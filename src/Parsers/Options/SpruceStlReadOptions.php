<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\FrameRate;

final class SpruceStlReadOptions implements FormatReadOptions
{
    /**
     * @param ?float $frameRate Frames per second of the hh:mm:ss:ff timecodes. Null takes 30 when a frame number is 25 or more, else 25.
     */
    public function __construct(public readonly ?float $frameRate = null)
    {
        if ($frameRate !== null) {
            FrameRate::check($frameRate);
        }
    }
}
