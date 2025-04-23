<?php

if (!filter_var(getenv('BOTLOCK_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
    return;
}

$displayErrors = ini_get('display_errors');
ini_set('display_errors', '0');

define('BOTLOCK_ROOT', defined('BOTLOCK_PHAR') ? BOTLOCK_PHAR : __DIR__);

require_once BOTLOCK_ROOT . '/vendor/autoload.php';

\GES\Botlock\Kernel::boot(BOTLOCK_ROOT)
    ->handleRequest(\GES\Botlock\Http\Request::fromGlobals());

ini_set('display_errors', $displayErrors);
