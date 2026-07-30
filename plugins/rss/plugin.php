<?php
declare(strict_types=1);

/**
 * RSS-лента как плагин.
 *
 * Ядро о ленте больше не знает: плагин сам регистрирует адрес /rss.xml,
 * сам добавляет автодискавери-<link> в <head> и сам сообщает теме, что
 * ленту можно показывать (фильтр site.rss → иконка RSS в шапке/подвале).
 * Выключили плагин — ленты нет вообще.
 */

// 1. Адрес ленты. Router спрашивает плагины, только если ничего своего не нашёл,
//    поэтому перебить существующую страницу этот маршрут не может.
PluginManager::route('rss.xml', function (): void {
    // $config и $cms создаются в index.php на верхнем уровне, то есть глобальны
    global $config, $cms;
    if (!is_array($config) || !($cms instanceof ContentManager)) {
        http_response_code(500);
        echo 'RSS is unavailable.';
        return;
    }
    require_once __DIR__ . '/RssManager.php';
    (new RssManager($config, $cms))->output();
});

// 2. Тема показывает иконку/ссылку RSS только когда лента существует
Hooks::add('site.rss', fn(): bool => true);

// 3. Автодискавери: браузеры и агрегаторы находят ленту по <link> в <head>
Hooks::add('site.head', function (string $html): string {
    global $config;
    $siteUrl = rtrim((string)($config['site_url'] ?? ''), '/');
    $title   = (string)($config['site_title'] ?? '');
    $e       = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return $html . '<link rel="alternate" type="application/rss+xml" title="'
        . $e($title) . '" href="' . $e($siteUrl . '/rss.xml') . '">';
});
