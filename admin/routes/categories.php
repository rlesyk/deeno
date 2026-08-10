<?php
declare(strict_types=1);

/**
 * Раздел «Категории»: список, метаданные, слияние, удаление.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Категории (admin + editor): агрегированный список, метаданные, merge, удаление
// ----------------------------------------------------------------

if ($action === 'categories') {
    requireRole(['admin', 'editor'], $user);
    require_once dirname(__DIR__) . '/CategoryController.php';
    $catController = new CategoryController($cms);

    // Новая категория без единого поста — только метаданные (POST + CSRF)
    if ($sub === 'create' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $title       = trim((string)($_POST['title'] ?? ''));
        $slug        = (string)($_POST['slug'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $position    = (int)($_POST['position'] ?? 0);
        $icon        = trim((string)($_POST['icon'] ?? ''));
        $result      = $catController->create($title, $slug, $description, $position, $icon);
        adminRedirect($adminBase . 'categories/?' . (isset($result['error']) ? 'error=1' : 'saved=1'));
    }

    // Сохранение: название/описание + (если ссылка изменилась) переименование/объединение (POST + CSRF)
    if ($sub === 'save' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $from        = (string)($_POST['from'] ?? '');
        $title       = trim((string)($_POST['title'] ?? ''));
        $slug        = (string)($_POST['slug'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $position    = (int)($_POST['position'] ?? 0);
        $icon        = trim((string)($_POST['icon'] ?? ''));
        $result      = $catController->save($from, $title, $slug, $description, $position, $icon);
        adminRedirect($adminBase . 'categories/?' . (isset($result['error']) ? 'error=1' : 'saved=1'));
    }

    // Удаление (POST + CSRF) — посты возвращаются к дефолтной категории
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $from = (string)($_POST['from'] ?? '');
        // Виртуальная категория постов без рубрики (Post::DEFAULT_CATEGORY) — удалять нечего
        if ($from !== '' && $from !== Post::DEFAULT_CATEGORY) {
            $catController->delete($from);
        }
        adminRedirect($adminBase . 'categories/?deleted=1');
    }

    adminRender('categories', $common + [
        'title'      => t('Категории'),
        'categories' => $catController->all(),
        'mediaList'  => (new MediaManager())->all(),
        'saved'      => isset($_GET['saved']),
        'deleted'    => isset($_GET['deleted']),
        'error'      => isset($_GET['error']),
    ]);
    exit;
}
