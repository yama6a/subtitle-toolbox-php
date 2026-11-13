<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class RecognizedText
{
    /**
     * Holds the recognized text lines and the confidence of the engine from 0 to 1, or null when it gives none.
     *
     * @param list<string> $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?float $confidence = null,
    ) {
        foreach ($lines as $line) {
            if (!is_string($line)) {
                throw new InvalidArgumentException("Every line of a RecognizedText must be a string.");
            }
        }
        if ($confidence !== null && !($confidence >= 0 && $confidence <= 1)) {
            throw new InvalidArgumentException("The confidence must be from 0 to 1, got " . (is_nan($confidence) ? "NAN" : $confidence) . ".");
        }
    }
}
