<?php
declare(strict_types=1);

/**
 * Раздел «Настройки»: параметры сайта и личные настройки панели.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Настройки сайта (только Admin)
// ----------------------------------------------------------------

if ($action === 'settings') {
    // Раздел открыт всем ролям, но не-админ видит только личные настройки
    // панели (тема и язык) — они переехали сюда из сайдбара. Всё, что меняет
    // сайт, по-прежнему доступно исключительно администратору. В демо-режиме
    // даже админ приравнивается к обычной роли: настройки сайта скрыты и на
    // сервере не принимаются, личная карточка «Панель управления» остаётся.
    $isSiteAdmin = ($user['role'] ?? '') === 'admin' && !isDemoMode();
    $settingsErr = '';

    if ($isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }

        // Личные настройки панели: пишем в профиль пользователя, чтобы выбор
        // пережил выход (раньше язык жил только в сессии и сбрасывался)
        $me = $users->find((string)($user['username'] ?? ''));
        if ($me !== null) {
            $lang = (string)($_POST['admin_lang'] ?? '');
            if (in_array($lang, ['ru', 'en'], true)) {
                $me['language'] = $lang;
                $_SESSION['admin_lang'] = $lang;
            }
            $uiTheme = (string)($_POST['admin_theme'] ?? '');
            if (in_array($uiTheme, ['light', 'dark'], true)) {
                $me['admin_theme'] = $uiTheme;
            }
            $users->save($me);
        }

        if (!$isSiteAdmin) {
            adminRedirect($adminBase . 'settings/?saved=1');
        }

        $themes = array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []);

        // Пресеты формата даты — все языконезависимые (числовые)
        $dateFormats = ['d.m.Y', 'd.m.Y H:i', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];

        // Главная: '' — лента постов; иначе slug существующей страницы
        $pageSlugs = array_map(fn($p) => $p->slug, $cms->pages());

        $new    = [
            'site_title'          => trim((string)($_POST['site_title'] ?? '')),
            'site_tagline'        => trim((string)($_POST['site_tagline'] ?? '')),
            'site_description'    => trim((string)($_POST['site_description'] ?? '')),
            'footer_text'         => trim((string)($_POST['footer_text'] ?? '')),
            'site_url'            => rtrim(trim((string)($_POST['site_url'] ?? '')), '/'),
            'language'            => in_array($_POST['language'] ?? '', ['ru', 'en'], true) ? (string)$_POST['language'] : 'ru',
            'timezone'            => trim((string)($_POST['timezone'] ?? 'Europe/Moscow')),
            'date_format'         => in_array($_POST['date_format'] ?? '', $dateFormats, true) ? (string)$_POST['date_format'] : 'd.m.Y',
            'homepage'            => in_array($_POST['homepage'] ?? '', $pageSlugs, true) ? (string)$_POST['homepage'] : '',
            'theme'               => in_array($_POST['theme'] ?? '', $themes, true) ? (string)$_POST['theme'] : 'default',
            'posts_per_page'      => max(1, min(100, (int)($_POST['posts_per_page'] ?? 10))),
            'order_by'            => ($_POST['order_by'] ?? '') === 'position' ? 'position' : 'date',
            'category_order'      => in_array($_POST['category_order'] ?? '', ['manual', 'alpha', 'created', 'modified'], true) ? (string)$_POST['category_order'] : 'alpha',
            'article_order'       => in_array($_POST['article_order'] ?? '', ['manual', 'alpha', 'created', 'modified'], true) ? (string)$_POST['article_order'] : 'manual',
            'revisions_keep'      => RevisionManager::normalizeKeep($_POST['revisions_keep'] ?? RevisionManager::DEFAULT_KEEP),
            'maintenance_mode'    => !empty($_POST['maintenance_mode']),
            'maintenance_message' => trim((string)($_POST['maintenance_message'] ?? '')),
            'cache_enabled'       => !empty($_POST['cache_enabled']),
            'debug'               => !empty($_POST['debug']),
            'sitemap_enabled'     => !empty($_POST['sitemap_enabled']),
            'logo'                => trim((string)($_POST['logo'] ?? '')),
            'favicon'             => trim((string)($_POST['favicon'] ?? '')),
            'og_image'            => trim((string)($_POST['og_image'] ?? '')),
            'media_max_width'     => max(320, min(10000, (int)($_POST['media_max_width'] ?? 2560))),
            'media_quality'       => max(40, min(100, (int)($_POST['media_quality'] ?? 82))),
            // Домены внешних скриптов (счётчики) для CSP. Нормализуем сразу:
            // из введённого остаются только схема+хост, по одному в строке —
            // так пользователь видит, что реально попало в политику.
            'external_scripts'    => implode("\n", Csp::allowedHosts([
                'external_scripts' => (string)($_POST['external_scripts'] ?? ''),
            ])),
        ];

        if ($new['site_title'] === '') {
            $settingsErr = t('Название сайта не может быть пустым.');
        } elseif (!in_array($new['timezone'], timezone_identifiers_list(), true)) {
            $settingsErr = t('Неизвестная временная зона.');
        } else {
            // Сливаем с текущим конфигом: неизвестные ключи (smtp_* и др.) сохраняются
            $merged = array_merge($config, $new);
            if (!DataFile::writeMigrating(ROOT_DIR . '/config', $merged)) {
                $settingsErr = t('Не удалось записать config.json (права на запись?).');
            } else {
                adminRedirect($adminBase . 'settings/?saved=1');
            }
        }
        $config = array_merge($config, $new);
    }

    adminRender('settings', $common + [
        'title'         => t('Настройки'),
        'config'        => $config,
        'themes'        => $isSiteAdmin ? array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []) : [],
        'pagesList'     => $isSiteAdmin ? $cms->pages() : [],
        'mediaList'     => $isSiteAdmin ? (new MediaManager())->all() : [],
        'saved'         => isset($_GET['saved']),
        'settingsErr'   => $settingsErr,
        'isSiteAdmin'   => $isSiteAdmin,
    ]);
    exit;
}
