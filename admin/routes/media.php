<?php
declare(strict_types=1);

/**
 * Раздел «Медиа»: галерея, загрузка (AJAX), удаление.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Медиа: галерея, загрузка (AJAX), удаление
// ----------------------------------------------------------------

if ($action === 'media') {
    $media = new MediaManager($config);

    // Загрузка — отвечаем JSON (для drag & drop из редактора и галереи)
    if ($sub === 'upload' && $isPost) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$security->verifyCsrf($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null))) {
            http_response_code(403);
            exit(json_encode(['error' => 'CSRF token mismatch.']));
        }
        $result = $media->upload($_FILES['file'] ?? []);
        if (isset($result['error'])) {
            http_response_code(422);
            $result['error'] = t($result['error']);
        }
        exit(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // Удаление (POST + CSRF). Медиатека общая и без владельца, поэтому
    // удаление файлов доступно только admin и editor (author загружает, но
    // не удаляет — чтобы не затронуть чужие ассеты).
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        requireRole(['admin', 'editor'], $user);
        $ok = $media->delete((string)($_POST['url'] ?? ''));
        adminRedirect($adminBase . 'media/?' . ($ok ? 'deleted=1' : 'error=1'));
    }

    adminRender('media', $common + [
        'title'   => t('Медиа'),
        'items'   => $media->all(),
        'deleted' => isset($_GET['deleted']),
        'error'   => isset($_GET['error']),
    ]);
    exit;
}
