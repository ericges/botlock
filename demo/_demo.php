<?php declare(strict_types=1);

/**
 * Control endpoint of the demo panel. prepend.php does not run BOTLOCK
 * for this script, so settings can always be changed or reset, even
 * when they lock the panel itself out.
 *
 * GET /_demo.php?reset=1 restores the defaults (escape hatch).
 */

use GES\Botlock\Demo\Presets;
use GES\Botlock\Demo\Settings;
use GES\Botlock\Demo\State;

require_once \dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/_lib/Settings.php';
require_once __DIR__ . '/_lib/Presets.php';
require_once __DIR__ . '/_lib/State.php';

$settings = new Settings(\dirname(__DIR__));
$state = new State($settings->stateDir(), Settings::INSTANCE_ID);

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

        case 'preset':
            if (null !== ($values = Presets::values((string) ($_POST['preset'] ?? '')))) {
                $settings->save($values);
                $notice = 'preset';
            }
            break;

        case 'reset':
            $settings->reset();
            $notice = 'reset';
            break;

        case 'clear-state':
            $state->clear();
            $notice = 'cleared';
            break;

        case 'rotate-secret':
            $state->rotateSecret();
            $notice = 'rotated';
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
