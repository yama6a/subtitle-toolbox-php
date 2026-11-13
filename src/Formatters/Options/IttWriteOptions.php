<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\IttFrameRates;
use SubtitleToolbox\OptionChecks;

final class IttWriteOptions implements FormatWriteOptions
{
    /** @internal */
    public const MISSING_FRAME_RATE = "iTT output needs the frame rate of the video. Set IttWriteOptions::\$frameRate.";


    /**
     * @param ?float $frameRate 23.976, 24, 25, 29.97 or 30. Null takes the frame rate that IttParser stored.
     */
    public function __construct(
        public readonly ?float $frameRate = null,
    ) {
        if ($frameRate !== null && IttFrameRates::key($frameRate) === null) {
            $rates = array_map(strval(...), array_keys(IttFrameRates::PARAMETERS));
            throw new InvalidArgumentException("The ITT formatter accepts the frame rates " . implode(", ", array_slice($rates, 0, -1)) .
                                               " and " . end($rates) . ", got " . OptionChecks::text($frameRate) . ".");
        }
    }
}
