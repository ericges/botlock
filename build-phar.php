<?php

if (!\extension_loaded('phar')) {
    throw new \RuntimeException('The Phar extension is not loaded');
}

$pharFileName = getenv('PHAR_FILENAME') ?: 'botlock.phar';

if (!\preg_match('#^[a-zA-Z0-9_.-]+\.phar$#', $pharFileName)) {
    throw new \InvalidArgumentException('Invalid PHAR filename');
}

$pharFile = __DIR__ . DIRECTORY_SEPARATOR . $pharFileName;

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, $pharFileName);

$phar->buildFromDirectory(__DIR__, '#/src/.*\.php$#');
$phar->buildFromDirectory(__DIR__, '#/assets/.*$#');
$phar->buildFromDirectory(__DIR__, '#/vendor/.*\.(php|json|lock|twig|latte|neon|txt)$#');

$stub = <<<STUB
<?php
Phar::mapPhar('$pharFileName');
require 'phar://$pharFileName/vendor/autoload.php';
\GES\Botlock\Kernel::boot()->handleRequest();
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->compressFiles(Phar::GZ);

echo "botlock.phar erstellt\n";
