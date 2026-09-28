<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * Whether PuzzleBudget lets one more slider puzzle be rendered.
 */
enum PuzzleBudgetResult
{
    case Granted;

    /** The client used up its puzzles for the window. */
    case ClientExhausted;
}
