<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Formatters\Options\FormatWriteOptions;

/**
 * The write settings for all formats. EBU STL and PGS ignore $lineEnding and $bom.
 * Only the formatters of ASS, EBU STL, iTT, MicroDVD, SAMI, SubRip, TTML and WebVTT read $stripTags.
 * Subtitle::toString() reads $skipImageCues.
 */
final class WriteOptions
{
    /**
     * @param LineEnding               $lineEnding    The line ending of the output.
     * @param ?bool                    $bom           True adds a UTF-8 BOM and false removes it. Null keeps the default of the format.
     * @param bool                     $stripTags     Write the text without tags.
     * @param bool                     $skipImageCues Drop image cues without text.
     * @param list<FormatWriteOptions> $formats       The settings of single formats, at most one object per class. Each formatter uses the object of its own class and ignores the others.
     */
    public function __construct(
        public readonly LineEnding $lineEnding = LineEnding::Lf,
        public readonly ?bool $bom = null,
        public readonly bool $stripTags = false,
        public readonly bool $skipImageCues = false,
        public readonly array $formats = [],
    ) {
        FormatOptionsList::check($formats, FormatWriteOptions::class, "WriteOptions::\$formats");
    }
}
