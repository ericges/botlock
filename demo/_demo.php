<?php declare(strict_types=1);

/**
 * Control endpoint of the demo panel. prepend.php does not run BOTLOCK
 * for this script, so settings can always be changed or reset, even
 * when they lock the panel itself out.
 *
 * GET /_demo.php?reset=1 restores the defaults (escape hatch).
 */

use GES\Botlock\Demo\Settings;

require_once __DIR__ . '/_lib/Settings.php';

$settings = new Settings(\dirname(__DIR__));

$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['action'] ?? '')
    : (isset($_GET['reset']) ? 'reset' : '');

$notice = null;

try
{
    switch ($action)
    {
        case 'save':
            $settings->save(Settings::fromForm($_POST));
            $notice = 'saved';
            break;

        case 'reset':
            $settings->reset();
            $notice = 'reset';
            break;
    }
}
catch (\Throwable $th)
{
    \http_response_code(500);
    \header('Content-Type: text/plain; charset=utf-8');
    exit('Demo settings could not be changed: ' . $th->getMessage());
}

if ($notice === null) {
    \http_response_code(400);
    \header('Content-Type: text/plain; charset=utf-8');
    exit('Unknown demo action');
}

\header('Location: /?demo=' . $notice, true, 303);
