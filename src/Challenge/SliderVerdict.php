<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * How SliderTrack judged the way the slider was moved.
 */
enum SliderVerdict
{
    /** The track is implausible for a person; the solve counts as a miss. */
    case Rejected;

    /** The piece was dragged into place with a human-looking motion. */
    case Drag;

    /** The piece got there by keys or presses on the track, which say less; the proof of work gets harder. */
    case Assisted;
}
