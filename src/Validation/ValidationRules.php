<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\OptionChecks;

final class ValidationRules
{
    /**
     * The Netflix limits that CueLimits takes as defaults.
     *
     * @internal
     */
    public const NETFLIX_MAX_CHARACTERS_PER_LINE = 42;

    /** @internal */
    public const NETFLIX_MAX_LINES_PER_CUE = 2;

    /** @internal */
    public const NETFLIX_MAX_DURATION = 7;


    /**
     * A rule with the limit null or false is off. docs/validation.md lists how each rule measures its value.
     *
     * @param ?float             $maxCharactersPerSecond    the most characters per second of a cue
     * @param ?int               $maxCharactersPerLine      the most characters of one line
     * @param ?int               $maxLinesPerCue            the most lines with visible text in a cue
     * @param ?float             $minDuration               the least seconds of a cue
     * @param ?float             $maxDuration               the most seconds of a cue
     * @param ?float             $minGap                    the least seconds between a cue and the earlier cues
     * @param bool               $noOverlap                 check that no cue overlaps an earlier cue
     * @param bool               $noEmptyCues               check that each cue has visible text
     * @param bool               $noDoubleSpaces            check for runs of two or more spaces between words
     * @param bool               $noLeadingOrTrailingSpaces check for lines that start or end with a space
     * @param bool               $noUnbalancedTags          check for core and WebVTT markup tags without a partner tag
     * @param ?DialogueDashStyle $dialogueDashStyle         the only dialogue dash style that a line may use
     * @param ?int               $maxSpeakersPerCue         the most speakers in a cue
     * @param ?float             $maxWordsPerMinute         the most words per minute of a cue
     * @param ?float             $minSecondsPerWord         the least seconds per word of a cue
     * @param ?string            $allowedCharacters         the allowed characters, or a character class such as "[A-Za-z0-9 .,!?]"
     * @param bool               $noAllCapsLines            check for lines with two or more upper case letters and no lower case letter
     * @param bool               $requireCues               check that the subtitle has at least one cue
     * @param bool               $noNegativeDuration        check that no cue ends before it starts
     */
    public function __construct(
        public readonly ?float $maxCharactersPerSecond = null,
        public readonly ?int $maxCharactersPerLine = null,
        public readonly ?int $maxLinesPerCue = null,
        public readonly ?float $minDuration = null,
        public readonly ?float $maxDuration = null,
        public readonly ?float $minGap = null,
        public readonly bool $noOverlap = false,
        public readonly bool $noEmptyCues = false,
        public readonly bool $noDoubleSpaces = false,
        public readonly bool $noLeadingOrTrailingSpaces = false,
        public readonly bool $noUnbalancedTags = false,
        public readonly ?DialogueDashStyle $dialogueDashStyle = null,
        public readonly ?int $maxSpeakersPerCue = null,
        public readonly ?float $maxWordsPerMinute = null,
        public readonly ?float $minSecondsPerWord = null,
        public readonly ?string $allowedCharacters = null,
        public readonly bool $noAllCapsLines = false,
        public readonly bool $requireCues = false,
        public readonly bool $noNegativeDuration = false,
    ) {
        $maximums = ["maxCharactersPerSecond" => $maxCharactersPerSecond, "maxCharactersPerLine" => $maxCharactersPerLine,
                     "maxLinesPerCue" => $maxLinesPerCue, "maxDuration" => $maxDuration,
                     "maxSpeakersPerCue" => $maxSpeakersPerCue, "maxWordsPerMinute" => $maxWordsPerMinute];
        foreach ($maximums as $name => $limit) {
            if ($limit !== null) {
                OptionChecks::notNegative($limit, "The limit $name must be 0 or more, got %s.");
            }
        }
        foreach (["minDuration" => $minDuration, "minGap" => $minGap, "minSecondsPerWord" => $minSecondsPerWord] as $name => $limit) {
            if ($limit !== null) {
                OptionChecks::nonNegativeFinite($limit, "The limit $name must be a finite number of 0 or more, got %s.");
            }
        }

        if ($allowedCharacters !== null && TextChecks::isCharacterClass($allowedCharacters)
            && @preg_match(TextChecks::characterClassPattern($allowedCharacters) . "u", "") === false) {
            throw new InvalidArgumentException("The allowed characters \"$allowedCharacters\" are no valid regular " .
                                               "expression character class.");
        }
    }


    /**
     * Returns the checks of a well-formed cue list.
     * It needs at least one cue, no cue that ends before it starts, and no overlap.
     */
    public static function structure(): self
    {
        return new self(
            requireCues: true,
            noNegativeDuration: true,
            noOverlap: true,
        );
    }


    /**
     * Returns the limits of the Netflix English (USA) Timed Text Style Guide for adult programs at the given frame rate.
     */
    public static function netflixEnglish(float $frameRate): self
    {
        return new self(
            maxCharactersPerSecond: 20,
            maxCharactersPerLine: self::NETFLIX_MAX_CHARACTERS_PER_LINE,
            maxLinesPerCue: self::NETFLIX_MAX_LINES_PER_CUE,
            minDuration: 5 / 6,
            maxDuration: self::NETFLIX_MAX_DURATION,
            minGap: (new FrameRate($frameRate))->framesToSeconds(2),
            noOverlap: true,
        );
    }


    /**
     * Returns the line length and reading speed limits of the BBC Subtitle Guidelines.
     * 37 characters per line is the broadcast limit of section 3.1.
     * 180 words per minute is the upper end of 160 to 180 in section 4. 0.3 s per word is from section 4.1.
     *
     * @see https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/
     */
    public static function bbc(): self
    {
        return new self(
            maxCharactersPerLine: 37,
            maxWordsPerMinute: 180,
            minSecondsPerWord: 0.3,
        );
    }
}
