<?php
declare(strict_types=1);

/**
 * Плагины: /plugins/<имя>/plugin.json + plugin.php.
 *
 * plugin.json — метаданные (name, description, version, author) и
 *               необязательная схема настроек (settings).
 * plugin.php  — исполняется при загрузке, вешает слушателей через Hooks
 *               и может регистрировать свои маршруты (route()).
 * Включённые плагины — массив имён каталогов в config.json → "plugins".
 *
 * Значения настроек лежат отдельно от кода плагина — в guard-файле
 * /system/plugin-data.php (как categories.php), чтобы переустановка или
 * обновление плагина их не затирали.
 *
 * Плагин — исполняемый PHP: устанавливать только из доверенных источников
 * (то же правило, что и для тем).
 */
class PluginManager
{
    private static string $dir;

    /** Поддерживаемые типы полей настроек (см. plugin.json → settings) */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'checkbox', 'select'];

    /** @var array<string, callable> маршруты плагинов: путь без слэшей => обработчик */
    private static array $routes = [];

    /** @var array<string, array>|null кэш значений настроек на время запроса */
    private static ?array $data = null;

    private static function dir(): string
    {
        return self::$dir ??= (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/plugins/';
    }

    /** Путь к guard-файлу со значениями настроек (без расширения) */
    private static function dataPath(): string
    {
        return (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/system/plugin-data';
    }

    /** Все установленные плагины: [имя-каталога => метаданные] */
    public static function all(): array
    {
        $plugins = [];
        foreach (glob(self::dir() . '*/plugin.json') ?: [] as $file) {
            $meta = json_decode((string)@file_get_contents($file), true);
            if (!is_array($meta)) continue;
            $dirName = basename(dirname($file));
            $plugins[$dirName] = [
                'dir'         => $dirName,
                'name'        => (string)($meta['name'] ?? $dirName),
                'description' => (string)($meta['description'] ?? ''),
                'version'     => (string)($meta['version'] ?? ''),
                'author'      => (string)($meta['author'] ?? ''),
                'settings'    => self::normalizeSchema($meta['settings'] ?? null),
            ];
        }
        ksort($plugins);
        return $plugins;
    }

    /** Имена включённых плагинов из конфига (только существующие) */
    public static function enabled(array $config): array
    {
        $list = array_filter((array)($config['plugins'] ?? []), 'is_string');
        return array_values(array_intersect($list, array_keys(self::all())));
    }

    /** Подключить включённые плагины (вызывается один раз при старте) */
    public static function loadEnabled(array $config): void
    {
        foreach (self::enabled($config) as $name) {
            // Имя каталога уже сверено со сканом all(), но перестрахуемся
            if (!preg_match('/^[a-z0-9_-]+$/i', $name)) continue;
            $file = self::dir() . $name . '/plugin.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    // ----------------------------------------------------------------
    // Настройки плагина (схема в plugin.json, значения в plugin-data.php)
    // ----------------------------------------------------------------

    /**
     * Привести схему настроек из plugin.json к предсказуемому виду.
     * Поля с неизвестным типом или без ключа отбрасываются: плагин ставится
     * из ZIP, и кривой manifest не должен ломать страницу «Плагины».
     *
     * @return array<int, array{key:string,label:string,type:string,default:mixed,options:array,hint:string}>
     */
    private static function normalizeSchema(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $field) {
            if (!is_array($field)) continue;
            $key  = (string)($field['key'] ?? '');
            $type = (string)($field['type'] ?? 'text');
            if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $key)) continue;
            if (!in_array($type, self::FIELD_TYPES, true)) continue;

            $options = [];
            if ($type === 'select') {
                foreach ((array)($field['options'] ?? []) as $ok => $ov) {
                    // Допускаем и список ["a","b"], и словарь {"a":"Подпись"}
                    $options[(string)(is_int($ok) ? $ov : $ok)] = (string)$ov;
                }
                if ($options === []) continue;   // select без вариантов бессмыслен
            }

            $out[] = [
                'key'     => $key,
                'label'   => (string)($field['label'] ?? $key),
                'type'    => $type,
                'default' => $field['default'] ?? ($type === 'checkbox' ? false : ''),
                'options' => $options,
                'hint'    => (string)($field['hint'] ?? ''),
            ];
        }
        return $out;
    }

    /** Схема настроек плагина (пустой массив — настроек нет) */
    public static function schema(string $plugin): array
    {
        return self::all()[$plugin]['settings'] ?? [];
    }

    /** Есть ли у плагина настраиваемые поля */
    public static function hasSettings(string $plugin): bool
    {
        return self::schema($plugin) !== [];
    }

    /** Все сохранённые значения: [плагин => [ключ => значение]] */
    private static function data(): array
    {
        if (self::$data === null) {
            [$data] = DataFile::readWithLegacy(self::dataPath());
            self::$data = is_array($data) ? $data : [];
        }
        return self::$data;
    }

    /**
     * Значения настроек плагина: сохранённые поверх значений по умолчанию.
     * Ключи, которых нет в схеме, не возвращаются — схема единственный источник
     * правды (плагин мог убрать поле в новой версии).
     */
    public static function settings(string $plugin): array
    {
        $saved = (array)(self::data()[$plugin] ?? []);
        $out   = [];
        foreach (self::schema($plugin) as $field) {
            $out[$field['key']] = array_key_exists($field['key'], $saved)
                ? $saved[$field['key']]
                : $field['default'];
        }
        return $out;
    }

    /** Одна настройка плагина; $fallback — если поля нет в схеме вовсе */
    public static function setting(string $plugin, string $key, mixed $fallback = null): mixed
    {
        $all = self::settings($plugin);
        return array_key_exists($key, $all) ? $all[$key] : $fallback;
    }

    /**
     * Сохранить настройки плагина. Принимает сырой ввод формы, приводит типы
     * по схеме и пишет только известные поля — чужие ключи в файл не попадают.
     */
    public static function saveSettings(string $plugin, array $input): bool
    {
        $schema = self::schema($plugin);
        if ($schema === []) return false;

        $clean = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            switch ($field['type']) {
                case 'checkbox':
                    $clean[$key] = !empty($input[$key]);
                    break;
                case 'number':
                    $clean[$key] = (int)($input[$key] ?? 0);
                    break;
                case 'select':
                    $val = (string)($input[$key] ?? '');
                    $clean[$key] = isset($field['options'][$val]) ? $val : (string)$field['default'];
                    break;
                default:
                    $clean[$key] = trim((string)($input[$key] ?? ''));
            }
        }

        $ok = DataFile::update(self::dataPath(), function (array $data) use ($plugin, $clean): array {
            $data[$plugin] = $clean;
            return $data;
        });
        if ($ok) self::$data = null;   // перечитать при следующем обращении
        return $ok;
    }

    /** Забыть настройки плагина (вызывается при его удалении) */
    public static function forget(string $plugin): void
    {
        DataFile::update(self::dataPath(), function (array $data) use ($plugin): array {
            unset($data[$plugin]);
            return $data;
        });
        self::$data = null;
    }

    // ----------------------------------------------------------------
    // Маршруты плагинов
    // ----------------------------------------------------------------

    /**
     * Зарегистрировать свой URL. Вызывается из plugin.php:
     *   PluginManager::route('hello', function (array $segments) { ... });
     * Обработчик сам печатает ответ; Router отдаёт ему управление ДО 404,
     * поэтому перебить существующий маршрут ядра плагин не может.
     */
    public static function route(string $path, callable $handler): void
    {
        self::$routes[trim($path, '/')] = $handler;
    }

    /**
     * Отдать запрос плагину, если он зарегистрировал такой маршрут.
     * @return bool true — ответ напечатан плагином, дальше идти не нужно
     */
    public static function dispatch(array $segments): bool
    {
        $path = implode('/', $segments);
        $fn   = self::$routes[$path] ?? null;
        if ($fn === null) return false;
        $fn($segments);
        return true;
    }

    /** Есть ли зарегистрированные маршруты (для тестов и отладки) */
    public static function routes(): array
    {
        return array_keys(self::$routes);
    }
}
