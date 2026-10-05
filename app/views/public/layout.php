<?php /** The public page's bare layout. Data: business, title, content, noindex */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!empty($noindex)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?= e($title) ?> · <?= e($business) ?></title>
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
</head>
<body class="public-body">
    <main class="public-main" id="public-main">
        <?= $content ?>
        <footer class="fs-12 text-muted mt-4 pt-3 border-top" id="public-footer"><?= e($business) ?></footer>
    </main>
</body>
</html>
