<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\FrameRate;

final class MicroDvdWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?float $frameRate          Frames per second of the video. Null takes the frame rate that MicroDvdParser stored.
     * @param bool   $writeFrameRateLine Write the frame rate as the first line, such as {1}{1}23.976. A file that MicroDvdParser read with this line gets it anyway.
     */
    public function __construct(
        public readonly ?float $frameRate = null,
        public readonly bool $writeFrameRateLine = false,
    ) {
        if ($frameRate !== null) {
            FrameRate::check($frameRate, "The MicroDVD frame rate must be greater than 0, got %s.");
        }
    }
}
