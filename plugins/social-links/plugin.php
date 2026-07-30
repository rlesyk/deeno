<?php
declare(strict_types=1);

/**
 * Ссылки на соцсети как плагин.
 *
 * Ядро больше не хранит их в настройках сайта: адреса живут в настройках
 * плагина, а тема получает готовый список через фильтр site.social
 * ([['name' => 'telegram', 'url' => 'https://…'], …]).
 * Иконки рисует SocialIcons из ядра — это просто набор SVG, темы обращаются
 * к нему напрямую, поэтому он остался на месте.
 */
Hooks::add('site.social', function (array $list): array {
    $saved = PluginManager::settings('social-links');

    // На установках, обновлённых с 1.0, адреса лежат в config['social'] —
    // подхватываем их, пока владелец не переcохранил настройки плагина.
    global $config;
    $legacy = (array)($config['social'] ?? []);

    foreach ($saved as $name => $url) {
        $url = trim((string)$url);
        if ($url === '' && isset($legacy[$name])) {
            $url = trim((string)$legacy[$name]);
        }
        // Только абсолютные http(s)-ссылки: остальное в подвал не пускаем
        if ($url !== '' && preg_match('~^https?://~i', $url)) {
            $list[] = ['name' => $name, 'url' => $url];
        }
    }
    return $list;
});
