<?php
declare(strict_types=1);

/**
 * Расстановка drag-and-drop: порядок разделов и статей, перенос.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Расстановка (drag-and-drop): новый порядок разделов/статей и перенос
// поста между разделами. JSON-ответ. Роли admin/editor.
// ----------------------------------------------------------------
if ($action === 'reorder' && $isPost) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    if (!in_array((string)($user['role'] ?? ''), ['admin', 'editor'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'role']);
        exit;
    }
    $payload = json_decode((string)($_POST['data'] ?? ''), true);
    if (!is_array($payload['sections'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad']);
        exit;
    }

    // Номера пишем ТОЛЬКО там, где выбран ручной порядок: при сортировке по
    // алфавиту или датам дерево всё равно рендерится расчётом, и записанный
    // position игнорировался бы — перестановка «откатывалась» на глазах у
    // пользователя, а файлы молча переписывались. Перенос МЕЖДУ разделами
    // меняет категорию и работает при любом режиме. Проверка именно здесь,
    // а не только в теме: JS можно обойти, правило должно быть одно.
    $manualPosts    = (string)($config['article_order'] ?? 'manual') === 'manual';
    $manualSections = (string)($config['category_order'] ?? 'alpha') === 'manual';

    $catMgr    = new CategoryManager();
    $redirects = new RedirectManager();
    foreach ($payload['sections'] as $i => $sec) {
        $cat      = (string)($sec['category'] ?? '');
        $storeCat = $cat === Post::DEFAULT_CATEGORY ? '' : $cat;
        // Порядок раздела (только у явных категорий, не у «Без категории»)
        if ($manualSections && $cat !== '' && $cat !== Post::DEFAULT_CATEGORY && $catMgr->exists($cat)) {
            $catMgr->setPosition($cat, (int)$i);
        }
        foreach ((array)($sec['posts'] ?? []) as $j => $file) {
            $post = $cms->postByFilename((string)$file);
            if ($post === null) continue;
            $oldCat  = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;
            $newCat  = $cat !== '' ? $cat : Post::DEFAULT_CATEGORY;
            $moved   = $oldCat !== $newCat;
            // Перенос в другой раздел меняет URL → 301-редирект
            if ($moved) {
                $redirects->add(
                    '/' . $oldCat . '/' . $post->slug . '/',
                    '/' . ($storeCat !== '' ? $storeCat : Post::DEFAULT_CATEGORY) . '/' . $post->slug . '/'
                );
            }
            $meta = [];
            if ($manualPosts) $meta['position'] = (int)$j;
            if ($moved)       $meta['category'] = $storeCat;
            if ($meta !== []) $cms->updatePostMeta((string)$file, $meta);
        }
    }
    echo json_encode(['ok' => true, 'positions' => $manualPosts]);
    exit;
}
