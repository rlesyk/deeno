<?php
declare(strict_types=1);

/**
 * ZIP-бэкапы сайта: content/ + media/ + users/ + themes/ + config.php + данные system/.
 * Не включаются: cache/ (пересобирается сам), backups/ (рекурсия), код CMS в system/.
 */
class BackupManager
{
    private const INCLUDE_DIRS  = ['content', 'media', 'users', 'themes'];
    private const INCLUDE_FILES = [
        'config.php', 'config.json',
        'system/categories.php',  // метаданные категорий (Название/Описание)
        'system/redirects.json',  // 301-редиректы при смене slug/категории
        'system/plugin-data.php', // сохранённые настройки плагинов
    ];

    private string $root;
    private string $backupsDir;
    /** Старое место внутри сайта: оттуда читаем, но новые архивы туда не пишем */
    private string $legacyDir;

    public function __construct(array $config = [])
    {
        $this->root       = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/';
        $this->legacyDir  = $this->root . 'backups/';
        $this->backupsDir = $this->resolveDir($config);
    }

    /** Где сейчас лежат архивы (показывается в админке) */
    public function dir(): string
    {
        return $this->backupsDir;
    }

    /** Хранилище вне сайта? (в админке подсказываем, что так безопаснее) */
    public function isOutsideWebRoot(): bool
    {
        return rtrim($this->backupsDir, '/') !== rtrim($this->legacyDir, '/');
    }

    /**
     * Выбрать каталог для архивов.
     *
     * Архив с users/ и config.php не должен лежать в веб-корне: на хостингах,
     * где статику отдаёт nginx мимо Apache, deny-правила из .htaccess к .zip
     * не применяются, и файл скачивается по прямой ссылке. Поэтому по умолчанию
     * уходим выше docroot — в домашний каталог аккаунта, который веб не отдаёт.
     *
     * Порядок: явная настройка → домашний каталог → старое место (фоллбэк).
     * «На уровень выше» вслепую подниматься нельзя: у cPanel родитель сайта —
     * это public_html, то есть docroot основного домена.
     */
    private function resolveDir(array $config): string
    {
        foreach ($this->candidates($config) as $dir) {
            $dir = rtrim($dir, '/') . '/';
            // Кандидат внутри сайта — значит в вебе, не подходит
            if (str_starts_with($dir, $this->root)) continue;
            // Каталог НЕ создаём: конструктор вызывается на каждой странице
            // админки (виджет «Последний бэкап»), и пустая папка появлялась бы
            // в домашнем каталоге даже у тех, кто бэкапы ни разу не делал.
            // Достаточно убедиться, что запись возможна; создаст его create().
            if (is_dir($dir) ? is_writable($dir) : is_writable(dirname(rtrim($dir, '/')))) {
                return $dir;
            }
        }
        return $this->legacyDir;
    }

    /** @return string[] пути-кандидаты в порядке предпочтения */
    private function candidates(array $config): array
    {
        $list = [];

        $custom = trim((string)($config['backups_dir'] ?? ''));
        if ($custom !== '') {
            $list[] = $custom;
        }

        // Имя каталога привязано к сайту: на одном аккаунте может быть
        // несколько установок deeno, их архивы не должны смешиваться.
        $suffix = 'deeno-backups-' . substr(md5($this->root), 0, 8);

        $home = getenv('HOME') ?: (string)($_SERVER['HOME'] ?? '');
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            $home = is_array($info) ? (string)($info['dir'] ?? '') : '';
        }
        if ($home !== '') {
            $list[] = rtrim($home, '/') . '/' . $suffix;
        }

        // Запасной способ определить дом аккаунта, когда HOME недоступен
        // (частый случай под PHP-FPM): всё, что до /public_html/ или /www/.
        foreach (['/public_html/', '/www/', '/htdocs/'] as $marker) {
            $pos = strpos($this->root, $marker);
            if ($pos !== false) {
                $list[] = substr($this->root, 0, $pos) . '/' . $suffix;
                break;
            }
        }

        return $list;
    }

    /** Положить deny-правила рядом с архивами — вдруг каталог всё же в вебе */
    private function protect(string $dir): void
    {
        $file = $dir . '.htaccess';
        if (is_file($file)) return;
        @file_put_contents($file,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order Allow,Deny\n  Deny from all\n</IfModule>\n");
    }

    /**
     * Создать бэкап. Возвращает ['file' => имя] или ['error' => текст].
     */
    public function create(): array
    {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'Расширение PHP zip не установлено на сервере.'];
        }
        // Каталог создаётся здесь, а не в конструкторе: пустая папка не должна
        // появляться у тех, кто бэкапы не делает (см. resolveDir).
        if (!is_dir($this->backupsDir) && !@mkdir($this->backupsDir, 0700, true)) {
            return ['error' => 'Нет прав на запись в каталог бэкапов.'];
        }
        $this->protect($this->backupsDir);

        // Имя содержит случайный суффикс: на хостингах, где статику отдаёт nginx
        // мимо Apache, .htaccess в /backups/ не применяется, и архив с
        // предсказуемым именем (backup-ДАТА-ВРЕМЯ.zip) скачивается перебором.
        // 12 hex-символов делают адрес неугадываемым; deny-правила остаются
        // первой линией защиты, это — вторая.
        $name = 'backup-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(6)) . '.zip';
        $path = $this->backupsDir . $name;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['error' => 'Не удалось создать ZIP-файл.'];
        }

        foreach (self::INCLUDE_FILES as $file) {
            if (is_file($this->root . $file)) {
                $zip->addFile($this->root . $file, $file);
            }
        }

        foreach (self::INCLUDE_DIRS as $dir) {
            $this->addDir($zip, $this->root . $dir, $dir);
        }

        $zip->close();
        return is_file($path) ? ['file' => $name] : ['error' => 'ZIP не был записан.'];
    }

    /**
     * Список бэкапов: [name, size, mtime], новые сверху.
     * Смотрим и в старом каталоге сайта: после переезда хранилища архивы,
     * созданные до обновления, должны остаться видимыми и скачиваемыми.
     */
    public function all(): array
    {
        $items = [];
        $seen  = [];
        foreach ($this->searchDirs() as $dir) {
            foreach (glob($dir . 'backup-*.zip') ?: [] as $path) {
                $name = basename($path);
                if (isset($seen[$name])) continue;   // активный каталог приоритетнее
                $seen[$name] = true;
                $items[] = [
                    'name'  => $name,
                    'size'  => (int)@filesize($path),
                    'mtime' => (int)@filemtime($path),
                ];
            }
        }
        usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /** Абсолютный путь для скачивания (с валидацией имени) */
    public function path(string $name): ?string
    {
        // Старые архивы (без случайного суффикса) тоже должны скачиваться
        if (!preg_match('/^backup-[\d-]+(?:-[0-9a-f]{12})?\.zip$/', $name)) return null;
        foreach ($this->searchDirs() as $dir) {
            if (is_file($dir . $name)) return $dir . $name;
        }
        return null;
    }

    /** Каталоги, где могут лежать архивы: активный, затем старый внутри сайта */
    private function searchDirs(): array
    {
        $dirs = [$this->backupsDir];
        if ($this->isOutsideWebRoot()) $dirs[] = $this->legacyDir;
        return $dirs;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);
        return $path !== null && @unlink($path);
    }

    // ----------------------------------------------------------------

    private function addDir(ZipArchive $zip, string $dir, string $local): void
    {
        if (!is_dir($dir)) return;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $rel = $local . '/' . substr($item->getPathname(), strlen($dir) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($item->getPathname(), $rel);
            }
        }
    }
}
