<?php
declare(strict_types=1);

/**
 * Роутер админки.
 * Вызывается двумя путями: Apache напрямую (/admin/ — реальная папка)
 * или через главный Router (когда URL вида /admin/posts/ уходит в index.php).
 * Поэтому файл самодостаточен.
 */

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__));
    spl_autoload_register(function (string $class): void {
        $file = ROOT_DIR . '/system/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

define('FFC_ADMIN', true);

// Версия. Нужна явно: на Apache /admin/ — реальная папка, запрос сюда приходит
// в обход корневого index.php, и без этого «Система» на дашборде показывала «—»
require_once ROOT_DIR . '/system/version.php';

// Хелперы и иконки нужны и роутам, и вьюхам — подключаем до всего остального
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';

// Конфиг — guard-файл config.php; config.json читается как legacy (см. DataFile)
[$config] = DataFile::readWithLegacy(ROOT_DIR . '/config');
$config = is_array($config) ? $config : [];

// Нет пользователей или конфига → CMS не установлена → в мастер (zero-config).
// Путь строим от корня сайта: работает и при установке в подпапку
if (is_file(ROOT_DIR . '/install.php')
    && (empty($config) || count(glob(ROOT_DIR . '/users/*.{php,json}', GLOB_BRACE) ?: []) === 0)) {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    while (basename($dir) === 'admin') {
        $dir = dirname($dir);
    }
    header('Location: ' . rtrim($dir, '/') . '/install.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', empty($config['debug']) ? '0' : '1');
if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Плагины: слушатели post.saved / media.uploaded должны работать и в админке.
// Повторный вызов безопасен — require_once не подключит plugin.php дважды.
PluginManager::loadEnabled($config);

$security = new Security($config);
$security->startSession();

// Content-Security-Policy: инлайн-скрипты только с nonce
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; object-src 'none'; "
    . "base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$users = new UserManager();
$cache = new CacheManager($config);
$cms   = new ContentManager($config, $cache);


$currentUser = $security->currentUser();
$adminLang   = (string)($_SESSION['admin_lang'] ?? '');
$adminTheme  = 'light';
if ($currentUser !== null) {
    // Личные настройки панели живут в профиле (Настройки → «Панель управления»)
    $profile = (new UserManager())->find((string)($currentUser['username'] ?? ''));
    if ($adminLang === '' && in_array((string)($profile['language'] ?? ''), ['ru', 'en'], true)) {
        $adminLang = (string)$profile['language'];
    }
    if (in_array((string)($profile['admin_theme'] ?? ''), ['light', 'dark'], true)) {
        $adminTheme = (string)$profile['admin_theme'];
    }
}
if ($adminLang === '') {
    $adminLang = $currentUser !== null
        ? (string)($config['language'] ?? 'ru')
        : detectAdminLang($config);
}
$GLOBALS['ffcLang'] = new Lang($adminLang);

$adminPath = (string)($config['admin_path'] ?? 'admin');
$adminBase = '/' . $adminPath . '/';

// Контекст для adminErrorPage: при ЧПУ этот файл подключается внутри метода
// Router, поэтому «глобальные» переменные не видны через global — кладём явно
$GLOBALS['ffcAdminBase'] = $adminBase;
$GLOBALS['ffcCspNonce']  = $cspNonce;
$GLOBALS['ffcSiteTitle'] = (string)($config['site_title'] ?? 'deeno');
// Демо-режим (публичная песочница demo.deeno.tech): флаг только в конфиге, в UI
// его нет. При ЧПУ этот файл подключается внутри метода Router, поэтому top-level
// $config не виден функциям через global — кладём флаг явно, как ffcAdminBase.
$GLOBALS['ffcDemoMode'] = !empty($config['demo_mode']);

// ----------------------------------------------------------------
// Разбор URL: /admin/<action>/<sub>/
// ----------------------------------------------------------------
$uriPath  = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$segments = array_values(array_filter(explode('/', trim($uriPath, '/')), fn($s) => $s !== ''));
if (($segments[0] ?? '') === $adminPath) {
    array_shift($segments);
}
$action = $segments[0] ?? 'dashboard';
$sub    = $segments[1] ?? '';
if ($action === 'index.php' || $action === '') {
    $action = 'dashboard';
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$user   = $security->currentUser();

// ----------------------------------------------------------------
// Экраны до входа
// ----------------------------------------------------------------
require __DIR__ . '/routes/auth.php';


// Сюда доходят только авторизованные (страховка)
if ($user === null) {
    adminRedirect($adminBase);
}

// Миграции данных после обновления кода. Выполняются молча при первом входе;
// в конфиг пишется current_build, поэтому дальше проверка стоит один
// version_compare. Демо-режим не трогаем — там конфиг менять нельзя.
$migrated = [];
if (!isDemoMode()) {
    $migrated = Migrator::run($config);
    if ($migrated !== []) {
        // Дальше по запросу нужен уже обновлённый конфиг (например, список плагинов)
        [$fresh] = DataFile::readWithLegacy(ROOT_DIR . '/config');
        if (is_array($fresh)) $config = $fresh;
    }
}

// ----------------------------------------------------------------
// Демо-режим: единая точка отказа для изменяющих действий (сервер, не UI).
// Максимальная защита публичной песочницы от злоупотреблений:
//   • загрузка медиа ЗАПРЕЩЕНА (иначе демо = публичный файлхостинг для
//     чужих/незаконных файлов);
//   • правка категорий ЗАПРЕЩЕНА (их название видно на фронте);
//   • создание/правка постов и страниц РАЗРЕШЕНЫ, но принудительно уходят в
//     draft (см. ниже, обработчик save) — гость видит свою работу в редакторе
//     и предпросмотре, но на публичный фронт она не попадает;
//   • reorder и личная тема/язык — разрешены (публичного текста не несут).
// Запрещено также: удаление контента, всё в users/backups, темы/плагины,
// профиль. Настройки сайта закрываются через $isSiteAdmin ниже.
// ----------------------------------------------------------------
if (isDemoMode() && $isPost) {
    $demoBlocked = [
        // archive убрал бы демо-пост с публичной витрины — это не правка своего
        // черновика, а изменение того, что видят остальные гости
        'posts'      => ['delete', 'archive'],
        'pages'      => ['delete'],
        'categories' => ['create', 'save', 'delete'],
        'users'      => ['save', 'delete'],
        'profile'    => [''],   // POST на /profile/ без sub — смена пароля/email
        'themes'     => ['activate', 'install', 'delete'],
        'plugins'    => ['toggle', 'install', 'delete', 'settings'],
        'backups'    => ['create', 'delete'],
        'media'      => ['upload', 'delete'],
    ];
    if (isset($demoBlocked[$action]) && in_array($sub, $demoBlocked[$action], true)) {
        // Загрузка медиа — AJAX-эндпоинт, ждёт JSON (иначе редактор молча
        // «повиснет» на редиректе). Остальные — обычные form-POST с редиректом.
        if ($action === 'media' && $sub === 'upload') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            exit(json_encode(['error' => t('В демо-режиме загрузка отключена.')]));
        }
        demoDenied($adminBase . $action . '/');
    }
}

// Маршрут /admin/lang/ убран (2026-07-20): язык выбирается в Настройках →
// «Панель управления» и хранится в профиле пользователя, а не только в сессии.

// ----------------------------------------------------------------
// Общие данные для layout
// ----------------------------------------------------------------
$siteTitle = (string)($config['site_title'] ?? 'deeno');
$siteUrl   = rtrim((string)($config['site_url'] ?? ''), '/');
$common    = [
    'user'      => $user,
    'adminBase' => $adminBase,
    'siteTitle'   => $siteTitle,
    'siteUrl'     => $siteUrl,
    'siteLogo'    => (string)($config['logo'] ?? ''),
    'siteFavicon' => (string)($config['favicon'] ?? ''),
    'security'  => $security,
    'action'    => $action,
    'cspNonce'  => $cspNonce,
    'adminLang' => $adminLang,
    'adminTheme' => $adminTheme,
    'demoMode'  => isDemoMode(),
    'migrated'  => $migrated,
];

// Джамп-бар (⌘K): контент + быстрые действия с учётом роли; счётчики для сайдбара.
// Метаданные приходят из кэшированного индекса — полные тела не читаются.
$role      = (string)($user['role'] ?? 'author');
$jumpPosts = $cms->posts(0, ['status' => 'all']);
if ($role === 'author') {
    $me = (string)($user['username'] ?? '');
    $jumpPosts = array_values(array_filter($jumpPosts, fn($p) => $p->author === $me));
}
$jumpPages = $role === 'author' ? [] : $cms->pages();

$categoriesCount = 0;
if ($role !== 'author') {
    require_once __DIR__ . '/CategoryController.php';
    $categoriesCount = count((new CategoryController($cms))->all());
}

$paletteItems = [];
foreach ($jumpPosts as $p) {
    $paletteItems[] = [
        'title' => $p->title !== '' ? $p->title : $p->slug,
        'url'   => $adminBase . 'posts/edit/?file=' . urlencode(basename($p->filePath)),
        'group' => t('Посты'),
        'meta'  => $p->status,
    ];
}
foreach ($jumpPages as $p) {
    $paletteItems[] = [
        'title' => $p->title !== '' ? $p->title : $p->slug,
        'url'   => $adminBase . 'pages/edit/?file=' . urlencode(basename($p->filePath)),
        'group' => t('Страницы'),
        'meta'  => $p->status,
    ];
}
$paletteActions = [
    ['title' => t('+ Новый пост'), 'url' => $adminBase . 'posts/new/', 'group' => t('Действия'), 'meta' => ''],
];
if ($role !== 'author') {
    $paletteActions[] = ['title' => t('+ Новая страница'), 'url' => $adminBase . 'pages/new/', 'group' => t('Действия'), 'meta' => ''];
    $paletteActions[] = ['title' => t('Категории'), 'url' => $adminBase . 'categories/', 'group' => t('Действия'), 'meta' => ''];
}
$paletteActions[] = ['title' => t('Медиа'), 'url' => $adminBase . 'media/', 'group' => t('Действия'), 'meta' => ''];
if ($role === 'admin') {
    $paletteActions[] = ['title' => t('Пользователи'), 'url' => $adminBase . 'users/',    'group' => t('Действия'), 'meta' => ''];
    $paletteActions[] = ['title' => t('Плагины'),      'url' => $adminBase . 'plugins/',  'group' => t('Действия'), 'meta' => ''];
    $paletteActions[] = ['title' => t('Настройки'),    'url' => $adminBase . 'settings/', 'group' => t('Действия'), 'meta' => ''];
}
$paletteActions[] = ['title' => t('Открыть сайт'), 'url' => ($siteUrl ?: '') . '/', 'group' => t('Действия'), 'meta' => ''];

$common['paletteItems'] = array_merge($paletteActions, $paletteItems);
$common['navCounts']    = ['posts' => count($jumpPosts), 'pages' => count($jumpPages), 'categories' => $categoriesCount];

// ----------------------------------------------------------------
// Диспетчер разделов
// ----------------------------------------------------------------
// Каждый раздел живёт в своём файле /admin/routes/. Подключается только
// нужный — остальные с диска даже не читаются. Роут сам решает, обработать
// запрос или пропустить (например, /reorder/ отвечает только на POST),
// поэтому после require выполнение может дойти до 404 ниже.
$routes = [
    'dashboard'  => 'dashboard',
    'posts'      => 'posts',
    'pages'      => 'posts',
    'categories' => 'categories',
    'reorder'    => 'reorder',
    'themes'     => 'themes',
    'plugins'    => 'plugins',
    'profile'    => 'profile',
    'users'      => 'users',
    'backups'    => 'backups',
    'settings'   => 'settings',
    'media'      => 'media',
];
if (isset($routes[$action])) {
    require __DIR__ . '/routes/' . $routes[$action] . '.php';
}


// Неизвестный раздел админки
http_response_code(404);
adminRender('404', $common + ['title' => t('Раздел не найден')]);
