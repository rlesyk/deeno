<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/**
 * Просмотр одной сохранённой версии материала с возможностью восстановления.
 * Слева — текст версии, справа — её параметры и разница с текущим состоянием.
 *
 * @var string $kind  'posts' или 'pages'  @var string $file  @var string $revId
 * @var array $revMeta  @var string $revBody  @var array $current  @var array $revList
 * @var string $adminBase  @var Security $security
 */
$editUrl = $adminBase . $kind . '/edit/?file=' . urlencode($file);
$curMeta = (array)($current['meta'] ?? []);
$curBody = (string)($current['body'] ?? '');

// Что именно отличается от текущего состояния — чтобы не гадать перед откатом
$diffFields = [];
foreach (['title' => t('Заголовок'), 'slug' => 'slug', 'status' => t('Статус'),
          'category' => t('Категория'), 'excerpt' => t('Описание')] as $key => $label) {
    $was = trim((string)($revMeta[$key] ?? ''));
    $now = trim((string)($curMeta[$key] ?? ''));
    if ($was !== $now) {
        $diffFields[] = ['label' => $label, 'was' => $was, 'now' => $now];
    }
}
$bodyChanged = trim($revBody) !== trim($curBody);
?>

<div class="filters">
  <a class="btn btn--secondary" href="<?= e($editUrl) ?>"><?= e(t('← К редактору')) ?></a>
</div>

<div class="editor">
  <div class="editor__main">
    <div class="card">
      <h3 style="margin-top:0"><?= e((string)($revMeta['title'] ?? t('Без заголовка'))) ?></h3>
      <?php // Текст версии как есть — редактировать здесь нечего, только смотреть ?>
      <textarea class="editor__content" rows="24" readonly
                style="width:100%"><?= e($revBody) ?></textarea>
    </div>
  </div>

  <aside class="editor__side">
    <div class="card">
      <?php if ($diffFields === [] && !$bodyChanged): ?>
        <p class="muted" style="margin-top:0"><?= e(t('Эта версия совпадает с текущей.')) ?></p>
      <?php else: ?>
        <p class="muted" style="margin-top:0"><?= e(t('Отличия от текущего состояния:')) ?></p>
        <ul class="rev-diff">
          <?php if ($bodyChanged): ?>
            <li><strong><?= e(t('Текст')) ?></strong> — <?= e(t('отличается')) ?></li>
          <?php endif; ?>
          <?php foreach ($diffFields as $d): ?>
            <li>
              <strong><?= e($d['label']) ?></strong><br>
              <span class="rev-diff__was"><?= e($d['was'] !== '' ? $d['was'] : '—') ?></span>
              →
              <span class="rev-diff__now"><?= e($d['now'] !== '' ? $d['now'] : '—') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" action="<?= e($adminBase . $kind . '/restore/') ?>">
        <?= $security->csrfField() ?>
        <input type="hidden" name="file" value="<?= e($file) ?>">
        <input type="hidden" name="rev" value="<?= e($revId) ?>">
        <button type="submit" class="btn btn--primary" style="width:100%"><?= e(t('Восстановить эту версию')) ?></button>
      </form>
      <?php // Откат обратим: текущее состояние тоже уходит в историю ?>
      <p class="muted" style="margin-bottom:0;font-size:13px">
        <?= e(t('Текущее состояние сохранится в истории — откат можно отменить.')) ?>
      </p>
    </div>

    <?php if (count($revList) > 1): ?>
    <div class="card">
      <p class="muted" style="margin-top:0"><?= e(t('Другие версии')) ?></p>
      <ul class="rev-list">
        <?php foreach ($revList as $rev): ?>
          <?php if ($rev['id'] === $revId) continue; ?>
          <li class="rev-list__item">
            <a href="<?= e($adminBase . $kind . '/revision/?file=' . urlencode($file) . '&rev=' . urlencode($rev['id'])) ?>">
              <span class="rev-list__time"><?= e(date('d.m.Y H:i', (int)$rev['time'])) ?></span>
              <span class="rev-list__meta">
                <?php if ($rev['author'] !== ''): ?><?= e($rev['author']) ?> · <?php endif; ?>
                <?= e(number_format($rev['size'] / 1024, 1, '.', ' ')) ?> <?= e(t('КБ')) ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </aside>
</div>
