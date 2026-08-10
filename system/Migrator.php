<?php
declare(strict_types=1);

/**
 * Миграции данных между версиями deeno.
 *
 * Код обновляется заменой файлов по FTP — а данные (config.php, папки, флаги)
 * остаются от прежней версии. Мигратор доводит их до состояния, которое ждёт
 * новый код: при первом входе в админку после обновления выполняются шаги для
 * всех версий между `current_build` в конфиге и текущей `DEENO_VERSION`.
 *
 * Правила для шагов:
 *  - идемпотентность: повторный запуск не должен ничего ломать (шаг может
 *    выполниться дважды, если запись конфига не удалась);
 *  - никаких разрушительных действий: только добавляем и восстанавливаем;
 *  - шаг получает конфиг и возвращает его же (возможно, изменённый).
 */
class Migrator
{
    /**
     * Шаги по версиям. Ключ — версия, в которой изменилось поведение;
     * выполняются по возрастанию, только те, что новее current_build.
     *
     * @return array<string, callable(array):array>
     */
    private static function steps(): array
    {
        return [
            // 1.1.0: RSS и соцсети переехали из ядра в плагины, а защитные
            // .htaccess начали поставляться с дистрибутивом.
            '1.1.0' => static function (array $c): array {
                $plugins = array_values(array_filter((array)($c['plugins'] ?? []), 'is_string'));

                // Лента была включена настройкой rss_enabled (по умолчанию — да),
                // теперь её даёт плагин. Без этого шага RSS молча пропал бы.
                $rssWasOn = !array_key_exists('rss_enabled', $c) || !empty($c['rss_enabled']);
                if ($rssWasOn && !in_array('rss', $plugins, true) && is_dir(self::root() . 'plugins/rss')) {
                    $plugins[] = 'rss';
                }

                // Ссылки на соцсети жили в config['social']; плагин их подхватит,
                // но сам должен быть включён — иначе иконки исчезнут из подвала.
                $hasSocial = false;
                foreach ((array)($c['social'] ?? []) as $url) {
                    if (is_string($url) && trim($url) !== '') { $hasSocial = true; break; }
                }
                if ($hasSocial && !in_array('social-links', $plugins, true)
                    && is_dir(self::root() . 'plugins/social-links')) {
                    $plugins[] = 'social-links';
                }

                $c['plugins'] = $plugins;

                // Защита папок с данными: в 1.0.0 эти файлы не попадали в
                // дистрибутив, и на Apache каталоги оставались открытыми.
                self::restoreHtaccess();

                return $c;
            },
        ];
    }

    /** Корень установки со слэшем на конце */
    private static function root(): string
    {
        return (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/';
    }

    /**
     * Выполнить недостающие шаги. Возвращает список применённых версий
     * (пустой массив — обновлять было нечего).
     *
     * @return string[]
     */
    public static function run(array $config): array
    {
        $target = defined('DEENO_VERSION') ? DEENO_VERSION : '1.0.0';
        $from   = trim((string)($config['current_build'] ?? ''));

        // Установки до 1.1.0 про current_build не знали. Новые ставятся сразу
        // с актуальным значением (install.php), поэтому пусто = обновление.
        if ($from === '') {
            $from = '1.0.0';
        }
        if (version_compare($from, $target, '>=')) {
            return [];
        }

        $done = [];
        foreach (self::steps() as $version => $step) {
            if (version_compare($version, $from, '<=')) continue;
            if (version_compare($version, $target, '>')) continue;

            // Пишем под блокировкой: параллельный запрос не должен затереть
            // конфиг промежуточным состоянием.
            $ok = DataFile::update(self::root() . 'config', static function (array $c) use ($step, $version): array {
                $c = $step($c);
                $c['current_build'] = $version;
                return $c;
            });
            if (!$ok) {
                return $done;   // нет прав на запись — попробуем в следующий раз
            }
            $done[] = $version;
        }

        // Отметку ставим всегда, даже если шагов для этой версии не было, —
        // иначе проверка повторялась бы на каждой странице админки.
        DataFile::update(self::root() . 'config', static function (array $c) use ($target): array {
            $c['current_build'] = $target;
            return $c;
        });

        return $done;
    }

    /**
     * Вернуть на место защитные .htaccess в папках с данными.
     * Тот же список, что и в install.php: там это делается при установке,
     * здесь — при обновлении со старой версии.
     */
    private static function restoreHtaccess(): void
    {
        $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order Allow,Deny\n  Deny from all\n</IfModule>\n";

        foreach (['backups', 'users', 'cache', 'content', 'system/logs'] as $dir) {
            $file = self::root() . $dir . '/.htaccess';
            if (is_dir(dirname($file)) && !is_file($file)) {
                @file_put_contents($file, $deny);
            }
        }
    }
}
