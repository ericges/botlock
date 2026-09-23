<?php declare(strict_types=1);

/**
 * Control endpoint of the demo panel. prepend.php does not run BOTLOCK
 * for this script, so settings can always be changed or reset, even
 * when they lock the panel itself out.
 *
 * Form posts are redirected back to the panel; requests accepting JSON
 * (the panel's fetch calls) get the outcome and the stored settings.
 * GET /_demo.php?reset=1 restores the defaults (escape hatch).
 */

use GES\Botlock\Demo\Notices;
use GES\Botlock\Demo\Presets;
use GES\Botlock\Demo\Settings;
use GES\Botlock\Demo\State;

require_once \dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/_lib/Settings.php';
require_once __DIR__ . '/_lib/Presets.php';
require_once __DIR__ . '/_lib/State.php';
require_once __DIR__ . '/_lib/Notices.php';

$settings = new Settings(\dirname(__DIR__));
$state = new State($settings->stateDir(), Settings::INSTANCE_ID);
$wantsJson = \str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

$fail = static function (int $status, string $error) use ($wantsJson): never {
    \http_response_code($status);

    if ($wantsJson) {
        \header('Content-Type: application/json');
        exit(\json_encode(['ok' => false, 'error' => $error]));
    }

    \header('Content-Type: text/plain; charset=utf-8');
    exit($error);
};

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
    $fail(500, 'Demo settings could not be changed: ' . $th->getMessage());
}

if ($notice === null) {
    $fail(400, 'Unknown demo action');
}

if (!$wantsJson) {
    \header('Location: /?demo=' . $notice, true, 303);
    exit;
}

$values = $settings->load();

\header('Content-Type: application/json');
echo \json_encode([
    'ok' => true,
    'notice' => $notice,
    'message' => Notices::message($notice),
    'values' => $values,
    'preset' => Presets::match($values),
    // The rotated secret invalidates the grant: only a page load shows the challenge.
    'reload' => $notice === 'rotated',
], \JSON_UNESCAPED_SLASHES);
