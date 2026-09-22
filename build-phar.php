<?php

if (!extension_loaded('phar')) {
    throw new \RuntimeException('The Phar extension is not loaded');
}

$pharFileName = getenv('PHAR_FILENAME') ?: 'botlock.phar';

if (!preg_match('#^[a-zA-Z0-9_.-]+\.phar$#', $pharFileName)) {
    throw new \InvalidArgumentException('Invalid PHAR filename');
}

$pharFile = __DIR__ . DIRECTORY_SEPARATOR . $pharFileName;

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, $pharFileName);

$phar->buildFromDirectory(__DIR__, '#/src/.*\.php$#');
$phar->buildFromDirectory(__DIR__, '#/templates/.*\.php$#');
$phar->buildFromDirectory(__DIR__, '#/translations/.*\.php$#');
$phar->buildFromDirectory(__DIR__, '#/vendor/.*\.(php|json|lock|twig|latte|neon|txt)$#');

$stubPath = __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
if (!file_exists($stubPath)) {
    throw new \RuntimeException('Stub file not found: ' . $stubPath);
}
$rawStub = file_get_contents($stubPath);
$rawStub = trim(preg_replace('/^\s*<\?php\s*/', '', $rawStub));

$stub = <<<STUB
<?php
Phar::mapPhar('$pharFileName');

define('BOTLOCK_PHAR', Phar::running(true) ?: ('phar://' . __FILE__));

$rawStub

__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->compressFiles(Phar::GZ);

echo "botlock.phar created\n";
