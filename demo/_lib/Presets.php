<?php declare(strict_types=1);

namespace GES\Botlock\Demo;

/**
 * Named settings bundles for the demo panel. Keys not listed stay at the
 * library default.
 */
final class Presets
{
    /**
     * @return array<string, array{label: string, description: string, values: array<string, string|list<string>>}>
     */
    public static function all(): array
    {
        return [
            'sandbox' => [
                'label' => 'Rate-limit sandbox',
                'description' => 'Rate-based levels with low thresholds (10/20/30/40 requests per minute), so a request burst escalates within seconds and ends in 429s. The demo default.',
                'values' => Settings::DEFAULTS,
            ],
            'challenge' => [
                'label' => 'Always challenge',
                'description' => 'Threat level pinned to 2: every visitor clicks to start the challenge.',
                'values' => ['THREAT_LEVEL_OVERRIDE' => '2'],
            ],
            'captcha' => [
                'label' => 'Slider captcha',
                'description' => 'Threat level pinned to 3: every visitor solves the slider puzzle before the challenge.',
                'values' => ['THREAT_LEVEL_OVERRIDE' => '3'],
            ],
            'off' => [
                'label' => 'Off',
                'description' => 'Threat level pinned to 0: every request passes.',
                'values' => ['THREAT_LEVEL_OVERRIDE' => '0'],
            ],
            'library' => [
                'label' => 'Library defaults',
                'description' => 'Nothing set: BOTLOCK behaves like a fresh production install.',
                'values' => [],
            ],
        ];
    }

    /**
     * @return array<string, string|list<string>|null>|null normalized values, or null for an unknown preset
     */
    public static function values(string $id): ?array
    {
        $preset = self::all()[$id] ?? null;

        return $preset ? Settings::normalize($preset['values']) : null;
    }

    /**
     * @param array<string, string|list<string>|null> $values normalized settings
     * @return string|null id of the preset these settings equal
     */
    public static function match(array $values): ?string
    {
        foreach (\array_keys(self::all()) as $id) {
            if (self::values($id) === $values) {
                return $id;
            }
        }

        return null;
    }
}
