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
            'challenge' => [
                'label' => 'Always challenge',
                'description' => 'Threat level pinned to 2: every visitor solves the challenge. The demo default.',
                'values' => Settings::DEFAULTS,
            ],
            'off' => [
                'label' => 'Off',
                'description' => 'Threat level pinned to 0: every request passes.',
                'values' => ['THREAT_LEVEL_OVERRIDE' => '0'],
            ],
            'sandbox' => [
                'label' => 'Rate-limit sandbox',
                'description' => 'Rate-based levels with tiny thresholds (5/10/15 requests per minute), so a request burst escalates within seconds.',
                'values' => [
                    'LEVEL_1_THRESHOLD_INDIVIDUAL' => '5',
                    'LEVEL_2_THRESHOLD_INDIVIDUAL' => '10',
                    'LEVEL_3_THRESHOLD_INDIVIDUAL' => '15',
                    'INDIVIDUAL_RATE_WINDOW_SEC' => '60',
                    'LEVEL_1_THRESHOLD_GLOBAL' => '30',
                    'LEVEL_2_THRESHOLD_GLOBAL' => '60',
                    'LEVEL_3_THRESHOLD_GLOBAL' => '120',
                    'LEVEL_DECAY_GRACE_PERIOD' => '30',
                ],
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
