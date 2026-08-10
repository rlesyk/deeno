<?php
declare(strict_types=1);

/**
 * Разделы «Посты» и «Страницы»: список, редактор, сохранение,
 * удаление, архивация, история версий.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Посты: список, редактор, сохранение, удаление
// ----------------------------------------------------------------

if ($action === 'posts' || $action === 'pages') {
    require_once dirname(__DIR__) . '/PostController.php';
    $controller = new PostController($cms, $security, $config);
    $type       = $action === 'pages' ? 'page' : 'post';

    // Страницы задают структуру сайта — только admin и editor
    if ($type === 'page') {
        requireRole(['admin', 'editor'], $user);
    }

    // Форма редактора: новый материал или правка существующего
    if ($sub === 'new' || $sub === 'edit') {
        $filename = $sub === 'edit' ? (string)($_GET['file'] ?? '') : '';
        $data     = $controller->editorData($filename, $user, $type);
        if (!empty($data['denied'])) {
            adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
        }

        if ($type === 'post') {
            require_once dirname(__DIR__) . '/CategoryController.php';
            $data['categoryList'] = (new CategoryController($cms))->all();
        }

        adminRender('editor', $common + $data + [
            'title'   => $data['isNew']
                ? ($type === 'page' ? t('Новая страница') : t('Новый пост'))
                : t('Редактирование'),
            'saved'     => isset($_GET['saved']),
            'restored'  => isset($_GET['restored']),
            'editErr'   => t((string)($_GET['msg'] ?? '')),
            'mediaList' => (new MediaManager())->all(),
            'revList'   => $data['isNew'] ? [] : $controller->revisions()->all($type, $data['filename']),
            'revKeep'   => $controller->revisions()->keep(),
        ]);
        exit;
    }

    // Сохранение (POST + CSRF)
    if ($sub === 'save' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        // Author не может перезаписать чужой пост, подставив его имя файла
        requireOwnPost((string)($_POST['file'] ?? ''), $user, $type, $cms, true);
        $_POST['type'] = $type;
        // Демо-режим: что бы гость ни выбрал, материал уходит в draft — на
        // публичный фронт (и в RSS/Sitemap) он не попадёт, но остаётся виден
        // автору в редакторе и предпросмотре. Так «Создать/Опубликовать»
        // работает, а обгадить публичную витрину нельзя.
        if (isDemoMode()) {
            $_POST['status'] = 'draft';
        }
        $result = $controller->save($_POST, $user);
        if (isset($result['error'])) {
            $back = (string)($_POST['file'] ?? '') !== ''
                ? $action . '/edit/?file=' . urlencode((string)$_POST['file']) . '&'
                : $action . '/new/?';
            adminRedirect($adminBase . $back . 'msg=' . urlencode($result['error']));
        }
        adminRedirect($adminBase . $action . '/edit/?file=' . urlencode($result['filename']) . '&saved=1');
    }

    // Удаление (POST + CSRF)
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $file = (string)($_POST['file'] ?? '');

        // Author удаляет только свои посты
        requireOwnPost($file, $user, $type, $cms);

        $ok = $type === 'page' ? $cms->deletePageFile($file) : $cms->deletePostFile($file);
        if ($ok) {
            // Материала нет — его история версий тоже больше не нужна
            $controller->revisions()->forget($type, $file);
            Hooks::run('post.deleted', ['file' => $file, 'type' => $type]);
        }
        adminRedirect($adminBase . $action . '/?' . ($ok ? 'deleted=1' : 'error=1'));
    }

    // В архив / из архива (POST + CSRF). Архивный пост не удалён, но скрыт
    // отовсюду: из лент, RSS, Sitemap, поиска и по прямой ссылке (404).
    if ($sub === 'archive' && $isPost && $type === 'post') {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $file = (string)($_POST['file'] ?? '');
        requireOwnPost($file, $user, $type, $cms);

        $post = $cms->postByFilename($file);
        if ($post === null) {
            adminRedirect($adminBase . 'posts/?error=1');
        }

        // Возврат — всегда в черновик: прежний статус мог быть published,
        // а тихая публикация по кнопке «из архива» — сюрприз для автора
        $toArchive = $post->status !== 'archived';
        $newStatus = $toArchive ? 'archived' : 'draft';

        // Смена статуса меняет файл — прежнее состояние в историю
        $controller->revisions()->snapshot('post', $file, (string)($user['username'] ?? ''));

        $ok = $cms->updatePostMeta($file, ['status' => $newStatus]);
        if ($ok) {
            Hooks::run('post.saved', ['file' => $file, 'meta' => ['status' => $newStatus], 'new' => false, 'type' => 'post']);
        }
        adminRedirect($adminBase . 'posts/?' . ($ok ? ($toArchive ? 'archived=1' : 'unarchived=1') : 'error=1'));
    }

    // История версий: просмотр одной сохранённой версии
    if ($sub === 'revision') {
        $file = (string)($_GET['file'] ?? '');
        $revId = (string)($_GET['rev'] ?? '');

        // Author смотрит историю только своих постов
        requireOwnPost($file, $user, $type, $cms);

        $data = $controller->editorData($file, $user, $type);
        if (!empty($data['denied']) || $data['filename'] === '') {
            adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
        }

        $revision = $controller->revisions()->parsed($type, $file, $revId);
        if ($revision === null) {
            adminErrorPage(404, t('Версия не найдена.'), t('Возможно, она вытеснена более новыми.'));
        }

        adminRender('revision', $common + [
            'title'    => t('Версия материала'),
            'kind'     => $action,
            'file'     => $file,
            'revId'    => $revId,
            'revMeta'  => $revision['meta'],
            'revBody'  => $revision['body'],
            'current'  => ['meta' => $data['meta'], 'body' => $data['body']],
            'revList'  => $controller->revisions()->all($type, $file),
        ]);
        exit;
    }

    // Восстановление версии (POST + CSRF)
    if ($sub === 'restore' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $file = (string)($_POST['file'] ?? '');
        requireOwnPost($file, $user, $type, $cms);

        // В демо восстановленная версия уходит в draft — как и обычное сохранение
        $result = $controller->restore($type, $file, (string)($_POST['rev'] ?? ''), $user, isDemoMode());
        if (isset($result['error'])) {
            adminRedirect($adminBase . $action . '/edit/?file=' . urlencode($file)
                . '&msg=' . urlencode($result['error']));
        }
        adminRedirect($adminBase . $action . '/edit/?file=' . urlencode($result['filename']) . '&restored=1');
    }

    // Список страниц (фильтры: статус + поиск по заголовку/slug)
    if ($action === 'pages') {
        $fStatus = (string)($_GET['status'] ?? '');
        $fSearch = trim((string)($_GET['q'] ?? ''));
        $pages   = $cms->pages();
        if (in_array($fStatus, ['published', 'draft'], true)) {
            $pages = array_values(array_filter($pages, fn($p) => $p->status === $fStatus));
        }
        if ($fSearch !== '') {
            $needle = mb_strtolower($fSearch);
            $pages  = array_values(array_filter(
                $pages,
                fn($p) => str_contains(mb_strtolower($p->title . ' ' . $p->slug), $needle)
            ));
        }
        adminRender('pages', $common + [
            'title'   => t('Страницы'),
            'pages'   => $pages,
            'fStatus' => $fStatus,
            'fSearch' => $fSearch,
            'deleted' => isset($_GET['deleted']),
            'error'   => isset($_GET['error']),
        ]);
        exit;
    }

    $all = $cms->posts(0, ['status' => 'all']);

    // Author видит только свои посты (чекпоинт этапа 3)
    if (($user['role'] ?? '') === 'author') {
        $me  = (string)($user['username'] ?? '');
        $all = array_values(array_filter($all, fn($p) => $p->author === $me));
    }

    // Список категорий для фильтра (до фильтрации)
    $categories = [];
    foreach ($all as $p) {
        $c = $p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY;
        $categories[$c] = true;
    }
    $categories = array_keys($categories);
    sort($categories);

    // Фильтры
    $fStatus   = (string)($_GET['status'] ?? '');
    $fCategory = (string)($_GET['category'] ?? '');
    $fSearch   = trim((string)($_GET['q'] ?? ''));

    if ($fStatus !== '') {
        $all = array_values(array_filter($all, fn($p) => $p->status === $fStatus));
    }
    if ($fCategory !== '') {
        $all = array_values(array_filter($all, fn($p) => ($p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY) === $fCategory));
    }
    if ($fSearch !== '') {
        $all = array_values(array_filter($all, fn($p) => mb_stripos($p->title, $fSearch) !== false));
    }

    // Пагинация: 20 на страницу (раздел 10.3 ТЗ)
    $perPage = 20;
    $total   = count($all);
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $posts   = array_slice($all, ($page - 1) * $perPage, $perPage);

    adminRender('posts', $common + [
        'title'      => t('Посты'),
        'posts'      => $posts,
        'total'      => $total,
        'page'       => $page,
        'perPage'    => $perPage,
        'categories' => $categories,
        'fStatus'    => $fStatus,
        'fCategory'  => $fCategory,
        'fSearch'    => $fSearch,
        'deleted'    => isset($_GET['deleted']),
        'error'      => isset($_GET['error']),
        'archived'   => isset($_GET['archived']),
        'unarchived' => isset($_GET['unarchived']),
    ]);
    exit;
}
