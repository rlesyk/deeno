<?php declare(strict_types=1);
/** deeno-mag — статическая страница. $e из layout. */
?>
<article class="entry entry--page">
  <header class="entry__head">
    <h1 class="entry__title"><?= $e($post->title) ?></h1>
  </header>
  <div class="entry__content">
    <?= $post->content() ?>
  </div>
</article>
