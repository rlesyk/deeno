<?php declare(strict_types=1);
/** deeno-mag — 404. $e/$tr из layout. */
?>
<div class="notfound">
  <p class="notfound__code">404</p>
  <h1 class="notfound__title"><?= $e($tr('Такой страницы нет')) ?></h1>
  <p class="notfound__text"><?= $e($tr('Возможно, материал был удалён или в адресе опечатка.')) ?></p>
  <a class="notfound__home" href="<?= $e($site->url . '/') ?>"><?= $e($tr('← На главную')) ?></a>
</div>
