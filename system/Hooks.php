<?php
declare(strict_types=1);

/**
 * Система хуков — фундамент плагинов (см. PLUGINS.md).
 *
 * Два вида хуков:
 *  - события (run): «случилось X» — слушатели получают payload;
 *  - фильтры (filter): значение проходит по цепочке слушателей,
 *    каждый возвращает (возможно изменённое) значение дальше.
 *
 * События ядра: post.saved, post.deleted, media.uploaded.
 * Фильтры ядра:  post.content (HTML статьи), site.head, site.footer
 *                (строки, которые тема выводит в <head> и перед </body>),
 *                admin.head (в <head> админки), editor.toolbar (кнопки в
 *                панели форматирования редактора).
 *
 * У слушателя есть приоритет (меньше — раньше, по умолчанию 10).
 */
class Hooks
{
    /** @var array<string, array<int, array{fn: callable, priority: int, seq: int}>> */
    private static array $listeners = [];

    /** Счётчик регистраций: при равном приоритете порядок — как добавляли */
    private static int $seq = 0;

    /**
     * Подписаться на событие или фильтр.
     *
     * $priority — меньше значит раньше (по умолчанию 10, как в WordPress).
     * Плагины грузятся в алфавитном порядке каталогов, поэтому полагаться на
     * него нельзя: если слушателю важно отработать до/после чужого, он задаёт
     * приоритет явно. При равном приоритете сохраняется порядок регистрации.
     */
    public static function add(string $event, callable $fn, int $priority = 10): void
    {
        self::$listeners[$event][] = ['fn' => $fn, 'priority' => $priority, 'seq' => self::$seq++];
    }

    /**
     * Слушатели события, отсортированные по приоритету.
     * @return callable[]
     */
    private static function sorted(string $event): array
    {
        $items = self::$listeners[$event] ?? [];
        // usort нестабилен, поэтому вторым ключом идёт номер регистрации
        usort($items, fn(array $a, array $b) => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
        return array_column($items, 'fn');
    }

    /** Вызвать событие; payload передаётся каждому слушателю */
    public static function run(string $event, array $payload = []): void
    {
        foreach (self::sorted($event) as $fn) {
            $fn($payload);
        }
    }

    /**
     * Пропустить значение через цепочку фильтров.
     * Слушатель: fn($value, array $ctx) → новое значение.
     * Вернул null — значение остаётся прежним (защита от забытого return).
     */
    public static function filter(string $name, mixed $value, array $ctx = []): mixed
    {
        foreach (self::sorted($name) as $fn) {
            $result = $fn($value, $ctx);
            if ($result !== null) {
                $value = $result;
            }
        }
        return $value;
    }
}
