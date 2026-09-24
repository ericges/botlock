<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

use GES\Botlock\Config\ProofOfWorkConfig;

/**
 * Decides per threat level what a challenged client has to do and how hard
 * its proof of work is. Trusted good bots are exempt from the escalation:
 * they always get the easy, self-starting challenge.
 *
 * | Client               | L1     | L2    | L3     |
 * |----------------------|--------|-------|--------|
 * | browser              | none   | click | slider |
 * | untrusted crawler    | click  | click | slider |
 * | trusted good bot     | none   | none  | none   |
 *
 * A slider moved by keys instead of a drag multiplies
 * the difficulty by the assisted factor on top.
 */
final readonly class InteractionPolicy
{
    private const GOOD_BOT_DIFFICULTY = 0.5;

    public function __construct(private ProofOfWorkConfig $config) {}

    public function interaction(int $level, bool $isCrawler, bool $isTrustedGoodBot): Interaction
    {
        if ($isTrustedGoodBot) {
            return Interaction::None;
        }

        return match (true) {
            $level >= 3 => Interaction::Slider,
            $level >= 2, $isCrawler => Interaction::Click,
            default => Interaction::None,
        };
    }

    public function difficulty(bool $isCrawler, bool $isTrustedGoodBot): float
    {
        return match (true) {
            $isTrustedGoodBot => self::GOOD_BOT_DIFFICULTY,
            $isCrawler => (float) $this->config->getCrawlerFactor(),
            default => 1.0,
        };
    }

    /**
     * Difficulty multiplier for a slider solved without a drag.
     */
    public function assistedFactor(): float
    {
        return (float) $this->config->getAssistedFactor();
    }
}
