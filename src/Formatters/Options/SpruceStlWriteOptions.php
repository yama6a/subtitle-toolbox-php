<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\FrameRate;

final class SpruceStlWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?float $frameRate Frames per second of the timecodes. Null takes the frame rate that SpruceStlParser stored, else 25.
     */
    public function __construct(
        public readonly ?float $frameRate = null,
    ) {
        if ($frameRate !== null) {
            FrameRate::check($frameRate, "The Spruce STL frame rate must be greater than 0, got %s.");
        }
    }
}
