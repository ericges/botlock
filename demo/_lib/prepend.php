<?php declare(strict_types=1);

/**
 * Demo-only prepend file: configures BOTLOCK through putenv() and then
 * prepends the library exactly like a production install would.
 *
 * Nothing sets BOTLOCK_* via Apache SetEnv, because FastCGI parameters
 * would shadow the values written here. PHP reverts putenv() changes at
 * the end of every request.
 */

$demoRoot = dirname(__DIR__, 2);

putenv('BOTLOCK_ENABLED=yes');
putenv('BOTLOCK_INSTANCE_ID=demo');
putenv('BOTLOCK_STATE_DIR=' . $demoRoot . '/.demo/state');
putenv('BOTLOCK_THREAT_LEVEL_OVERRIDE=2');

require $demoRoot . '/bootstrap.php';

unset($demoRoot);
