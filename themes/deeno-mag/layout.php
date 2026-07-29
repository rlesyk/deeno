<?php declare(strict_types=1);
/**
 * deeno-mag — журнальная тема deeno. Фирменный deeno UI (индиго #4F6EF7,
 * Inter, тонкие границы, свет/тьма), но модель — редакционная витрина:
 * горизонтальное меню рубрик сверху вместо бокового сайдбара. Свой код.
 */
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Рубрики для верхнего меню (slug → человекочитаемое название).
$menuCategories = [];
foreach ($cms->posts() as $p) {
    $menuCategories[$p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY] = true;
}
$menuCategories = array_keys($menuCategories);
sort($menuCategories);
$categoryManager = new CategoryManager();

// Подсветка активного пункта — по текущему пути запроса.
$reqPath = rtrim(strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/');
$navActive = function (string $url) use ($reqPath): bool {
    $p = rtrim((string)(parse_url($url, PHP_URL_PATH) ?: '/'), '/');
    if ($p === '') {
        return $reqPath === '';
    }
    return $reqPath === $p || str_starts_with($reqPath, $p . '/');
};

// Язык интерфейса темы — по языку сайта (Настройки). Отдельного переключателя
// в теме нет: локализуется всего пара слов, ради них тумблер не нужен.
$lang = str_starts_with(strtolower((string)$site->language), 'en') ? 'en' : 'ru';
$trDict = [
    'Главная' => 'Home',
    'Поиск' => 'Search',
    'Введите запрос' => 'Search…',
    'Нажмите Enter, чтобы начать поиск' => 'Press Enter to search',
    'Читайте также' => 'Read next',
    'Работает на deeno' => 'Powered by deeno',
    'Редактировать' => 'Edit',
    'Предпросмотр — материал ещё не опубликован' => 'Preview — not published yet',
    'Меню' => 'Menu',
    'Тема' => 'Theme',
    'Тег' => 'Tag',
    'Рубрика' => 'Category',
    'Найдено:' => 'Found:',
    'Ничего не найдено. Попробуйте другой запрос.' => 'Nothing found. Try another query.',
    'Постов пока нет.' => 'No posts yet.',
    'Читать далее' => 'Read more',
    'Такой страницы нет' => 'Page not found',
    'Возможно, материал был удалён или в адресе опечатка.' => 'The page may have been removed or the address mistyped.',
    '← На главную' => '← Home',
    'Главное' => 'Featured',
    'Свежее в' => 'Latest in',
    'Все' => 'All',
    'мин' => 'min read',
    'Ещё в рубрике' => 'More in',
];
$tr = fn(string $s): string => $lang === 'en' ? ($trDict[$s] ?? $s) : $s;

// Время чтения по сырому markdown (≈200 слов/мин), минимум 1.
$readTime = function (Post $p): int {
    $words = str_word_count(strip_tags($p->rawBody()));
    return max(1, (int)round($words / 200));
};
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Панель браузера в цвет темы (--canvas): светлая #f9fafb, тёмная #0F1117.
       Тег стоит ДО скрипта, иначе скрипту нечего обновлять. -->
  <meta name="theme-color" content="#f9fafb">
  <script>(function(){try{var d=localStorage.getItem('deeno-site-theme')==='dark';if(d){document.documentElement.setAttribute('data-theme','dark');var m=document.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content','#0F1117');}}catch(e){}})();</script>
  <?php if (isset($seo)): ?>
  <?= $seo->head($post ?? null, $seoCtx ?? []) ?>
  <?php else: ?>
  <title><?= isset($post) ? $e($post->title . ' | ' . $site->title) : $e($site->title) ?></title>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= $e($theme->asset('style.css')) ?>">
  <?= Hooks::filter('site.head', '') ?>
</head>
<body>

<?php if (!empty($isPreview)): ?>
<div class="preview-bar"><?= $e($tr('Предпросмотр — материал ещё не опубликован')) ?></div>
<?php endif; ?>

<!-- Верхняя шапка: логотип · горизонтальное меню рубрик · инструменты -->
<header class="topbar">
  <button class="burger" id="menu-toggle" aria-label="<?= $e($tr('Меню')) ?>">
    <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>

  <a class="topbar__logo" href="<?= $e($site->url . '/') ?>"><?php if ($site->logo !== ''): ?><img class="topbar__logo-img" src="<?= $e($site->logo) ?>" alt=""><?php endif; ?><span><?= $e($site->title) ?></span></a>

  <nav class="topnav" id="topnav">
    <a<?= $navActive($site->url . '/') ? ' class="active"' : '' ?> href="<?= $e($site->url . '/') ?>"><?= $e($tr('Главная')) ?></a>
    <?php foreach ($menuCategories as $cat): $u = $site->url . '/' . $cat . '/'; ?>
      <a<?= $navActive($u) ? ' class="active"' : '' ?> href="<?= $e($u) ?>"><?= $e($categoryManager->get($cat)['title']) ?></a>
    <?php endforeach; ?>
    <?php foreach ($cms->menuPages() as $p): $u = $site->url . '/' . $p->slug . '/'; ?>
      <a<?= $navActive($u) ? ' class="active"' : '' ?> href="<?= $e($u) ?>"><?= $e($p->title) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="topbar__tools">
    <button type="button" class="tool" id="search-open" aria-label="<?= $e($tr('Поиск')) ?>" title="<?= $e($tr('Поиск')) ?>">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    </button>
    <button type="button" class="tool" id="theme-toggle" aria-label="<?= $e($tr('Тема')) ?>" title="<?= $e($tr('Тема')) ?>">
      <svg class="theme-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      <svg class="theme-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
    </button>
    <?php if ($site->rss): ?>
      <a class="tool" href="<?= $e($site->url . '/rss.xml') ?>" title="RSS" aria-label="RSS">
        <svg viewBox="0 0 24 24"><path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/></svg>
      </a>
    <?php endif; ?>
  </div>

  <!-- Затемнение под drawer — ВНУТРИ топбара (в том же контексте наложения),
       чтобы затемнять и контент, и сам топбар, а drawer оставался над ним. -->
  <div class="overlay" id="overlay"></div>
</header>

<main class="content">
  <?php require $contentFile; ?>
</main>

<footer class="site-footer">
  <span><?= $site->footerText !== '' ? $e($site->footerText) : $e($site->title) . ' © ' . date('Y') ?></span>
  <?php if (!empty($site->social)): ?>
    <ul class="site-footer__social">
      <?php foreach ($site->social as $s): ?>
        <li><a href="<?= $e($s['url']) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $e($s['name']) ?>"><?= SocialIcons::svg($s['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <span><?= $e($tr('Работает на deeno')) ?></span>
</footer>

<!-- Оверлей поиска (как jump-модалка дашборда: поле + Esc) -->
<div class="search-overlay" id="search-overlay" hidden>
  <form class="search-box" method="get" action="<?= $e($site->url) ?>/search/">
    <div class="search-box__field">
      <svg class="search-box__icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="search" name="q" id="search-field" placeholder="<?= $e($tr('Введите запрос')) ?>"
             value="<?= $e((string)($searchQuery ?? '')) ?>" autocomplete="off">
      <kbd>Esc</kbd>
    </div>
    <p class="search-box__hint"><?= $e($tr('Нажмите Enter, чтобы начать поиск')) ?></p>
  </form>
</div>

<?php if (!empty($editUrl)): ?>
<a class="edit-fab" href="<?= $e($editUrl) ?>">
  <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
  <?= $e($tr('Редактировать')) ?>
</a>
<?php endif; ?>

<script src="<?= $e($theme->asset('main.js')) ?>" defer></script>
<?= Hooks::filter('site.footer', '') ?>
</body>
</html>
