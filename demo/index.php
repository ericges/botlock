<?php declare(strict_types=1);

use GES\Botlock\Demo\Settings;

require_once __DIR__ . '/_lib/Settings.php';

$demo = new Settings(\dirname(__DIR__));
$values = $demo->load();

$groups = [];
foreach (Settings::schema() as $key => $field) {
    $groups[$field['group']][$key] = $field;
}

$notices = [
    'saved' => 'Settings saved. They apply from the next request on.',
    'reset' => 'Settings reset to the demo defaults.',
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
