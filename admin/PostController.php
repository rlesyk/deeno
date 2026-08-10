<?php
declare(strict_types=1);

defined('FFC_ADMIN') or exit;

/**
 * Контроллер постов и статичных страниц: форма редактора и сохранение.
 * Тип 'post' — /content/posts/ (имя файла с датой),
 * тип 'page' — /content/pages/ (имя файла = slug.md, определяет URL).
 * При смене slug/категории добавляет 301-редирект.
 */
class PostController
{
    private RevisionManager $revisions;

    public function __construct(
        private ContentManager $cms,
        private Security $security,
        private array $config,
    ) {
        $this->revisions = new RevisionManager($config);
    }

    public function revisions(): RevisionManager
    {
        return $this->revisions;
    }

    /**
     * Данные для формы редактора. $filename === '' — новый материал.
     */
    public function editorData(string $filename, array $currentUser, string $type = 'post'): array
    {
        $meta = [];
        $body = '';
        $dir  = $type === 'page' ? $this->cms->pagesDir() : $this->cms->postsDir();

        if ($filename !== '') {
            $path = $dir . $filename;
            if (preg_match('/^[\w\-.]+\.md$/u', $filename) && !str_contains($filename, '..') && is_file($path)) {
                $parsed = FrontmatterParser::parse((string)file_get_contents($path));
                $meta   = $parsed['meta'];
                $body   = $parsed['body'];
                // Author не может открыть чужой материал
                if (($currentUser['role'] ?? '') === 'author'
                    && (string)($meta['author'] ?? '') !== (string)($currentUser['username'] ?? '')) {
                    return ['denied' => true, 'filename' => '', 'type' => $type, 'meta' => [], 'body' => '',
                            'previewToken' => '', 'previewExp' => 0, 'isNew' => true,
                            'author' => (string)($currentUser['username'] ?? '')];
                }
                // Отложенный пост, чья дата прошла — фактически published
                if (($meta['status'] ?? '') === 'scheduled'
                    && !empty($meta['scheduled_date'])
                    && strtotime((string)$meta['scheduled_date']) <= time()) {
                    $meta['status'] = 'published';
                }
                if ($type === 'page' && ($meta['slug'] ?? '') === '') {
                    $meta['slug'] = basename($filename, '.md');
                }
            } else {
                $filename = '';
            }
        }

        $previewExp = time() + Security::PREVIEW_TTL;

        return [
            'filename'     => $filename,
            'type'         => $type,
            'meta'         => $meta,
            'body'         => $body,
            'previewToken' => ($type === 'post' && $filename !== '') ? Security::previewToken($filename, $previewExp) : '',
            'previewExp'   => $previewExp,
            'isNew'        => $filename === '',
            'author'       => (string)($meta['author'] ?? ($currentUser['username'] ?? '')),
        ];
    }

    /**
     * Сохранить пост или страницу из POST-формы.
     * Возвращает ['filename' => ...] или ['error' => ...].
     */
    public function save(array $post, array $currentUser): array
    {
        $type = ($post['type'] ?? 'post') === 'page' ? 'page' : 'post';

        $title = trim((string)($post['title'] ?? ''));
        if ($title === '') {
            return ['error' => Lang::t('Заголовок обязателен.')];
        }

        $slug = trim((string)($post['slug'] ?? ''));
        $slug = $slug !== '' ? Slugger::make($slug) : Slugger::make($title);
        if ($slug === '') {
            return ['error' => Lang::t('Не удалось сформировать slug.')];
        }

        $filename = trim((string)($post['file'] ?? ''));
        $isNew    = $filename === '';

        if (!$isNew && (!preg_match('/^[\w\-.]+\.md$/u', $filename) || str_contains($filename, '..'))) {
            return ['error' => Lang::t('Некорректное имя файла.')];
        }

        return $type === 'page'
            ? $this->savePage($post, $currentUser, $title, $slug, $filename, $isNew)
            : $this->savePost($post, $currentUser, $title, $slug, $filename, $isNew);
    }

    // ----------------------------------------------------------------
    // Посты
    // ----------------------------------------------------------------

    private function savePost(array $post, array $user, string $title, string $slug, string $filename, bool $isNew): array
    {
        // Статус берётся напрямую из селекта — единственный источник правды.
        $status  = (string)($post['status'] ?? 'draft');
        $allowed = ['published', 'draft', 'sticky', 'scheduled', 'unlisted', 'archived'];
        if (!in_array($status, $allowed, true)) $status = 'draft';

        $date = trim((string)($post['date'] ?? ''));
        if ($date === '' || strtotime($date) === false) {
            $date = date('Y-m-d');
        }

        // Author пишет только под своим именем; чужой пост ему не отдаст editorData
        $role   = (string)($user['role'] ?? 'author');
        $author = $role === 'author'
            ? (string)($user['username'] ?? '')
            : (string)($post['author'] ?? ($user['username'] ?? ''));

        $meta = [
            'title'          => $title,
            'slug'           => $slug,
            'date'           => $date,
            'date_modified'  => date('Y-m-d'),
            'author'         => $author,
            'status'         => $status,
            'scheduled_date' => $status === 'scheduled' ? trim((string)($post['scheduled_date'] ?? '')) : '',
            'category'       => Slugger::make((string)($post['category'] ?? '')),
            'tags'           => $this->parseTags((string)($post['tags'] ?? '')),
            'cover'          => trim((string)($post['cover'] ?? '')),
            'excerpt'        => trim((string)($post['excerpt'] ?? '')),
            'template'       => trim((string)($post['template'] ?? '')),
            'position'       => (int)($post['position'] ?? 0) ?: '',
            'icon'           => trim((string)($post['icon'] ?? '')),
        ] + $this->seoMeta($post) + [
            'custom_fields'  => $this->parseCustomFields($post),
        ];

        // Санитизация HTML (раздел 14 ТЗ): контент не-админов рендерится
        // в safe mode Parsedown — сырой HTML/скрипты экранируются
        if ($role !== 'admin') {
            $meta['safe_html'] = true;
        }

        // Отложенный пост с прошедшей датой — сразу published (актуализация в файле)
        if ($status === 'scheduled') {
            $ts = strtotime((string)$meta['scheduled_date']);
            if ($ts === false) {
                return ['error' => Lang::t('Укажите корректную дату отложенной публикации.')];
            }
            if ($ts <= time()) {
                $meta['status'] = 'published';
                $meta['scheduled_date'] = '';
            }
        }

        // Смена slug/категории у опубликованного поста → 301-редирект
        if (!$isNew) {
            $old = $this->loadOldMeta($this->cms->postsDir() . $filename);
            if ($old !== null) {
                $oldUrl = '/' . (($old['category'] ?? '') !== '' ? $old['category'] : Post::DEFAULT_CATEGORY) . '/' . ($old['slug'] ?? '') . '/';
                $newUrl = '/' . ($meta['category'] !== '' ? $meta['category'] : Post::DEFAULT_CATEGORY) . '/' . $slug . '/';
                if ($oldUrl !== $newUrl && ($old['slug'] ?? '') !== '') {
                    (new RedirectManager())->add($oldUrl, $newUrl);
                }
            }
        }

        $body    = str_replace("\r\n", "\n", (string)($post['content'] ?? ''));
        $content = FrontmatterSerializer::serialize($meta, $body);

        if ($isNew) {
            $filename = $this->uniqueFilename($date, $slug);
        } else {
            // Снимок предыдущего состояния — до того, как файл будет перезаписан
            $this->revisions->snapshot('post', $filename, (string)($user['username'] ?? ''));
        }

        if (@file_put_contents($this->cms->postsDir() . $filename, $content, LOCK_EX) === false) {
            return ['error' => Lang::t('Не удалось записать файл поста (права на /content/posts/?).')];
        }

        Hooks::run('post.saved', ['file' => $filename, 'meta' => $meta, 'new' => $isNew, 'type' => 'post']);

        return ['filename' => $filename];
    }

    // ----------------------------------------------------------------
    // Статичные страницы
    // ----------------------------------------------------------------

    private function savePage(array $post, array $user, string $title, string $slug, string $filename, bool $isNew): array
    {
        // Статус страницы — из селекта (у страниц только draft/published).
        $status = (string)($post['status'] ?? 'draft');
        if (!in_array($status, ['published', 'draft'], true)) $status = 'draft';

        $meta = [
            'title'         => $title,
            'slug'          => $slug,
            'date'          => trim((string)($post['date'] ?? '')) ?: date('Y-m-d'),
            'date_modified' => date('Y-m-d'),
            'author'        => (string)($post['author'] ?? ($user['username'] ?? '')),
            'status'        => $status,
            'template'      => trim((string)($post['template'] ?? '')),
            'position'      => (int)($post['position'] ?? 0) ?: '',
            'icon'          => trim((string)($post['icon'] ?? '')),
            // show_in_menu по умолчанию true — пишем только явное false (bool)
            'show_in_menu'  => empty($post['show_in_menu']) ? false : '',
        ] + $this->seoMeta($post) + [
            'custom_fields' => $this->parseCustomFields($post),
        ];

        $newFilename = $slug . '.md';
        $pagesDir    = $this->cms->pagesDir();
        if (!is_dir($pagesDir)) {
            @mkdir($pagesDir, 0755, true);
        }

        if ($isNew && is_file($pagesDir . $newFilename)) {
            return ['error' => sprintf(t('Страница со slug «%s» уже существует.'), $slug)];
        }

        $body    = str_replace("\r\n", "\n", (string)($post['content'] ?? ''));
        $content = FrontmatterSerializer::serialize($meta, $body);

        // Снимок предыдущего состояния — пока старый файл ещё на месте
        if (!$isNew) {
            $this->revisions->snapshot('page', $filename, (string)($user['username'] ?? ''));
        }

        if (@file_put_contents($pagesDir . $newFilename, $content, LOCK_EX) === false) {
            return ['error' => Lang::t('Не удалось записать файл страницы (права на /content/pages/?).')];
        }

        // Смена slug: имя файла страницы = URL, старый файл удаляем + 301
        if (!$isNew && $filename !== $newFilename) {
            $oldSlug = basename($filename, '.md');
            @unlink($pagesDir . $filename);
            (new RedirectManager())->add('/' . $oldSlug . '/', '/' . $slug . '/');
            // История привязана к имени файла — переезжает вместе со страницей
            $this->revisions->rename('page', $filename, $newFilename);
        }

        Hooks::run('post.saved', ['file' => $newFilename, 'meta' => $meta, 'new' => $isNew, 'type' => 'page']);

        return ['filename' => $newFilename];
    }

    // ----------------------------------------------------------------
    // История версий
    // ----------------------------------------------------------------

    /**
     * Вернуть материал к сохранённой версии.
     * Текущее состояние тоже уходит в историю, поэтому откат обратим.
     * Возвращает ['filename' => ...] или ['error' => ...].
     */
    public function restore(string $type, string $filename, string $id, array $user, bool $forceDraft = false): array
    {
        $type = $type === 'page' ? 'page' : 'post';

        if (!preg_match('/^[\w\-.]+\.md$/u', $filename) || str_contains($filename, '..')) {
            return ['error' => Lang::t('Некорректное имя файла.')];
        }

        $dir  = $type === 'page' ? $this->cms->pagesDir() : $this->cms->postsDir();
        $path = $dir . $filename;
        if (!is_file($path)) {
            return ['error' => Lang::t('Материал не найден.')];
        }

        $parsed = $this->revisions->parsed($type, $filename, $id);
        if ($parsed === null) {
            return ['error' => Lang::t('Версия не найдена.')];
        }

        $meta = $parsed['meta'];
        $old  = $this->loadOldMeta($path) ?? [];

        // Имя файла страницы задаёт её URL — восстановление адрес не меняет
        if ($type === 'page') {
            $meta['slug'] = basename($filename, '.md');
        }
        $meta['date_modified'] = date('Y-m-d');

        // Демо-режим: версия могла быть опубликованной — на витрину не пускаем
        if ($forceDraft) {
            $meta['status']         = 'draft';
            $meta['scheduled_date'] = '';
        }

        // Author правит только под своим именем (чужой материал ему не отдадут)
        if (($user['role'] ?? '') === 'author') {
            $meta['author'] = (string)($user['username'] ?? '');
        }

        // Текущее состояние — в историю, иначе откат затрёт его безвозвратно
        $this->revisions->snapshot($type, $filename, (string)($user['username'] ?? ''));

        $content = FrontmatterSerializer::serialize($meta, $parsed['body']);
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            return ['error' => Lang::t('Не удалось записать файл.')];
        }

        // У поста URL зависит от slug/категории в шапке — как и при обычном
        // сохранении, старый адрес отправляем 301-м на новый
        if ($type === 'post') {
            $oldUrl = '/' . (($old['category'] ?? '') !== '' ? $old['category'] : Post::DEFAULT_CATEGORY) . '/' . ($old['slug'] ?? '') . '/';
            $newUrl = '/' . (($meta['category'] ?? '') !== '' ? $meta['category'] : Post::DEFAULT_CATEGORY) . '/' . ($meta['slug'] ?? '') . '/';
            if ($oldUrl !== $newUrl && ($old['slug'] ?? '') !== '' && ($meta['slug'] ?? '') !== '') {
                (new RedirectManager())->add($oldUrl, $newUrl);
            }
        }

        Hooks::run('post.saved', ['file' => $filename, 'meta' => $meta, 'new' => false, 'type' => $type, 'restored' => $id]);

        return ['filename' => $filename];
    }

    // ----------------------------------------------------------------

    /** Общие SEO-поля формы */
    private function seoMeta(array $post): array
    {
        return [
            'seo_title'       => trim((string)($post['seo_title'] ?? '')),
            'seo_description' => trim((string)($post['seo_description'] ?? '')),
            'seo_noindex'     => !empty($post['seo_noindex']) ? true : '',
            'canonical'       => trim((string)($post['canonical'] ?? '')),
            'og_title'        => trim((string)($post['og_title'] ?? '')),
            'og_image'        => trim((string)($post['og_image'] ?? '')),
            'og_description'  => trim((string)($post['og_description'] ?? '')),
        ];
    }

    /** Метаданные существующего файла (для сравнения slug/категории) */
    private function loadOldMeta(string $path): ?array
    {
        if (!is_file($path)) return null;
        return FrontmatterParser::parse((string)file_get_contents($path))['meta'];
    }

    private function parseTags(string $raw): array
    {
        $tags = array_map('trim', explode(',', $raw));
        $tags = array_filter($tags, fn($t) => $t !== '');
        $tags = array_map(fn($t) => mb_strtolower($t, 'UTF-8'), $tags);
        return array_values(array_unique($tags));
    }

    /** Пары key/value из полей cf_key[] / cf_value[] */
    private function parseCustomFields(array $post): array
    {
        $keys   = (array)($post['cf_key'] ?? []);
        $values = (array)($post['cf_value'] ?? []);
        $fields = [];
        foreach ($keys as $i => $key) {
            $key = trim((string)$key);
            $val = trim((string)($values[$i] ?? ''));
            if ($key === '' || $val === '' || !preg_match('/^\w+$/', $key)) continue;
            $fields[$key] = $val;
        }
        return $fields;
    }

    /** Имя файла YYYY-MM-DD-slug.md; при коллизии — суффикс -2, -3… */
    private function uniqueFilename(string $date, string $slug): string
    {
        $day  = date('Y-m-d', strtotime($date) ?: time());
        $base = $day . '-' . $slug;
        $name = $base . '.md';
        $i    = 2;
        while (is_file($this->cms->postsDir() . $name)) {
            $name = $base . '-' . $i . '.md';
            $i++;
        }
        return $name;
    }
}
