<?php
declare(strict_types=1);

/**
 * Standalone-страница ошибки админки (CSRF, 403, 404 без layout).
 * Подключается из adminErrorPage() в admin/helpers.php — оттуда приходят
 * $title, $message, $base, $site и $nonce.
 *
 * @var string $title  @var string $message
 * @var string $base   @var string $site  @var string $nonce
 */

defined('FFC_ADMIN') or exit;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <script nonce="<?= e($nonce) ?>">
    try { document.documentElement.dataset.theme = localStorage.getItem('deeno-theme') || 'light'; } catch (e) {}
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> / <?= e($site) ?></title>
  <link rel="stylesheet" href="<?= e($base) ?>assets/admin.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
</head>
<body class="login-page">
<div class="login-card">
  <h1 class="login-card__title"><?= e($title) ?></h1>
  <div class="alert alert--danger"><?= e($message) ?></div>
  <a class="btn btn--primary btn--block" href="<?= e($base) ?>"><?= e(t('К обзору')) ?></a>
</div>
</body>
</html>
