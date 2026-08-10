<?php
declare(strict_types=1);

/**
 * Раздел «Бэкапы»: создание, скачивание, удаление архивов.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Бэкапы (только Admin)
// ----------------------------------------------------------------

if ($action === 'backups') {
    requireRole(['admin'], $user);

    $backups = new BackupManager($config);

    if ($sub === 'create' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $result = $backups->create();
        adminRedirect($adminBase . 'backups/?' . (isset($result['error'])
            ? 'backup_err=' . urlencode(t($result['error']))
            : 'backup_ok=1'));
    }

    if ($sub === 'download') {
        $path = $backups->path((string)($_GET['file'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            exit('Backup not found.');
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $backups->delete((string)($_POST['file'] ?? ''));
        adminRedirect($adminBase . 'backups/?backup_deleted=1');
    }

    adminRender('backups', $common + [
        'title'         => t('Бэкапы'),
        'backupsList'   => $backups->all(),
        'backupsDir'    => $backups->dir(),
        'backupsSafe'   => $backups->isOutsideWebRoot(),
        'backupOk'      => isset($_GET['backup_ok']),
        'backupDeleted' => isset($_GET['backup_deleted']),
        'backupErr'     => (string)($_GET['backup_err'] ?? ''),
    ]);
    exit;
}
