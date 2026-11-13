<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * An exact point on the screen in percent of the video width and height. The alignment of the cue says which point
 * of the cue box sits there: for Alignment::BottomCenter the middle of the bottom edge, for Alignment::TopLeft the
 * top left corner.
 */
final class CuePosition
{
    /**
     * @param float $x 0 is the left edge of the video, 100 the right edge.
     * @param float $y 0 is the top edge of the video, 100 the bottom edge.
     *
     * @throws InvalidArgumentException when $x or $y is not from 0 to 100, or is NAN.
     */
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {
        OptionChecks::between($x, 0, 100, "The position x must be a number from 0 to 100, got %s.");
        OptionChecks::between($y, 0, 100, "The position y must be a number from 0 to 100, got %s.");
    }
}
