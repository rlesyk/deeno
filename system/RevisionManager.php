<?php
declare(strict_types=1);

/**
 * История версий материалов: снимок старого файла перед перезаписью.
 *
 * Хранилище — /content/revisions/<posts|pages>/<ключ>/<Ymd-His-NNN>-<автор>.md,
 * где ключ = имя файла материала без расширения. Для постов оно не меняется
 * никогда, для страниц равно slug и переезжает вместе с переименованием
 * (см. rename()). Файл ревизии — точная копия .md вместе с YAML-шапкой,
 * поэтому восстановление сводится к обратному копированию.
 *
 * Не git: на shared-хостинге его нет. Глубина хранения — `revisions_keep`
 * из конфига (0 выключает историю целиком).
 */
class RevisionManager
{
    public const DEFAULT_KEEP = 10;
    public const MAX_KEEP     = 100;

    private string $dir;
    private int    $keep;

    public function __construct(array $config = [], ?string $root = null)
    {
        $root       = $root ?? (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__));
        $this->dir  = rtrim($root, '/') . '/content/revisions/';
        $this->keep = self::normalizeKeep($config['revisions_keep'] ?? self::DEFAULT_KEEP);
    }

    /** Сколько версий хранить: 0 — история выключена */
    public static function normalizeKeep(mixed $value): int
    {
        $keep = is_numeric($value) ? (int)$value : self::DEFAULT_KEEP;
        return max(0, min(self::MAX_KEEP, $keep));
    }

    public function enabled(): bool
    {
        return $this->keep > 0;
    }

    public function keep(): int
    {
        return $this->keep;
    }

    /**
     * Снять копию текущего файла материала перед его перезаписью.
     * Возвращает id ревизии или null (история выключена, файла нет, нет прав).
     */
    public function snapshot(string $type, string $filename, string $author = ''): ?string
    {
        if (!$this->enabled()) return null;

        $key = $this->key($filename);
        $dir = $this->dirFor($type, $key);
        if ($dir === null) return null;

        $source = $this->sourcePath($type, $filename);
        if ($source === null || !is_file($source)) return null;

        $content = @file_get_contents($source);
        if ($content === false || $content === '') return null;

        // Ничего не менялось — второй одинаковый снимок не нужен
        $last = $this->latest($type, $key);
        if ($last !== null && $this->read($type, $key, $last) === $content) {
            return null;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $id   = $this->makeId($dir, $author);
        $path = $dir . $id . '.md';
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            return null;
        }

        $this->prune($type, $key);
        return $id;
    }

    /**
     * Список версий, свежие первыми:
     * [['id' => …, 'time' => ts, 'author' => …, 'size' => bytes], …]
     */
    public function all(string $type, string $filename): array
    {
        $key = $this->key($filename);
        $dir = $this->dirFor($type, $key);
        if ($dir === null || !is_dir($dir)) return [];

        $items = [];
        foreach (glob($dir . '*.md') ?: [] as $path) {
            $id = basename($path, '.md');
            if (!self::isRevisionId($id)) continue;
            $items[] = [
                'id'     => $id,
                'time'   => $this->idToTime($id),
                'author' => $this->idToAuthor($id),
                'size'   => (int)@filesize($path),
            ];
        }

        // Сортировка по имени = по времени: формат id лексикографически монотонен
        usort($items, fn(array $a, array $b) => strcmp($b['id'], $a['id']));
        return $items;
    }

    /** Содержимое версии (сырой .md) или null */
    public function read(string $type, string $filename, string $id): ?string
    {
        $path = $this->pathFor($type, $filename, $id);
        if ($path === null || !is_file($path)) return null;
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    /** Разобранная версия: ['meta' => …, 'body' => …] или null */
    public function parsed(string $type, string $filename, string $id): ?array
    {
        $content = $this->read($type, $filename, $id);
        return $content === null ? null : FrontmatterParser::parse($content);
    }

    /**
     * Перенести историю под новое имя файла (страница сменила slug).
     * Если у нового имени история уже есть — оставляем её, старую удаляем.
     */
    public function rename(string $type, string $oldFilename, string $newFilename): bool
    {
        $from = $this->dirFor($type, $this->key($oldFilename));
        $to   = $this->dirFor($type, $this->key($newFilename));
        if ($from === null || $to === null || $from === $to || !is_dir($from)) return false;

        if (is_dir($to)) {
            $this->removeDir($from);
            return false;
        }
        return @rename($from, $to);
    }

    /** Удалить всю историю материала (материал удалён) */
    public function forget(string $type, string $filename): bool
    {
        $dir = $this->dirFor($type, $this->key($filename));
        return $dir !== null && is_dir($dir) && $this->removeDir($dir);
    }

    /** Сколько места занимает история, байт */
    public function size(): int
    {
        if (!is_dir($this->dir)) return 0;
        $total = 0;
        foreach (['posts', 'pages'] as $type) {
            foreach (glob($this->dir . $type . '/*/*.md') ?: [] as $path) {
                $total += (int)@filesize($path);
            }
        }
        return $total;
    }

    // ----------------------------------------------------------------

    /** Оставить только последние keep версий */
    private function prune(string $type, string $filename): void
    {
        $items = $this->all($type, $filename);
        if (count($items) <= $this->keep) return;

        $dir = $this->dirFor($type, $this->key($filename));
        if ($dir === null) return;

        foreach (array_slice($items, $this->keep) as $item) {
            @unlink($dir . $item['id'] . '.md');
        }
    }

    private function latest(string $type, string $filename): ?string
    {
        $items = $this->all($type, $filename);
        return $items === [] ? null : $items[0]['id'];
    }

    /**
     * Уникальный id: Ymd-His-NNN[-автор]. Счётчик занимает фиксированные три
     * знака сразу после времени, поэтому сортировка по имени совпадает с
     * хронологией даже когда два снимка попали в одну секунду.
     */
    private function makeId(string $dir, string $author): string
    {
        $stamp = date('Ymd-His');
        $slug  = $this->authorSlug($author);
        $tail  = $slug !== '' ? '-' . $slug : '';
        for ($n = 0; $n < 1000; $n++) {
            $id = $stamp . '-' . sprintf('%03d', $n) . $tail;
            if (!is_file($dir . $id . '.md')) return $id;
        }
        return $stamp . '-999' . $tail;
    }

    private function authorSlug(string $author): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '', $author) ?? '';
        return mb_substr($slug, 0, 32);
    }

    private function idToTime(string $id): int
    {
        if (!preg_match('/^(\d{8})-(\d{6})/', $id, $m)) return 0;
        $ts = strtotime($m[1] . 'T' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2));
        return $ts === false ? 0 : $ts;
    }

    private function idToAuthor(string $id): string
    {
        // Ymd-His-NNN-автор → всё после счётчика
        if (!preg_match('/^\d{8}-\d{6}-\d{3}-(.+)$/', $id, $m)) return '';
        return $m[1];
    }

    /** Имя файла материала без .md — оно же имя каталога истории */
    private function key(string $filename): string
    {
        return basename(trim($filename), '.md');
    }

    private static function isRevisionId(string $id): bool
    {
        return (bool)preg_match('/^\d{8}-\d{6}-\d{3}(?:-[a-zA-Z0-9_-]{1,32})?$/', $id);
    }

    private static function isKey(string $key): bool
    {
        return $key !== '' && !str_contains($key, '..') && (bool)preg_match('/^[\w\-.]+$/u', $key);
    }

    private function typeDir(string $type): ?string
    {
        return match ($type) {
            'page', 'pages' => 'pages',
            'post', 'posts' => 'posts',
            default         => null,
        };
    }

    /** Каталог истории материала или null, если тип/ключ не проходят проверку */
    private function dirFor(string $type, string $key): ?string
    {
        $typeDir = $this->typeDir($type);
        if ($typeDir === null || !self::isKey($key)) return null;
        return $this->dir . $typeDir . '/' . $key . '/';
    }

    private function pathFor(string $type, string $filename, string $id): ?string
    {
        $dir = $this->dirFor($type, $this->key($filename));
        if ($dir === null || !self::isRevisionId($id)) return null;
        return $dir . $id . '.md';
    }

    /** Путь к живому файлу материала */
    private function sourcePath(string $type, string $filename): ?string
    {
        $typeDir = $this->typeDir($type);
        $key     = $this->key($filename);
        if ($typeDir === null || !self::isKey($key)) return null;
        $root = dirname(rtrim($this->dir, '/'));   // …/content
        return $root . '/' . $typeDir . '/' . $key . '.md';
    }

    private function removeDir(string $dir): bool
    {
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $path) {
            if (is_file($path)) @unlink($path);
        }
        return @rmdir($dir);
    }
}
