<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use SubtitleToolbox\DialogueDashStyle;

final class CommonErrorOptions
{
    /**
     * docs/text.md#fixing-common-errors shows an example of each fix.
     *
     * @param ?string           $language                     a code such as "en", "de-AT" or "fra", or null for the language metadata
     * @param bool              $doubleSpaces                 join runs of spaces into one, as in "Hi  there"
     * @param bool              $spaceBeforePunctuation       remove the space before punctuation, as in "Really ?"
     * @param bool              $missingSpaceAfterPunctuation add the space after punctuation, as in "Stop.Now"
     * @param bool              $unbalancedTags               close an open tag at the cue end, and remove a closing tag without an opening tag
     * @param bool              $emptyTags                    remove tag pairs without text, as in "<i></i>"
     * @param bool              $dialogueDashes               write the dialogue dashes in $dialogueDashStyle
     * @param DialogueDashStyle $dialogueDashStyle            the dash that $dialogueDashes writes
     * @param bool              $ellipsis                     write ". . ." and "...." as "...", or as U+2026 with $unicodeEllipsis
     * @param bool              $unicodeEllipsis              make $ellipsis write U+2026 for every ellipsis
     * @param bool              $ocrLowercaseL                read an OCR "l" as "I" where the language needs it, as in "lt's"
     * @param bool              $ocrPipe                      read an OCR "|" as "I" or "l", as in "|t was"
     * @param bool              $ocrZeroInWords               read an OCR "0" in a word as "O" or "o", as in "D0N'T"
     * @param ?OcrReplaceList   $replaceList                  the words to replace, or null for no replace list
     * @param bool              $dialogueOnOneLine            split "- Hi. - Hello." into 2 dialogue lines. Off by default
     * @param bool              $loneLowercaseI               write the English pronoun "i" as "I", as in "i think". Off by default
     * @param bool              $sentenceStartCase            start a cue or line after a sentence end with a capital letter. Off by default
     * @param bool              $musicNotes                   write a "#" or "*" at the start or end of a line as U+266A, as in "# la la #". Off by default
     * @param bool              $doubleApostrophes            write 2 apostrophes as a double quote, as in "''Hi''". Off by default
     * @param bool              $unneededPeriods              remove a period after "?" or "!", as in "Really?.". Off by default
     */
    public function __construct(
        public readonly ?string $language = null,
        public readonly bool $doubleSpaces = true,
        public readonly bool $spaceBeforePunctuation = true,
        public readonly bool $missingSpaceAfterPunctuation = true,
        public readonly bool $unbalancedTags = true,
        public readonly bool $emptyTags = true,
        public readonly bool $dialogueDashes = true,
        public readonly DialogueDashStyle $dialogueDashStyle = DialogueDashStyle::HyphenSpace,
        public readonly bool $ellipsis = true,
        public readonly bool $unicodeEllipsis = false,
        public readonly bool $ocrLowercaseL = true,
        public readonly bool $ocrPipe = true,
        public readonly bool $ocrZeroInWords = true,
        public readonly ?OcrReplaceList $replaceList = null,
        public readonly bool $dialogueOnOneLine = false,
        public readonly bool $loneLowercaseI = false,
        public readonly bool $sentenceStartCase = false,
        public readonly bool $musicNotes = false,
        public readonly bool $doubleApostrophes = false,
        public readonly bool $unneededPeriods = false,
    ) {
    }


    /**
     * Tells if these options turn $rule on for $language, the result of CommonErrorFixer::language().
     *
     * @internal
     */
    public function enables(CommonErrorRule $rule, ?string $language): bool
    {
        return match ($rule) {
            CommonErrorRule::ReplaceList                  => $this->replaceList !== null,
            CommonErrorRule::UnbalancedTags               => $this->unbalancedTags,
            CommonErrorRule::EmptyTags                    => $this->emptyTags,
            CommonErrorRule::DoubleApostrophes            => $this->doubleApostrophes,
            CommonErrorRule::OcrPipe                      => $this->ocrPipe,
            CommonErrorRule::OcrZeroInWords               => $this->ocrZeroInWords,
            CommonErrorRule::OcrLowercaseL                => $this->ocrLowercaseL,
            CommonErrorRule::LoneLowercaseI               => $this->loneLowercaseI && $language === "en",
            CommonErrorRule::Ellipsis                     => $this->ellipsis,
            CommonErrorRule::UnneededPeriods              => $this->unneededPeriods,
            CommonErrorRule::DoubleSpaces                 => $this->doubleSpaces,
            CommonErrorRule::SpaceBeforePunctuation       => $this->spaceBeforePunctuation,
            CommonErrorRule::MissingSpaceAfterPunctuation => $this->missingSpaceAfterPunctuation,
            CommonErrorRule::DialogueOnOneLine            => $this->dialogueOnOneLine,
            CommonErrorRule::DialogueDashes               => $this->dialogueDashes,
            CommonErrorRule::MusicNotes                   => $this->musicNotes,
            CommonErrorRule::SentenceStartCase            => $this->sentenceStartCase,
        };
    }
}
