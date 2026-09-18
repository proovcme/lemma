<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= e(app_theme_color()) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Лемма">
    <title><?= e($title ?? app_title_default()) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= url(app_is_demo_mode() ? '/favicon-demo.svg' : '/favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= url('/pwa/apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= url('/manifest.webmanifest') ?>">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body class="guest is-demo-theme">
<main class="guest-card">
    <?php if ($message = flash('success')): ?>
        <div class="alert alert--success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($message = flash('error')): ?>
        <div class="alert alert--error"><?= e($message) ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
<script src="<?= asset('pwa.js') ?>" defer></script>
</body>
</html>
