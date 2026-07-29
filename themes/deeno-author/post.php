<?php declare(strict_types=1);
/**
 * deeno-author — страница поста. Центрированная колонка чтения, теги, карточка
 * автора внизу, пред./след. по дате. $e/$tr/$readTime приходят из layout.
 */
$categoryManager = new CategoryManager();
$postCategorySlug = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;

// Соседи по дате: строго хронологический порядок (без sticky-первыми).
$chron = $cms->posts();
usort($chron, fn(Post $a, Post $b) => strtotime($b->dateRaw) <=> strtotime($a->dateRaw));
$idx = null;
foreach ($chron as $i => $p) { if ($p->filePath === $post->filePath) { $idx = $i; break; } }
$newer = ($idx !== null && $idx > 0) ? $chron[$idx - 1] : null;
$older = ($idx !== null && $idx < count($chron) - 1) ? $chron[$idx + 1] : null;

$bio = $site->tagline !== '' ? $site->tagline : $site->description;
?>

<article class="entry">
  <header class="entry__head">
    <p class="entry__meta">
      <span><?= $e($post->date()) ?></span>
      <a href="<?= $e($site->url . '/' . $postCategorySlug . '/') ?>"><?= $e($categoryManager->get($postCategorySlug)['title']) ?></a>
      <span><?= $readTime($post) ?>&nbsp;<?= $e($tr('мин')) ?></span>
    </p>
    <h1 class="entry__title"><?= $e($post->title) ?></h1>
  </header>

  <?php if ($post->cover): ?>
    <figure class="entry__cover"><img src="<?= $e($post->coverSrc()) ?>" alt="<?= $e($post->title) ?>"></figure>
  <?php endif; ?>

  <div class="entry__content">
    <?= $post->content() ?>
  </div>

  <?php if (!empty($post->tags)): ?>
    <p class="entry__tags">
      <?php foreach ($post->tags as $tg): ?>
        <a href="<?= $e($site->url . '/tag/' . $tg . '/') ?>">#<?= $e($tg) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</article>

<aside class="author-card">
  <div class="author-card__avatar">
    <?php if ($site->logo !== ''): ?>
      <img src="<?= $e($site->logo) ?>" alt="">
    <?php else: ?>
      <span><?= $e(mb_strtoupper(mb_substr($post->author !== '' ? $post->author : $site->title, 0, 1))) ?></span>
    <?php endif; ?>
  </div>
  <div class="author-card__body">
    <p class="author-card__kicker"><?= $e($tr('Написал')) ?></p>
    <p class="author-card__name"><?= $e($post->author !== '' ? $post->author : $site->title) ?></p>
    <?php if ($bio !== ''): ?><p class="author-card__bio"><?= $e($bio) ?></p><?php endif; ?>
    <?php if (!empty($site->social)): ?>
      <ul class="author-card__social">
        <?php foreach ($site->social as $s): ?>
          <li><a href="<?= $e($s['url']) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $e($s['name']) ?>"><?= SocialIcons::svg($s['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</aside>

<?php if ($newer !== null || $older !== null): ?>
<nav class="prevnext">
  <?php if ($older !== null): ?>
    <a class="prevnext__link prevnext__link--prev" href="<?= $e($older->url()) ?>">
      <span class="prevnext__dir">← <?= $e($tr('Раньше')) ?></span>
      <span class="prevnext__title"><?= $e($older->title) ?></span>
    </a>
  <?php else: ?><span></span><?php endif; ?>
  <?php if ($newer !== null): ?>
    <a class="prevnext__link prevnext__link--next" href="<?= $e($newer->url()) ?>">
      <span class="prevnext__dir"><?= $e($tr('Позже')) ?> →</span>
      <span class="prevnext__title"><?= $e($newer->title) ?></span>
    </a>
  <?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
