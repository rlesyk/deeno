<?php
declare(strict_types=1);

/**
 * Общие хелперы админки: локализация, экранирование, рендер вида,
 * подписи статусов, страница ошибки и проверки прав.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

/**
 * Локализация интерфейса. Порядок: личный выбор в сессии → для вошедшего язык
 * сайта → для ГОСТЯ подсказка браузера (Accept-Language).
 *
 * Гость — это экраны входа и восстановления пароля: там ещё некому было выбрать
 * язык, а настройка сайта к посетителю отношения не имеет. Раньше форма входа
 * всегда шла на языке сайта, и англоязычный админ видел русский экран.
 */
function detectAdminLang(array $config): string
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    foreach (explode(',', $accept) as $part) {
        $code = substr(trim(explode(';', $part)[0]), 0, 2);
        if (in_array($code, ['ru', 'en'], true)) {
            return $code;
        }
    }
    return (string)($config['language'] ?? 'en');
}
function t(string $s): string
{
    return $GLOBALS['ffcLang']->get($s);
}
// ----------------------------------------------------------------
// Хелперы
// ----------------------------------------------------------------

// Тип never — PHP 8.1+, а мы обещаем 8.0 (раздел 2 ТЗ)
function adminRedirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Рендер вида внутри layout админки */
function adminRender(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $contentView = __DIR__ . '/views/' . $view . '.php';
    require __DIR__ . '/views/layout.php';
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Подписи статусов материала для интерфейса. В value, CSS-классах и файлах
 * статус остаётся английским — переводится только видимый текст.
 *
 * @return array<string, string> статус => подпись, в порядке показа
 */
function statusLabels(bool $isPage = false): array
{
    return $isPage
        ? ['draft' => t('Черновик'), 'published' => t('Опубликована')]
        : [
            'published' => t('Опубликован'),
            'sticky'    => t('Закреплён'),
            'draft'     => t('Черновик'),
            'scheduled' => t('Отложен'),
            'unlisted'  => t('Вне списков'),
            'archived'  => t('В архиве'),
        ];
}

/** Подпись одного статуса; неизвестный показываем как есть. */
function statusLabel(string $status, bool $isPage = false): string
{
    return statusLabels($isPage)[$status] ?? statusLabels(false)[$status] ?? $status;
}

/**
 * Куда ведёт заголовок материала в списке.
 *
 * Опубликованное (published/sticky/unlisted) открывается на сайте в новой
 * вкладке; черновик и отложенная публикация публично отдают 404, поэтому ведут
 * сразу в редактор — в текущей вкладке.
 *
 * @param  string $kind  'posts' или 'pages' — раздел админки
 * @return array{href: string, blank: bool}
 */
function materialLink(Post $p, string $kind, string $adminBase, string $publicUrl): array
{
    if ($p->isPubliclyVisible()) {
        return ['href' => $publicUrl, 'blank' => true];
    }
    return [
        'href'  => $adminBase . $kind . '/edit/?file=' . urlencode(basename($p->filePath)),
        'blank' => false,
    ];
}

/** Эффективный лимит загрузки в байтах: минимум из upload_max_filesize, post_max_size и 10 МБ. */
function uploadLimitBytes(): int
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '') return 0;
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024; // fallthrough
            case 'm': $n *= 1024; // fallthrough
            case 'k': $n *= 1024;
        }
        return $n;
    };
    $limits = [10 * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = $toBytes((string)ini_get($k));
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}

/** Стилизованная standalone-страница ошибки (CSRF, 403 и т.п.) */
function adminErrorPage(int $code, string $title, string $message): void
{
    http_response_code($code);
    $base  = (string)($GLOBALS['ffcAdminBase'] ?? './');
    $site  = (string)($GLOBALS['ffcSiteTitle'] ?? 'deeno');
    $nonce = (string)($GLOBALS['ffcCspNonce'] ?? '');
    require __DIR__ . '/views/error.php';
    exit;
}

/** Единый ответ на неверный CSRF-токен */
function csrfFail(): void
{
    adminErrorPage(403, t('Сессия устарела'), t('Сессия устарела, попробуйте ещё раз.'));
}

/** Включён ли демо-режим (публичная песочница). Флаг только в конфиге. */
function isDemoMode(): bool
{
    return !empty($GLOBALS['ffcDemoMode']);
}

/**
 * Отказ в изменяющем действии под демо-режимом. Не пугающая 403-заглушка:
 * возвращаемся на тот же раздел с ?demo=1 — layout покажет тост «В демо-режиме
 * это действие отключено». Все запрещённые эндпоинты — обычные form-POST с
 * редиректом, поэтому JSON-ответ не нужен.
 */
function demoDenied(string $redirectTo = ''): void
{
    $base = (string)($GLOBALS['ffcAdminBase'] ?? './');
    adminRedirect(($redirectTo !== '' ? $redirectTo : $base) . '?demo=1');
}

/**
 * Проверка прав: роль пользователя должна
 * входить в список разрешённых, иначе 403. Вызывается в начале роутов.
 * Роли: admin — всё; editor — контент и медиа; author — свои посты и медиа.
 */
function requireRole(array $roles, ?array $user): void
{
    if (!in_array((string)($user['role'] ?? ''), $roles, true)) {
        adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
    }
}

/**
 * Author работает только со своими постами — проверка на КАЖДОМ изменяющем
 * маршруте, а не только при открытии редактора: форму можно не открывать,
 * а отправить POST с чужим именем файла напрямую.
 *
 * $allowNew — пустое имя файла означает создание нового материала (разрешено
 * при сохранении). При удалении пустое имя остаётся ошибкой, как и было.
 * Другие роли и страницы сюда не доходят: страницы закрыты requireRole выше.
 */
function requireOwnPost(string $file, ?array $user, string $type, ContentManager $cms, bool $allowNew = false): void
{
    if (($user['role'] ?? '') !== 'author' || $type !== 'post') return;
    if ($allowNew && $file === '') return;

    $post = $cms->postByFilename($file);
    if ($post === null || $post->author !== (string)($user['username'] ?? '')) {
        adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
    }
}
