<?php declare(strict_types=1);

/**
 * Demo-only prepend file: configures BOTLOCK through putenv() from the
 * settings chosen in the control panel, then prepends the library
 * exactly like a production install would.
 *
 * Nothing sets BOTLOCK_* via Apache SetEnv, because FastCGI parameters
 * would shadow the values written here. PHP reverts putenv() changes at
 * the end of every request.
 */

require_once __DIR__ . '/Settings.php';

(static function (): void {
    $demoRoot = \dirname(__DIR__, 2);
    $settings = new \GES\Botlock\Demo\Settings($demoRoot);

    \putenv('BOTLOCK_ENABLED=yes');
    \putenv('BOTLOCK_INSTANCE_ID=' . \GES\Botlock\Demo\Settings::INSTANCE_ID);
    \putenv('BOTLOCK_STATE_DIR=' . $settings->stateDir());

    // The control endpoint must stay reachable whatever the settings are.
    if (($_SERVER['SCRIPT_NAME'] ?? '') === '/_demo.php') {
        return;
    }

    foreach (\GES\Botlock\Demo\Settings::toEnv($settings->load()) as $name => $value) {
        \putenv($name . '=' . $value);
    }

    require $demoRoot . '/bootstrap.php';
})();
