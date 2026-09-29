<?php declare(strict_types=1);

namespace GES\Botlock\Demo;

/**
 * BOTLOCK_* settings chosen in the demo control panel, persisted as JSON
 * outside the docroot and exported to the environment by prepend.php.
 *
 * A null value means "library default": the variable is not set at all,
 * which is different from setting it empty (see BOTLOCK_TRUSTED_PROXIES).
 * Validation is type-only on purpose, so semantically broken values can
 * be used to demonstrate BOTLOCK_FAIL_OPEN.
 */
final class Settings
{
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOL = 'bool';
    public const TYPE_ENUM = 'enum';
    public const TYPE_LIST = 'list';

    /** BOTLOCK_INSTANCE_ID of the demo; names the files in the state directory. */
    public const INSTANCE_ID = 'demo';

    /**
     * Settings used while no settings file exists: rate-based levels with
     * low thresholds, so normal clicking stays calm but a request burst
     * escalates visibly.
     */
    public const DEFAULTS = [
        'LEVEL_1_THRESHOLD_INDIVIDUAL' => '10',
        'LEVEL_2_THRESHOLD_INDIVIDUAL' => '20',
        'LEVEL_3_THRESHOLD_INDIVIDUAL' => '30',
        'LEVEL_4_THRESHOLD_INDIVIDUAL' => '40',
        'INDIVIDUAL_RATE_WINDOW_SEC' => '60',
        'LEVEL_1_THRESHOLD_GLOBAL' => '60',
        'LEVEL_2_THRESHOLD_GLOBAL' => '120',
        'LEVEL_3_THRESHOLD_GLOBAL' => '240',
        'LEVEL_DECAY_GRACE_PERIOD' => '30',
    ];

    public function __construct(private readonly string $demoRoot) {}

    /**
     * Exposed settings keyed by BOTLOCK_ suffix. "default" is the library
     * default, shown as a hint only.
     *
     * @return array<string, array{group: string, type: string, label: string, default: string, help?: string, options?: list<string>}>
     */
    public static function schema(): array
    {
        return [
            'THREAT_LEVEL_OVERRIDE' => ['group' => 'Mode', 'type' => self::TYPE_ENUM, 'options' => ['0', '1', '2', '3', '4'], 'label' => 'Threat level override', 'default' => 'unset (rate-based)', 'help' => 'Fixed level that replaces rate evaluation.'],
            'ENABLE_RATE_LIMIT' => ['group' => 'Mode', 'type' => self::TYPE_BOOL, 'label' => 'Rate limiting', 'default' => 'yes'],
            'ENABLE_GLOBAL_RATE_LIMIT' => ['group' => 'Mode', 'type' => self::TYPE_BOOL, 'label' => 'Global rate limit', 'default' => 'yes'],
            'ENABLE_INDIVIDUAL_RATE_LIMIT' => ['group' => 'Mode', 'type' => self::TYPE_BOOL, 'label' => 'Individual rate limit', 'default' => 'yes'],

            'THRESHOLD_FACTOR' => ['group' => 'Rate limits', 'type' => self::TYPE_FLOAT, 'label' => 'Threshold factor', 'default' => '1', 'help' => 'Multiplies every level threshold.'],
            'LEVEL_1_THRESHOLD_GLOBAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Global level 1', 'default' => '120', 'help' => 'Weighted five-minute score.'],
            'LEVEL_2_THRESHOLD_GLOBAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Global level 2', 'default' => '300'],
            'LEVEL_3_THRESHOLD_GLOBAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Global level 3', 'default' => '600'],
            'LEVEL_DECAY_GRACE_PERIOD' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Global decay grace (s)', 'default' => '300'],
            'LEVEL_1_THRESHOLD_INDIVIDUAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Individual level 1', 'default' => '60', 'help' => 'Requests per window.'],
            'LEVEL_2_THRESHOLD_INDIVIDUAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Individual level 2', 'default' => '90'],
            'LEVEL_3_THRESHOLD_INDIVIDUAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Individual level 3', 'default' => '120'],
            'LEVEL_4_THRESHOLD_INDIVIDUAL' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Individual level 4', 'default' => '180', 'help' => 'Answered with 429, no challenge. 0 or not above level 3: off.'],
            'INDIVIDUAL_RATE_WINDOW_SEC' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Individual window (s)', 'default' => '60'],
            'GC_PROBABILITY' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'GC probability (1 in N)', 'default' => '1000', 'help' => '0 disables the sweep.'],
            'SLIDER_IP_LIMIT' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Slider puzzles per IP', 'default' => '10', 'help' => 'Per window; 429 beyond. 0 disables.'],
            'SLIDER_IP_WINDOW_SEC' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Slider budget window (s)', 'default' => '600'],
            'SLIDER_GLOBAL_LIMIT' => ['group' => 'Rate limits', 'type' => self::TYPE_INT, 'label' => 'Slider puzzles per minute', 'default' => '300', 'help' => 'All clients; 503 beyond. 0 disables.'],

            'POW_ALGORITHM' => ['group' => 'Proof of work', 'type' => self::TYPE_ENUM, 'options' => ['sha256', 'sha384', 'sha512'], 'label' => 'Algorithm', 'default' => 'sha256'],
            'MAX_NUMBER' => ['group' => 'Proof of work', 'type' => self::TYPE_INT, 'label' => 'Max number', 'default' => '50000', 'help' => 'Base difficulty.'],
            'CRAWLER_FACTOR' => ['group' => 'Proof of work', 'type' => self::TYPE_INT, 'label' => 'Crawler factor', 'default' => '15'],
            'SLIDER_ASSISTED_FACTOR' => ['group' => 'Proof of work', 'type' => self::TYPE_INT, 'label' => 'Slider keyboard factor', 'default' => '4', 'help' => 'Slider solved without a drag.'],
            'EXPIRE' => ['group' => 'Proof of work', 'type' => self::TYPE_INT, 'label' => 'Session lifetime (s)', 'default' => '3600'],
            'MIN_SOLVE_MS' => ['group' => 'Proof of work', 'type' => self::TYPE_INT, 'label' => 'Minimum solve time (ms)', 'default' => '1000', 'help' => 'Between issuing and verifying.'],

            'IGNORE_IPS' => ['group' => 'Detection', 'type' => self::TYPE_LIST, 'label' => 'Ignore IPs', 'default' => 'none'],
            'IGNORE_USER_AGENTS' => ['group' => 'Detection', 'type' => self::TYPE_LIST, 'label' => 'Ignore User-Agents', 'default' => 'none', 'help' => 'Case-insensitive substrings.'],
            'IGNORE_URLS' => ['group' => 'Detection', 'type' => self::TYPE_LIST, 'label' => 'Ignore URLs', 'default' => 'none', 'help' => 'Absolute URL prefixes.'],
            'GOOD_BOTS' => ['group' => 'Detection', 'type' => self::TYPE_LIST, 'label' => 'Good bots', 'default' => 'Googlebot, AdsBot, Bingbot, DuckDuckBot, Exabot, facebot'],
            'VERIFY_BOTS' => ['group' => 'Detection', 'type' => self::TYPE_LIST, 'label' => 'Verify bots via DNS', 'default' => 'google, bing'],
            'DNS_CHECKS' => ['group' => 'Detection', 'type' => self::TYPE_BOOL, 'label' => 'DNS checks', 'default' => 'yes'],
            'TRUSTED_PROXIES' => ['group' => 'Detection', 'type' => self::TYPE_LIST, 'label' => 'Trusted proxies', 'default' => 'private ranges', 'help' => 'An empty list trusts no proxy.'],
            'EXTERNAL_SCHEME' => ['group' => 'Detection', 'type' => self::TYPE_ENUM, 'options' => ['auto', 'http', 'https'], 'label' => 'External scheme', 'default' => 'auto'],

            'FAIL_OPEN' => ['group' => 'Kernel', 'type' => self::TYPE_BOOL, 'label' => 'Fail open', 'default' => 'no', 'help' => 'Let requests through when booting fails.'],
        ];
    }

    /**
     * Stored values, or DEFAULTS when no valid settings file exists.
     *
     * @return array<string, string|list<string>|null> every schema key
     */
    public function load(): array
    {
        $json = @\file_get_contents($this->file());
        $data = \is_string($json) ? \json_decode($json, true) : null;

        return self::normalize(\is_array($data) ? $data : self::DEFAULTS);
    }

    /**
     * @param array<string, mixed> $values
     * @throws \RuntimeException when the file cannot be written
     */
    public function save(array $values): void
    {
        $file = $this->file();
        $dir = \dirname($file);

        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Could not create ' . $dir);
        }

        $tmp = $file . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        $json = \json_encode(self::normalize($values), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        if (@\file_put_contents($tmp, $json . "\n") === false || !@\rename($tmp, $file)) {
            @\unlink($tmp);
            throw new \RuntimeException('Could not write ' . $file);
        }
    }

    /** Removes the settings file, so DEFAULTS apply again. */
    public function reset(): void
    {
        @\unlink($this->file());
    }

    /**
     * Keeps schema keys only and coerces each value to its type; anything
     * invalid becomes null (library default).
     *
     * @param array<string, mixed> $values
     * @return array<string, string|list<string>|null>
     */
    public static function normalize(array $values): array
    {
        $result = [];

        foreach (self::schema() as $key => $field) {
            $result[$key] = self::coerce($field, $values[$key] ?? null);
        }

        return $result;
    }

    /**
     * Converts the panel form (settings[KEY] plus default[KEY] checkboxes
     * for lists) into values. Keys missing from the form are library defaults.
     *
     * @param array<string, mixed> $post
     * @return array<string, string|list<string>|null>
     */
    public static function fromForm(array $post): array
    {
        $settings = \is_array($post['settings'] ?? null) ? $post['settings'] : [];
        $defaults = \is_array($post['default'] ?? null) ? $post['default'] : [];
        $values = [];

        foreach (self::schema() as $key => $field) {
            $raw = $settings[$key] ?? null;

            if ($field['type'] === self::TYPE_LIST) {
                $values[$key] = isset($defaults[$key]) || !\is_string($raw) ? null : \preg_split('/\R/', $raw);
            } else {
                $values[$key] = \is_string($raw) ? \trim($raw) : null;
            }
        }

        return self::normalize($values);
    }

    /**
     * @param array<string, string|list<string>|null> $values
     * @return array<string, string> full BOTLOCK_* names; library defaults are omitted
     */
    public static function toEnv(array $values): array
    {
        $env = [];

        foreach (self::normalize($values) as $key => $value) {
            if ($value === null) {
                continue;
            }

            // A JSON array survives entries that contain commas (see Env::list()).
            $env['BOTLOCK_' . $key] = \is_array($value) ? \json_encode($value, \JSON_UNESCAPED_SLASHES) : $value;
        }

        return $env;
    }

    public function file(): string
    {
        return $this->demoRoot . '/.demo/settings.json';
    }

    public function stateDir(): string
    {
        return $this->demoRoot . '/.demo/state';
    }

    public function forgeLogFile(): string
    {
        return $this->demoRoot . '/.demo/forge-log.json';
    }

    /**
     * @return string|list<string>|null
     */
    private static function coerce(array $field, mixed $value): string|array|null
    {
        if ($value === null) {
            return null;
        }

        switch ($field['type'])
        {
            case self::TYPE_INT:
                $value = \is_int($value) ? (string) $value : $value;
                return \is_string($value) && \ctype_digit($value) ? (string) (int) $value : null;

            case self::TYPE_FLOAT:
                $number = \is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value)) ? (float) $value : null;
                return $number !== null && \is_finite($number) && $number > 0 ? (string) $number : null;

            case self::TYPE_BOOL:
                $value = \is_bool($value) ? ($value ? 'yes' : 'no') : $value;
                return \in_array($value, ['yes', 'no'], true) ? $value : null;

            case self::TYPE_ENUM:
                $value = \is_int($value) ? (string) $value : $value;
                return \in_array($value, $field['options'], true) ? $value : null;

            case self::TYPE_LIST:
                if (!\is_array($value)) {
                    return null;
                }

                $items = \array_map(static fn(mixed $v): string => \trim((string) $v), \array_filter($value, 'is_scalar'));

                return \array_values(\array_filter($items, static fn(string $v): bool => $v !== ''));
        }

        return null;
    }
}
