<?php declare(strict_types=1);

/**
 * The protected area of the demo: BOTLOCK runs before this page (see
 * _lib/prepend.php), so reaching it means the request passed. It shows
 * what PHP received, which for forged requests is what the relay sent.
 */

$e = static fn(?string $value): string => \htmlspecialchars($value ?? '—', \ENT_QUOTES);

$received = [
    'Method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'URI' => $_SERVER['REQUEST_URI'] ?? null,
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
    'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'Accept-Language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
    'X-Forwarded-For' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
    'Time' => \date('H:i:s'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <title>Botlock - Protected page</title>
    <link rel="stylesheet" href="/assets/panel.css?v=<?= \filemtime(\dirname(__DIR__) . '/assets/panel.css') ?>">
</head>
<body data-demo-protected class="protected-page">

<main class="block">
    <h1 class="passed">Passed BOTLOCK</h1>
    <p class="muted">This page is only served after BOTLOCK let the request through. It received:</p>

    <dl class="kv">
        <?php foreach ($received as $name => $value): ?>
            <div><dt><?= $e($name) ?></dt><dd><?= $e($value) ?></dd></div>
        <?php endforeach ?>
    </dl>

    <p><a href="">Reload this page</a></p>
</main>

</body>
</html>
