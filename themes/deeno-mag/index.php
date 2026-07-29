<?php declare(strict_types=1);
/**
 * deeno-mag — главная и списки.
 * Главная (без рубрики/тега/поиска, стр. 1) — редакционная витрина:
 * крупный featured из sticky + секции «Свежее в <рубрике>».
 * Рубрика/тег/поиск/пагинация — обычная сетка карточек.
 * $e, $tr, $readTime, $categoryManager приходят из layout.php.
 */
$currentPage = $page ?? 1;

$isHome = !isset($searchQuery) && !isset($tag)
    && (!isset($category) || $category === '') && $currentPage === 1;

// Карточка поста (переиспользуется в секциях и в сетке списков).
$card = function (Post $p) use ($e, $site, $tr, $readTime, $categoryManager): void {
    $slug = $p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY;
    ?>
    <article class="card">
      <?php if ($p->cover): ?>
        <a class="card__thumb" href="<?= $e($p->url()) ?>"><img src="<?= $e($p->coverSrc()) ?>" alt="<?= $e($p->title) ?>" loading="lazy"></a>
      <?php endif; ?>
      <div class="card__body">
        <p class="card__meta">
          <a class="card__cat" href="<?= $e($site->url . '/' . $slug . '/') ?>"><?= $e($categoryManager->get($slug)['title']) ?></a>
          <span><?= $e($p->date()) ?></span>
          <span class="card__read"><?= $readTime($p) ?>&nbsp;<?= $e($tr('мин')) ?></span>
        </p>
        <h3 class="card__title"><a href="<?= $e($p->url()) ?>"><?= $e($p->title) ?></a></h3>
        <div class="card__excerpt"><?= $p->excerpt() ?></div>
      </div>
    </article>
    <?php
};
?>

<?php if ($isHome): ?>
  <?php
    $all = $cms->posts();
    $featured = null;
    foreach ($all as $p) { if ($p->isSticky()) { $featured = $p; break; } }
    if ($featured === null && !empty($all)) { $featured = $all[0]; }

    // Группируем по рубрике, исключая featured.
    $byCat = [];
    foreach ($all as $p) {
        if ($featured !== null && $p->filePath === $featured->filePath) { continue; }
        $slug = $p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY;
        $byCat[$slug][] = $p;
    }
    // Порядок секций — как в categories() (по числу постов, активные первыми).
    $order = array_keys($cms->categories());
  ?>

  <?php if ($featured === null): ?>
    <p class="list-empty"><?= $e($tr('Постов пока нет.')) ?></p>
  <?php else: ?>

    <?php $fSlug = $featured->category !== '' ? $featured->category : Post::DEFAULT_CATEGORY; ?>
    <a class="lead" href="<?= $e($featured->url()) ?>">
      <?php if ($featured->cover): ?>
        <span class="lead__cover"><img src="<?= $e($featured->coverSrc()) ?>" alt="<?= $e($featured->title) ?>"></span>
      <?php endif; ?>
      <span class="lead__body">
        <span class="lead__eyebrow"><?= $e($tr('Главное')) ?></span>
        <span class="lead__title"><?= $e($featured->title) ?></span>
        <span class="lead__excerpt"><?= $e(strip_tags($featured->excerpt())) ?></span>
        <span class="lead__meta">
          <span class="lead__cat"><?= $e($categoryManager->get($fSlug)['title']) ?></span>
          <span><?= $e($featured->date()) ?></span>
          <span><?= $readTime($featured) ?>&nbsp;<?= $e($tr('мин')) ?></span>
        </span>
      </span>
    </a>

    <?php foreach ($order as $slug): ?>
      <?php if (empty($byCat[$slug])) { continue; } $items = array_slice($byCat[$slug], 0, 3); ?>
      <section class="section">
        <header class="section__head">
          <h2 class="section__title"><?= $e($tr('Свежее в')) ?> <span><?= $e($categoryManager->get($slug)['title']) ?></span></h2>
          <a class="section__all" href="<?= $e($site->url . '/' . $slug . '/') ?>"><?= $e($tr('Все')) ?> →</a>
        </header>
        <div class="grid">
          <?php foreach ($items as $p) { $card($p); } ?>
        </div>
      </section>
    <?php endforeach; ?>

  <?php endif; ?>

<?php else: ?>

  <?php if (isset($searchQuery)): ?>
    <header class="list-head">
      <p class="list-head__eyebrow"><?= $e($tr('Поиск')) ?></p>
      <h1>«<?= $e($searchQuery) ?>»</h1>
      <p class="list-head__meta"><?= $e($tr('Найдено:')) ?> <?= count($posts) ?></p>
    </header>
  <?php elseif (isset($tag)): ?>
    <header class="list-head">
      <p class="list-head__eyebrow"><?= $e($tr('Тег')) ?></p>
      <h1>#<?= $e($tag) ?></h1>
    </header>
  <?php elseif (isset($category) && $category !== ''): ?>
    <header class="list-head">
      <p class="list-head__eyebrow"><?= $e($tr('Рубрика')) ?></p>
      <h1><?= $e($categoryTitle ?? $category) ?></h1>
      <?php if (!empty($categoryDescription)): ?>
        <p class="list-head__lead"><?= $e($categoryDescription) ?></p>
      <?php endif; ?>
    </header>
  <?php endif; ?>

  <?php if (empty($posts)): ?>
    <p class="list-empty"><?= $e(isset($searchQuery) ? $tr('Ничего не найдено. Попробуйте другой запрос.') : $tr('Постов пока нет.')) ?></p>
  <?php else: ?>
    <div class="grid grid--list">
      <?php foreach ($posts as $p) { $card($p); } ?>
    </div>

    <?php if (isset($total, $perPage) && $total > $perPage): ?>
      <?php
        $totalPages = (int)ceil($total / $perPage);
        $base = $site->url . '/' . (isset($category) && $category !== '' ? $category . '/' : '');
        $pageUrl = fn(int $n): string => $n <= 1 ? $base : $base . 'page/' . $n . '/';
      ?>
      <nav class="pagination" aria-label="Pages">
        <?php if ($currentPage > 1): ?>
          <a class="pagination__arrow" href="<?= $e($pageUrl($currentPage - 1)) ?>">←</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $currentPage): ?>
            <span class="pagination__num current"><?= $i ?></span>
          <?php else: ?>
            <a class="pagination__num" href="<?= $e($pageUrl($i)) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?>
          <a class="pagination__arrow" href="<?= $e($pageUrl($currentPage + 1)) ?>">→</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>
