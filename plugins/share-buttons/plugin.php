<?php
declare(strict_types=1);

/**
 * Кнопки «Поделиться»: в конец статьи добавляет ссылки-шеринг в соцсети.
 * Без слежки — обычные share-ссылки. Иконки — общий класс SocialIcons.
 */
Hooks::add('post.content', function (string $html, array $ctx): string {
    $post = $ctx['post'] ?? null;
    if (!is_object($post) || !method_exists($post, 'url')) {
        return $html;
    }
    $url = (string)$post->url();
    if ($url === '') {
        return $html;
    }
    // Для шеринга нужен абсолютный адрес
    if (!preg_match('~^https?://~i', $url)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? '') . $url;
    }
    $u = rawurlencode($url);
    $t = rawurlencode((string)($post->title ?? ''));

    $known = [
        'telegram' => 'https://t.me/share/url?url=' . $u . '&text=' . $t,
        'vk'       => 'https://vk.com/share.php?url=' . $u,
        'x'        => 'https://twitter.com/intent/tweet?url=' . $u . '&text=' . $t,
        'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
    ];

    // Настройки задаются в админке (Плагины → шестерёнка), схема — в plugin.json
    $chosen = preg_split('/\s*,\s*/', (string)PluginManager::setting('share-buttons', 'networks'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $nets   = [];
    foreach ($chosen as $name) {
        $name = strtolower($name);
        if (isset($known[$name])) $nets[$name] = $known[$name];
    }
    if ($nets === []) {
        return $html;   // все сети выключены — блок не нужен
    }

    $links = '';
    foreach ($nets as $name => $href) {
        $icon = class_exists('SocialIcons') ? SocialIcons::svg($name, 20) : $name;
        $links .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
            . ' target="_blank" rel="noopener nofollow" aria-label="' . $name . '"'
            . ' style="display:inline-flex;color:inherit;opacity:.7">' . $icon . '</a>';
    }
    $label = trim((string)PluginManager::setting('share-buttons', 'label'));
    $title = $label === '' ? '' :
        '<span style="font-size:.9em;opacity:.6">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';

    $block = '<div class="dn-share" style="display:flex;align-items:center;gap:16px;'
        . 'margin:2.4em 0 0;padding-top:1.3em;border-top:1px solid rgba(128,128,128,.2)">'
        . $title . $links . '</div>';

    return $html . $block;
});
