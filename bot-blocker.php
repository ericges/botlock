<?php

if (!filter_var(getenv('GES_BOTLOCK_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

\GES\Botlock\Kernel::boot()->handleRequest();
