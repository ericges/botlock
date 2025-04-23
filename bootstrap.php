<?php

if (!filter_var(getenv('BOTLOCK_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
    return;
}

$displayErrors = ini_get('display_errors');
ini_set('display_errors', 'stderr');

require_once __DIR__ . '/vendor/autoload.php';

\GES\Botlock\Kernel::boot()->handleRequest();

ini_set('display_errors', $displayErrors);
