<?php declare(strict_types=1);

use GES\Botlock\Demo\Presets;
use GES\Botlock\Demo\Settings;

require_once __DIR__ . '/_lib/Settings.php';
require_once __DIR__ . '/_lib/Presets.php';

$demo = new Settings(\dirname(__DIR__));
$values = $demo->load();
$activePreset = Presets::match($values);

$groups = [];
foreach (Settings::schema() as $key => $field) {
    $groups[$field['group']][$key] = $field;
}

$notices = [
    'saved' => 'Settings saved. They apply from the next request on.',
    'preset' => 'Preset applied. It takes effect from the next request on.',
    'reset' => 'Settings reset to the demo defaults.',
    'cleared' => 'Rate-limit state and cached challenge pages deleted.',
    'rotated' => 'Secret rotated. Your grant is no longer valid, so this page had to challenge you again.',
];
$notice = $notices[$_GET['demo'] ?? ''] ?? null;

$e = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Botlock - Demo</title>
    <link rel="stylesheet" href="/assets/panel.css">
    <script src="/assets/panel.js" defer></script>
</head>
<body>

<div class="container">
    <header>
        <h1>Verification Complete!</h1>
        <p>This page is protected by Botlock security measures.</p>
        <p class="success-message">Your browser has successfully passed the challenge.</p>
        <form method="post" action="?_botlock=reset" class="actions">
            <input type="hidden" name="location" value="<?= $e($_SERVER['REQUEST_URI']) ?>">
            <button type="submit">Reset Botlock Challenge</button>
        </form>
    </header>

    <?php if ($notice): ?>
        <p class="notice"><?= $e($notice) ?></p>
    <?php endif ?>

    <h2>Status</h2>
    <section data-status="/?_botlock=status">
        <dl class="status-grid">
            <div><dt>Effective threat level</dt><dd data-field="threat_level">—</dd></div>
            <div><dt>Global level</dt><dd data-field="threat_level_global">—</dd></div>
            <div><dt>Individual level</dt><dd data-field="threat_level_individual">—</dd></div>
            <div><dt>Individual rate</dt><dd data-field="individual_rate">—</dd></div>
            <div><dt>Crawler verification</dt><dd data-field="crawler_verification">—</dd></div>
            <div><dt>Challenge passed</dt><dd data-field="passed">—</dd></div>
            <div><dt>Fingerprint</dt><dd data-field="subject">—</dd></div>
            <div><dt>User-Agent</dt><dd data-field="user_agent">—</dd></div>
        </dl>
        <pre class="status-headers" data-status-headers hidden></pre>
        <div class="actions">
            <button type="button" data-status-refresh>Refresh</button>
            <label><input type="checkbox" data-status-poll> Refresh every 5 s</label>
            <span class="hint" data-status-updated></span>
        </div>
        <p class="hint">
            Shows <code>?_botlock=status</code>. Every status request passes BOTLOCK's rate
            evaluation and counts toward the individual rate, so polling alone can raise the
            level with low thresholds.
        </p>
    </section>

    <h2>Tools</h2>
    <form method="post" action="/_demo.php" class="actions">
        <button type="submit" name="action" value="clear-state">Clear state</button>
        <button type="submit" name="action" value="rotate-secret">Rotate secret</button>
    </form>
    <div class="actions burst" data-burst="/">
        <label for="burst-count">Requests</label>
        <input type="number" id="burst-count" min="1" max="200" value="20" data-burst-count>
        <button type="button" data-burst-start>Send burst</button>
        <span class="hint" data-burst-result></span>
    </div>
    <?php if ($values['THREAT_LEVEL_OVERRIDE'] !== null): ?>
        <p class="hint warning">
            A threat level override is set, so requests are not rate-evaluated and a burst changes nothing.
            Try the rate-limit sandbox preset.
        </p>
    <?php endif ?>
    <p class="hint">
        <strong>Send burst</strong> requests this page the given number of times, one after another,
        then refreshes the status. <strong>Clear state</strong> deletes the rate-limit counters and cached challenge pages in
        <code>.demo/state/</code>. <strong>Rotate secret</strong> deletes the generated signing
        secret; BOTLOCK creates a new one and every issued grant becomes invalid.
    </p>

    <h2>Presets</h2>
    <form method="post" action="/_demo.php" class="presets">
        <input type="hidden" name="action" value="preset">
        <?php foreach (Presets::all() as $id => $preset): ?>
            <button type="submit" name="preset" value="<?= $e($id) ?>"
                    class="preset<?= $id === $activePreset ? ' active' : '' ?>"
                    <?= $id === $activePreset ? 'aria-current="true"' : '' ?>>
                <strong><?= $e($preset['label']) ?></strong>
                <span><?= $e($preset['description']) ?></span>
            </button>
        <?php endforeach ?>
    </form>
    <p class="hint">
        <?= $activePreset ? 'Active preset: ' . $e(Presets::all()[$activePreset]['label']) . '.' : 'Custom settings (no preset matches).' ?>
    </p>

    <h2>Settings</h2>
    <p class="hint">
        Applied to every request through <code>putenv()</code> before BOTLOCK is prepended.
        Empty fields use the library default. If the settings lock you out, open
        <a href="/_demo.php?reset=1"><code>/_demo.php?reset=1</code></a> to restore the demo defaults.
    </p>

    <form method="post" action="/_demo.php">

        <?php foreach ($groups as $group => $fields): ?>
            <fieldset>
                <legend><?= $e($group) ?></legend>
                <div class="fields">
                    <?php foreach ($fields as $key => $field):
                        $id = 'setting-' . $key;
                        $name = 'settings[' . $key . ']';
                        $value = $values[$key];
                        ?>
                        <div class="field">
                            <label for="<?= $e($id) ?>"><?= $e($field['label']) ?></label>

                            <?php if ($field['type'] === Settings::TYPE_INT): ?>
                                <input type="number" min="0" id="<?= $e($id) ?>" name="<?= $e($name) ?>"
                                       value="<?= $e((string) $value) ?>" placeholder="<?= $e($field['default']) ?>">

                            <?php elseif ($field['type'] === Settings::TYPE_LIST): ?>
                                <textarea id="<?= $e($id) ?>" name="<?= $e($name) ?>"
                                          placeholder="one entry per line"><?= $e(\implode("\n", $value ?? [])) ?></textarea>
                                <label class="inherit">
                                    <input type="checkbox" name="default[<?= $e($key) ?>]" value="1" <?= $value === null ? 'checked' : '' ?>>
                                    Library default
                                </label>

                            <?php else:
                                $options = $field['type'] === Settings::TYPE_BOOL ? ['yes', 'no'] : $field['options'];
                                ?>
                                <select id="<?= $e($id) ?>" name="<?= $e($name) ?>">
                                    <option value="">Library default</option>
                                    <?php foreach ($options as $option): ?>
                                        <option value="<?= $e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= $e($option) ?></option>
                                    <?php endforeach ?>
                                </select>
                            <?php endif ?>

                            <p class="hint">
                                Default: <?= $e($field['default']) ?><?= isset($field['help']) ? ' · ' . $e($field['help']) : '' ?>
                                <br><code>BOTLOCK_<?= $e($key) ?></code>
                            </p>
                        </div>
                    <?php endforeach ?>
                </div>
            </fieldset>
        <?php endforeach ?>

        <div class="actions">
            <button type="submit" name="action" value="save" class="primary">Save settings</button>
            <button type="submit" name="action" value="reset" formnovalidate>Reset to demo defaults</button>
        </div>
    </form>
</div>

</body>
</html>
