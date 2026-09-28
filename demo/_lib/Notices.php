<?php declare(strict_types=1);

namespace GES\Botlock\Demo;

/**
 * Messages for the outcomes of /_demo.php actions, shown as a banner after
 * a form redirect or as a toast after a fetch request.
 */
final class Notices
{
    public const MESSAGES = [
        'saved' => 'Settings saved. They apply from the next request on.',
        'preset' => 'Preset applied. It takes effect from the next request on.',
        'reset' => 'Settings reset to the demo defaults.',
        'cleared' => 'Rate-limit state, slider puzzle budgets and cached challenge pages deleted.',
        'rotated' => 'Secret rotated. Every issued grant is invalid now.',
        'log-cleared' => 'Request log cleared.',
    ];

    public static function message(?string $notice): ?string
    {
        return self::MESSAGES[$notice ?? ''] ?? null;
    }
}
