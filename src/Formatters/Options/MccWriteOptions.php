<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Parsers\Mcc;

final class MccWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?float $frameRate 23.976, 24, 25, 29.97, 30, 50, 59.94 or 60. Null takes the frame rate that MccParser stored, else 29.97.
     * @param ?bool  $dropFrame True writes drop-frame time code at 29.97 and 59.94 fps. Null takes the parsed mode, else true.
     */
    public function __construct(
        public readonly ?float $frameRate = null,
        public readonly ?bool $dropFrame = null,
    ) {
        if ($frameRate !== null && Mcc::frameRateKey($frameRate) === null) {
            $rates = array_map(strval(...), array_keys(Mcc::FRAME_RATES));
            throw new InvalidArgumentException("The MCC formatter accepts the frame rates " . implode(", ", array_slice($rates, 0, -1)) .
                                               " and " . end($rates) . ", got " . OptionChecks::text($frameRate) . ".");
        }
    }
}
