<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * What a client has to do before it receives the proof of work. Written
 * into the ticket and the challenge signature, so the verify step knows
 * what was required instead of trusting the browser.
 */
enum Interaction: string
{
    /** The proof of work starts on its own. */
    case None = 'none';

    /** The proof of work is only handed out after a click. */
    case Click = 'click';

    /** The proof of work is only handed out after the slider puzzle is solved. */
    case Slider = 'slider';

    public function isInteractive(): bool
    {
        return $this !== self::None;
    }
}
