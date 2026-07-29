<?php declare(strict_types=1);
/**
 * deeno-author — главная и списки.
 * Главная (без рубрики/тега/поиска, стр. 1) — герой автора + хронологический
 * таймлайн по годам + облако меток. Рубрика/тег/поиск — таймлайн-список.
 * $e, $tr, $readTime приходят из layout.php.
 */
$currentPage = $page ?? 1;
$categoryManager = new CategoryManager();

$isHome = !isset($searchQuery) && !isset($tag)
    && (!isset($category) || $category === '') && $currentPage === 1;

// Строка таймлайна: дата · заголовок · время чтения · рубрика.
$row = function (Post $p) use ($e, $site, $tr, $readTime, $categoryManager): void {
    $slug = $p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY;
    ?>
    <a class="tl__item" href="<?= $e($p->url()) ?>">
      <span class="tl__date"><?= $e($p->date('d.m')) ?></span>
      <span class="tl__main">
        <span class="tl__title"><?= $e($p->title) ?></span>
        <span class="tl__meta"><?= $e($categoryManager->get($slug)['title']) ?> · <?= $readTime($p) ?>&nbsp;<?= $e($tr('мин')) ?></span>
      </span>
      <span class="tl__arrow">→</span>
    </a>
    <?php
};

// Таймлайн: посты строго по дате (без sticky-первыми), сгруппированы по году.
$timeline = function (array $list) use ($row): void {
    usort($list, fn(Post $a, Post $b) => strtotime($b->dateRaw) <=> strtotime($a->dateRaw));
    $curYear = null;
    echo '<div class="tl">';
    foreach ($list as $p) {
        $y = date('Y', strtotime($p->dateRaw) ?: time());
        if ($y !== $curYear) {
            $curYear = $y;
            echo '<div class="tl__year">' . htmlspecialchars($y, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $row($p);
    }
    echo '</div>';
};
?>

<?php if ($isHome): ?>
  <?php
    $all = $cms->posts();
    // Облако меток: частоты по всем постам.
    $tagCounts = [];
    foreach ($all as $p) {
        foreach ($p->tags as $t) { $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1; }
    }
    arsort($tagCounts);
    $maxTag = $tagCounts ? max($tagCounts) : 1;
    $bio = $site->tagline !== '' ? $site->tagline : $site->description;
  ?>

  <section class="hero">
    <div class="hero__avatar">
      <?php if ($site->logo !== ''): ?>
        <img src="<?= $e($site->logo) ?>" alt="">
      <?php else: ?>
        <span><?= $e(mb_strtoupper(mb_substr($site->title, 0, 1))) ?></span>
      <?php endif; ?>
    </div>
    <h1 class="hero__name"><?= $e($site->title) ?></h1>
    <?php if ($bio !== ''): ?><p class="hero__bio"><?= $e($bio) ?></p><?php endif; ?>
    <?php if (!empty($site->social)): ?>
      <ul class="hero__social">
        <?php foreach ($site->social as $s): ?>
          <li><a href="<?= $e($s['url']) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $e($s['name']) ?>"><?= SocialIcons::svg($s['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if (empty($all)): ?>
    <p class="list-empty"><?= $e($tr('Постов пока нет.')) ?></p>
  <?php else: ?>
    <div class="home">
      <div class="home__main"><?php $timeline($all); ?></div>
      <?php if (!empty($tagCounts)): ?>
        <aside class="home__aside">
          <h2 class="aside__title"><?= $e($tr('Метки')) ?></h2>
          <div class="cloud">
            <?php foreach ($tagCounts as $t => $n): $w = 0.85 + 0.5 * ($n / $maxTag); ?>
              <a class="cloud__tag" style="font-size: <?= number_format($w, 2) ?>rem" href="<?= $e($site->url . '/tag/' . $t . '/') ?>">#<?= $e($t) ?></a>
            <?php endforeach; ?>
          </div>
        </aside>
      <?php endif; ?>
    </div>
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
    <?php $timeline($posts); ?>

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
