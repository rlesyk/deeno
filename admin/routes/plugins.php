<?php
declare(strict_types=1);

/**
 * Раздел «Плагины»: список, включение, настройки, установка, удаление.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Плагины (только Admin)
// ----------------------------------------------------------------

if ($action === 'plugins') {
    requireRole(['admin'], $user);
    require_once dirname(__DIR__) . '/PluginInstaller.php';
    $pluginInstaller = new PluginInstaller();

    // Вкл/выкл (POST + CSRF). Запись в config.json сама сбросит кэш страниц.
    if ($sub === 'toggle' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        if (isset(PluginManager::all()[$name])) {
            $enabled = PluginManager::enabled($config);
            $enabled = in_array($name, $enabled, true)
                ? array_values(array_diff($enabled, [$name]))
                : array_merge($enabled, [$name]);
            $config['plugins'] = $enabled;
            DataFile::writeMigrating(ROOT_DIR . '/config', $config);
        }
        adminRedirect($adminBase . 'plugins/?saved=1');
    }

    // Установка из ZIP (POST + CSRF)
    if ($sub === 'install' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $result = $pluginInstaller->install($_FILES['plugin_zip'] ?? []);
        adminRedirect($adminBase . 'plugins/?' . (isset($result['error'])
            ? 'error=' . urlencode(t($result['error']))
            : 'installed=1'));
    }

    // Настройки плагина (POST + CSRF). Схема — в plugin.json, значения
    // хранятся отдельно от кода плагина (system/plugin-data.php).
    if ($sub === 'settings' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        $err  = '';
        if (!PluginManager::hasSettings($name)) {
            $err = t('У этого плагина нет настроек.');
        } elseif (!PluginManager::saveSettings($name, $_POST)) {
            $err = t('Не удалось сохранить настройки плагина (права на /system/?).');
        }
        adminRedirect($adminBase . 'plugins/?' . ($err !== '' ? 'error=' . urlencode($err) : 'saved=1'));
    }

    // Удаление (POST + CSRF)
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        $pluginInstaller->delete($name);
        // Отключаем удалённый плагин, если он был включён
        if (in_array($name, PluginManager::enabled($config), true)) {
            $config['plugins'] = array_values(array_diff(PluginManager::enabled($config), [$name]));
            DataFile::writeMigrating(ROOT_DIR . '/config', $config);
        }
        // Сохранённые настройки удалённого плагина больше не нужны
        PluginManager::forget($name);
        adminRedirect($adminBase . 'plugins/?deleted=1');
    }

    // Значения настроек для форм в модалках
    $allPlugins     = PluginManager::all();
    $pluginSettings = [];
    foreach ($allPlugins as $dir => $meta) {
        if (!empty($meta['settings'])) {
            $pluginSettings[$dir] = PluginManager::settings($dir);
        }
    }

    adminRender('plugins', $common + [
        'title'          => t('Плагины'),
        'plugins'        => $allPlugins,
        'enabled'        => PluginManager::enabled($config),
        'pluginSettings' => $pluginSettings,
        'saved'          => isset($_GET['saved']),
        'installed'      => isset($_GET['installed']),
        'deleted'        => isset($_GET['deleted']),
        'pluginsErr'     => (string)($_GET['error'] ?? ''),
    ]);
    exit;
}
