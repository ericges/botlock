<?php declare(strict_types=1);

use GES\Botlock\Demo\Identity;
use GES\Botlock\Demo\Notices;
use GES\Botlock\Demo\Presets;
use GES\Botlock\Demo\Settings;

require_once __DIR__ . '/_lib/Settings.php';
require_once __DIR__ . '/_lib/Presets.php';
require_once __DIR__ . '/_lib/Notices.php';
require_once __DIR__ . '/_lib/Identity.php';

$demo = new Settings(\dirname(__DIR__));
$values = $demo->load();
$activePreset = Presets::match($values);

$groups = [];
foreach (Settings::schema() as $key => $field) {
    $groups[$field['group']][$key] = $field;
}

$changedIn = static fn(array $fields): int => \count(\array_filter(\array_keys($fields), static fn(string $key): bool => $values[$key] !== null));

$notice = Notices::message(\is_string($_GET['demo'] ?? null) ? $_GET['demo'] : null);

$e = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES);

$secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$origin = ($secure ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'botlock.ddev.site');
$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$nonce = '00000000-0000-4000-8000-000000000000';

$recipes = [
    [
        'title' => 'How BOTLOCK sees curl',
        'command' => "curl -sk '{$origin}/protected/?_botlock=status'",
        'hint' => 'Each client gets its own fingerprint (subject) and individual rate.',
    ],
    [
        'title' => 'Get challenged',
        'command' => "curl -sk -o /dev/null -w '%{http_code}\\n' '{$origin}/protected/'",
        'hint' => '401 while the effective threat level is 1 to 3, 429 at level 4, 200 at level 0.',
    ],
    [
        'title' => 'Claim to be Googlebot',
        'command' => "curl -sk -A '{$googlebot}' '{$origin}/protected/?_botlock=status'",
        'hint' => 'The reverse-DNS check fails for your address: crawler_verification is failed or unverified, and the request is challenged.',
    ],
    [
        'title' => 'Compare challenge requirements',
        'command' => "curl -sk -H 'Botlock-Nonce: {$nonce}' -A 'AhrefsBot/7.0' '{$origin}/protected/?_botlock=challenge'\n"
            . "curl -sk -H 'Botlock-Nonce: {$nonce}' -A 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0' '{$origin}/protected/?_botlock=challenge'",
        'hint' => 'int names what the client has to do first: none (the proof of work is included), click or slider (it follows from POST ?_botlock=challenge). Crawlers that are not good bots (curl itself included) always have to interact, and their max is multiplied by BOTLOCK_CRAWLER_FACTOR. The nonce header is required; any UUID works.',
    ],
    [
        'title' => 'Forward a client address',
        'command' => "ddev exec curl -s -H 'X-Forwarded-For: 203.0.113.7' 'http://localhost/protected/?_botlock=status'",
        'hint' => 'Runs inside the container: the DDEV router replaces X-Forwarded-For, but loopback is a trusted proxy. The subject changes with the forwarded address.',
    ],
    [
        'title' => 'Reset the session',
        'command' => "curl -sk -X POST '{$origin}/protected/?_botlock=reset'",
        'hint' => 'Answers JSON without a location field; the Reset challenge tool redirects instead.',
    ],
    [
        'title' => 'Restore the demo defaults',
        'command' => "curl -sk '{$origin}/_demo.php?reset=1'",
        'hint' => 'The escape hatch; never protected by BOTLOCK.',
    ],
];
$demoData = [
    'schema' => Settings::schema(),
    'values' => $values,
    'presets' => \array_map(static fn(array $preset): array => \array_diff_key($preset, ['values' => true]), Presets::all()),
    'preset' => $activePreset,
    'identities' => Identity::presets(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <title>Botlock - Demo</title>
    <link rel="stylesheet" href="/assets/panel.css?v=<?= \filemtime(__DIR__ . '/assets/panel.css') ?>">
    <script src="/assets/panel.js?v=<?= \filemtime(__DIR__ . '/assets/panel.js') ?>" defer></script>
</head>
<body>

<header class="topbar">
    <div class="brand">BOTLOCK <span>demo</span></div>
    <div class="topbar-status" data-chip aria-live="polite">
        <div class="topbar-cell">
            <span class="topbar-label">Identity</span>
            <span class="topbar-value" data-chip-identity>—</span>
        </div>
        <div class="topbar-cell topbar-level" data-chip-level-cell title="Effective threat level of the selected identity">
            <span class="topbar-label">Level</span>
            <span class="topbar-dot" aria-hidden="true"></span>
            <span class="topbar-value"><strong data-chip-level>—</strong> <span data-chip-level-text>status pending</span></span>
        </div>
        <div class="topbar-cell" title="Whether the selected identity holds a grant from a solved challenge">
            <span class="topbar-label">Grant</span>
            <span class="topbar-value" data-chip-grant>—</span>
        </div>
    </div>
</header>

<?php if ($notice): ?>
    <p class="banner" role="status"><?= $e($notice) ?></p>
<?php endif ?>

<main class="panes">
    <div class="pane pane-settings">
        <section class="block" data-status>
            <div class="block-head">
                <h2>Status</h2>
                <div class="block-tools">
                    <label class="toggle"><input type="checkbox" data-status-poll> every 5 s</label>
                    <button type="button" class="icon" data-status-refresh title="Refresh status" aria-label="Refresh status">⟳</button>
                </div>
            </div>

            <div class="meters">
                <?php foreach (['threat_level' => 'Effective', 'threat_level_global' => 'Global', 'threat_level_individual' => 'Individual'] as $field => $label): ?>
                    <div class="meter-row<?= $field === 'threat_level' ? ' is-main' : '' ?>" data-meter="<?= $e($field) ?>">
                        <span class="meter-label"><?= $e($label) ?></span>
                        <span class="meter" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                        <span class="meter-value" data-meter-value>—</span>
                        <span class="meter-text muted" data-meter-text></span>
                    </div>
                <?php endforeach ?>
                <p class="rate">
                    Rate <strong data-rate>—</strong> / <span data-rate-window>—</span> s
                    <span class="muted" data-rate-thresholds></span>
                </p>
            </div>

            <div class="history">
                <svg data-history viewBox="0 0 240 40" preserveAspectRatio="none" role="img"
                     aria-label="Individual rate and effective level of the last status samples"></svg>
                <p class="muted"><span data-history-count>0</span> samples · bar height = individual rate, color = effective level</p>
            </div>

            <dl class="kv">
                <div><dt>crawler_verification</dt><dd data-field="crawler_verification">—</dd></div>
                <div><dt>passed</dt><dd data-field="passed">—</dd></div>
                <div><dt>grant_level</dt><dd data-field="grant_level">—</dd></div>
                <div><dt>subject</dt><dd data-field="subject">—</dd></div>
                <div><dt>user_agent</dt><dd data-field="user_agent">—</dd></div>
            </dl>

            <pre class="headers" data-status-headers hidden></pre>
            <p class="muted" data-status-updated></p>
            <p class="muted">
                Status requests pass BOTLOCK's rate evaluation and count toward the individual rate.
            </p>
        </section>

        <form method="post" action="/_demo.php" class="block" data-action-form>
            <input type="hidden" name="action" value="preset">
            <h2>Presets</h2>
            <div class="segmented" role="group" aria-label="Presets">
                <?php foreach (Presets::all() as $id => $preset): ?>
                    <button type="submit" name="preset" value="<?= $e($id) ?>" title="<?= $e($preset['description']) ?>"
                            aria-pressed="<?= $id === $activePreset ? 'true' : 'false' ?>"><?= $e($preset['label']) ?></button>
                <?php endforeach ?>
            </div>
            <p class="muted" data-preset-label>
                <?= $activePreset ? $e(Presets::all()[$activePreset]['description']) : 'Custom settings: no preset matches.' ?>
            </p>
        </form>

        <form method="post" action="/_demo.php" id="demo-reset" data-action-form hidden>
            <input type="hidden" name="action" value="reset">
        </form>

        <form method="post" action="/_demo.php" data-settings-form>
            <input type="hidden" name="action" value="save">

            <?php foreach ($groups as $group => $fields):
                $changed = $changedIn($fields);
                ?>
                <details class="group" data-group="<?= $e($group) ?>" <?= $group === 'Mode' || $changed > 0 ? 'open' : '' ?>>
                    <summary>
                        <span><?= $e($group) ?></span>
                        <span class="muted" data-group-count><?= \count($fields) ?><?= $changed ? ' · ' . $changed . ' set' : '' ?></span>
                    </summary>

                    <?php foreach ($fields as $key => $field):
                        $id = 'setting-' . $key;
                        $name = 'settings[' . $key . ']';
                        $value = $values[$key];
                        ?>
                        <div class="field<?= $value !== null ? ' is-set' : '' ?>" data-key="<?= $e($key) ?>">
                            <label for="<?= $e($id) ?>"><?= $e($field['label']) ?></label>

                            <div class="control">
                                <?php if ($field['type'] === Settings::TYPE_INT): ?>
                                    <input type="number" min="0" id="<?= $e($id) ?>" name="<?= $e($name) ?>"
                                           value="<?= $e((string) $value) ?>" placeholder="<?= $e($field['default']) ?>">

                                <?php elseif ($field['type'] === Settings::TYPE_LIST): ?>
                                    <textarea id="<?= $e($id) ?>" name="<?= $e($name) ?>" rows="2"
                                              placeholder="one entry per line"><?= $e(\implode("\n", $value ?? [])) ?></textarea>
                                    <label class="inherit">
                                        <input type="checkbox" name="default[<?= $e($key) ?>]" value="1" <?= $value === null ? 'checked' : '' ?>>
                                        library default
                                    </label>

                                <?php else:
                                    $options = $field['type'] === Settings::TYPE_BOOL ? ['yes', 'no'] : $field['options'];
                                    ?>
                                    <select id="<?= $e($id) ?>" name="<?= $e($name) ?>">
                                        <option value="">default (<?= $e($field['default']) ?>)</option>
                                        <?php foreach ($options as $option): ?>
                                            <option value="<?= $e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= $e($option) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                <?php endif ?>
                            </div>

                            <div class="field-state">
                                <span class="marker" title="Set: differs from the library default"></span>
                                <button type="button" class="icon small" data-field-reset
                                        title="Reset to the library default" aria-label="Reset <?= $e($field['label']) ?> to the library default">↺</button>
                            </div>

                            <p class="field-meta">
                                <code>BOTLOCK_<?= $e($key) ?></code> · default <?= $e($field['default']) ?><?= isset($field['help']) ? ' · ' . $e($field['help']) : '' ?>
                            </p>
                        </div>
                    <?php endforeach ?>
                </details>
            <?php endforeach ?>

            <div class="savebar" data-savebar>
                <span class="muted" data-savebar-text>Settings are stored in <code>.demo/settings.json</code>.</span>
                <button type="button" data-discard hidden>Discard</button>
                <button type="submit" form="demo-reset" title="Remove .demo/settings.json">Demo defaults</button>
                <button type="submit" class="primary">Save</button>
            </div>
        </form>

        <details class="block recipes">
            <summary><h2>curl recipes</h2><span class="muted"><?= \count($recipes) ?></span></summary>
            <p class="muted">
                For the paths a browser cannot take. <code>-k</code> skips certificate checks and can be
                dropped when DDEV's mkcert CA is trusted.
            </p>
            <?php foreach ($recipes as $recipe): ?>
                <div class="recipe">
                    <div class="recipe-head">
                        <strong><?= $e($recipe['title']) ?></strong>
                        <button type="button" class="small" data-copy>Copy</button>
                    </div>
                    <pre><code><?= $e($recipe['command']) ?></code></pre>
                    <p class="muted"><?= $e($recipe['hint']) ?></p>
                </div>
            <?php endforeach ?>
        </details>
    </div>

    <aside class="pane pane-live">
        <section class="block">
            <div class="block-head">
                <h2>Tools</h2>
                <label class="toggle" title="Reload the frame after settings or tools change">
                    <input type="checkbox" data-frame-auto checked> auto-reload frame
                </label>
            </div>
            <div class="tool-row">
                <div class="tool-group" data-burst>
                    <label for="burst-count">Burst</label>
                    <input type="number" id="burst-count" min="1" max="200" value="20" data-burst-count>
                    <button type="button" data-burst-start>Send</button>
                </div>
                <form method="post" action="/_demo.php" class="tool-group" data-action-form>
                    <button type="submit" name="action" value="clear-state" title="Delete rate-limit counters and cached challenge pages">Clear state</button>
                    <button type="submit" name="action" value="rotate-secret" title="Delete the signing secret; every grant becomes invalid">Rotate secret</button>
                </form>
                <form method="post" action="/protected/?_botlock=reset" class="tool-group" data-reset-form>
                    <input type="hidden" name="location" value="<?= $e($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="primary" title="Clear the BOTLOCK session via ?_botlock=reset">Reset challenge</button>
                </form>
            </div>
            <p class="muted tool-result" data-burst-result></p>
            <p class="muted warning" data-burst-override <?= $values['THREAT_LEVEL_OVERRIDE'] === null ? 'hidden' : '' ?>>
                An override is set, so requests are not rate-evaluated. Try the rate-limit sandbox.
            </p>
        </section>

        <section class="block identity" data-identity>
            <div class="block-head identity-head">
                <h2>Identity</h2>
                <select data-identity-preset aria-label="Identity">
                    <?php foreach (Identity::presets() as $key => $preset): ?>
                        <option value="<?= $e($key) ?>"><?= $e($preset['label']) ?></option>
                    <?php endforeach ?>
                    <option value="custom">Custom</option>
                </select>
                <span class="muted identity-note" data-identity-note></span>
                <button type="submit" form="identity-form" class="primary">Apply</button>
            </div>
            <form class="identity-fields" id="identity-form" data-identity-form>
                <label for="identity-ua">User-Agent</label>
                <input type="text" id="identity-ua" name="ua" class="wide" placeholder="empty: this browser's" spellcheck="false">

                <label for="identity-ip" title="Sent as X-Forwarded-For; loopback is a trusted proxy">Client IP</label>
                <input type="text" id="identity-ip" name="ip" placeholder="X-Forwarded-For, empty: loopback" spellcheck="false"
                       title="Sent as X-Forwarded-For; loopback is a trusted proxy">

                <label for="identity-lang">Language</label>
                <input type="text" id="identity-lang" name="lang" placeholder="Accept-Language, empty: none" spellcheck="false">

                <label for="identity-headers">Headers</label>
                <textarea id="identity-headers" name="headers" class="wide" rows="1" placeholder="Name: value, one per line" spellcheck="false"></textarea>
            </form>
        </section>

        <section class="block frame-block" data-frame-block>
            <div class="block-head">
                <div class="tabs" role="tablist">
                    <button type="button" role="tab" aria-selected="true" aria-controls="panel-frame" data-tab="frame">Protected page</button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="panel-log" data-tab="log">Request log <span class="muted" data-log-count></span></button>
                </div>
                <div class="block-tools">
                    <span class="badge" data-frame-state>loading</span>
                    <button type="button" class="icon" data-frame-reload title="Reload the frame" aria-label="Reload the frame">⟳</button>
                    <a class="button icon" data-frame-open href="/protected/" target="_blank" rel="noopener"
                       title="Open in a new tab" aria-label="Open the protected page in a new tab">↗</a>
                </div>
            </div>

            <div class="tab-panel" id="panel-frame" role="tabpanel" data-panel="frame">
                <p class="frame-url"><code data-frame-url>/protected/</code></p>
                <iframe data-frame src="/protected/" title="Protected page as BOTLOCK serves it"></iframe>
            </div>

            <div class="tab-panel log-panel" id="panel-log" role="tabpanel" data-panel="log" hidden>
                <div class="log-toolbar">
                    <label class="toggle"><input type="checkbox" data-log-all> include status, burst and reset requests</label>
                    <form method="post" action="/_demo.php" data-action-form>
                        <button type="submit" name="action" value="clear-log" class="small">Clear log</button>
                    </form>
                </div>
                <p class="muted" data-log-note hidden>
                    "This browser" requests the protected page directly, so they are not logged. Pick a forged identity to see its exchanges.
                </p>
                <p class="muted" data-log-empty>No relayed requests yet.</p>
                <div class="log" data-log></div>
            </div>
        </section>
    </aside>
</main>

<div class="toasts" aria-live="polite" data-toasts></div>

<script type="application/json" id="demo-data"><?= \json_encode($demoData, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) ?></script>
</body>
</html>
