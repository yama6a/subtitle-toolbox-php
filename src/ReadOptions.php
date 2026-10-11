<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;

/**
 * The format-neutral settings of one read. Each parser ignores the fields it does not use.
 */
final class ReadOptions
{
    /** The iconv name of the encoding of input that is not UTF-8, such as "Windows-1252". */
    public readonly ?string $encoding;


    /**
     * @param TextEncoding|string|null $encoding        The encoding of input that is not UTF-8. A TextEncoding case, or any other name that iconv accepts, for example "CP1125". A UTF-16 or UTF-32 BOM wins. Valid UTF-8 without zero bytes stays as is.
     * @param bool                     $lenient         Skip or repair a broken block and record a ParseWarning instead of throwing. SCC, PGS, VobSub and the chapter formats ignore it.
     * @param float                    $lastCueDuration Seconds that a last cue without an end lasts.
     * @param list<FormatReadOptions>  $formats         The settings of single formats, at most one object per class. Each parser uses the object of its own class and ignores the others.
     */
    public function __construct(
        TextEncoding|string|null $encoding = null,
        public readonly bool $lenient = false,
        public readonly float $lastCueDuration = 5.0,
        public readonly array $formats = [],
    ) {
        $encoding       = $encoding instanceof TextEncoding ? $encoding->value : $encoding;
        $this->encoding = $encoding;
        if ($encoding !== null && @iconv($encoding, "UTF-8", "") === false) {
            throw new InvalidArgumentException("The encoding \"$encoding\" is unknown.");
        }
        OptionChecks::nonNegativeFinite($lastCueDuration, "The last cue duration must be 0 or more seconds, got %s.");
        FormatOptionsList::check($formats, FormatReadOptions::class, "ReadOptions::\$formats");
    }
}
