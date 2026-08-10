<?php
declare(strict_types=1);

/**
 * Раздел «Темы»: список, активация, установка ZIP, удаление.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Темы (только Admin): список, активация, установка ZIP, удаление
// ----------------------------------------------------------------

if ($action === 'themes') {
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        adminRender('404', $common + ['title' => t('Доступ запрещён')]);
        exit;
    }

    require_once dirname(__DIR__) . '/ThemeInstaller.php';
    $installer = new ThemeInstaller();

    if ($sub === 'activate' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name   = (string)($_POST['name'] ?? '');
        $themes = array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []);
        if (in_array($name, $themes, true)) {
            $config['theme'] = $name;
            DataFile::writeMigrating(ROOT_DIR . '/config', $config);
            adminRedirect($adminBase . 'themes/?activated=1');
        }
        adminRedirect($adminBase . 'themes/');
    }

    if ($sub === 'install' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $result = $installer->install($_FILES['theme_zip'] ?? []);
        adminRedirect($adminBase . 'themes/?' . (isset($result['error'])
            ? 'error=' . urlencode(t($result['error']))
            : 'installed=1'));
    }

    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        // Нельзя удалить активную тему и default (база наследования)
        if ($name !== ($config['theme'] ?? '') && $name !== ThemeManager::FALLBACK_THEME) {
            $installer->delete($name);
        }
        adminRedirect($adminBase . 'themes/?deleted=1');
    }

    adminRender('themes', $common + [
        'title'     => t('Темы'),
        'themes'    => $installer->all((string)($config['theme'] ?? 'default')),
        'activated' => isset($_GET['activated']),
        'installed' => isset($_GET['installed']),
        'deleted'   => isset($_GET['deleted']),
        'themesErr' => (string)($_GET['error'] ?? ''),
    ]);
    exit;
}
