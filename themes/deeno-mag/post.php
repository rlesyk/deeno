<?php declare(strict_types=1);
/**
 * deeno-mag — страница поста. Широкий герой-обложка, байлайн со временем
 * чтения, теги, блок «Читайте также». $e/$tr/$readTime/$categoryManager — из layout.
 */
$postCategorySlug = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;
?>

<article class="entry">
  <header class="entry__head">
    <a class="entry__cat" href="<?= $e($site->url . '/' . $postCategorySlug . '/') ?>"><?= $e($categoryManager->get($postCategorySlug)['title']) ?></a>
    <h1 class="entry__title"><?= $e($post->title) ?></h1>
    <p class="entry__meta">
      <?php if ($post->author): ?><span class="entry__author"><?= $e($post->author) ?></span><?php endif; ?>
      <span><?= $e($post->date()) ?></span>
      <span><?= $readTime($post) ?>&nbsp;<?= $e($tr('мин')) ?></span>
    </p>
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

<?php
$related = $cms->related($post, 3);
if (!empty($related)):
?>
<aside class="related">
  <h2 class="related__title"><?= $e($tr('Читайте также')) ?></h2>
  <div class="grid">
    <?php foreach ($related as $r): $rSlug = $r->category !== '' ? $r->category : Post::DEFAULT_CATEGORY; ?>
      <article class="card">
        <?php if ($r->cover): ?>
          <a class="card__thumb" href="<?= $e($r->url()) ?>"><img src="<?= $e($r->coverSrc()) ?>" alt="<?= $e($r->title) ?>" loading="lazy"></a>
        <?php endif; ?>
        <div class="card__body">
          <p class="card__meta">
            <a class="card__cat" href="<?= $e($site->url . '/' . $rSlug . '/') ?>"><?= $e($categoryManager->get($rSlug)['title']) ?></a>
            <span><?= $e($r->date()) ?></span>
          </p>
          <h3 class="card__title"><a href="<?= $e($r->url()) ?>"><?= $e($r->title) ?></a></h3>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</aside>
<?php endif; ?>
