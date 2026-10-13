<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
trait TextTransforms
{
    private const TEXT_TRANSFORMS_BIDI_CONTROLS = [
        "\u{200E}", "\u{200F}", "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
    ];


    /**
     * Calls $fn for each text run between tags, with entities decoded as Markup::mapTextRuns() does.
     *
     * @param callable(string $text, SubtitleCue $cue): string $fn
     */
    public function mapText(callable $fn): self
    {
        return $this->textTransformsMapRuns(fn (string $text, SubtitleCue $cue): string => $fn($text, $cue));
    }


    /**
     * Calls $fn for each line, with its tags and entities.
     *
     * @param callable(string $line, SubtitleCue $cue): string $fn
     */
    public function mapLines(callable $fn): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array =>
            array_map(fn (string $line): string => $fn($line, $cue), $cue->getLines()));
    }


    /**
     * Replaces $search with $replace in the text between tags.
     */
    public function replaceText(string $search, string $replace, ?ReplaceTextOptions $options = null): self
    {
        $options ??= new ReplaceTextOptions();
        $regex         = $options->regex;
        $caseSensitive = $options->caseSensitive;
        if ($search === "") {
            throw new InvalidArgumentException("The search text must not be empty.");
        }

        if ($regex) {
            $pattern = $caseSensitive ? $search : $search[0] . "(?i)" . substr($search, 1);
            if (@preg_match($pattern, "") === false) {
                throw new InvalidArgumentException("The regular expression \"$search\" is not valid: " . preg_last_error_msg() . ".");
            }

            return $this->textTransformsMapRuns(fn (string $text): string =>
                preg_replace($pattern, $replace, $text) ?? $text);
        }

        if ($caseSensitive) {
            return $this->textTransformsMapRuns(fn (string $text): string => str_replace($search, $replace, $text));
        }

        $pattern     = "/" . preg_quote($search, "/") . "/iu";
        $replacement = str_replace(["\\", "$"], ["\\\\", "\\$"], $replace);

        return $this->textTransformsMapRuns(fn (string $text): string =>
            preg_replace($pattern, $replacement, $text) ?? str_ireplace($search, $replace, $text));
    }


    /**
     * Removes all tags except the tag names in $keepTags, for example ["i"].
     *
     * @param list<string> $keepTags
     */
    public function stripFormatting(array $keepTags = [], bool $keepWordTimestamps = true): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array => array_map(
            function (string $line) use ($keepTags, $keepWordTimestamps): string {
                if (!$keepWordTimestamps) {
                    return Markup::keepTags($line, $keepTags);
                }

                $parts = preg_split(Markup::WORD_TIMESTAMP_REGEX, $line, -1, PREG_SPLIT_DELIM_CAPTURE);

                return implode("", array_map(
                    fn (string $part, int $index): string => $index % 2 === 1 ? $part : Markup::keepTags($part, $keepTags),
                    $parts,
                    array_keys($parts)
                ));
            },
            $cue->getLines()
        ));
    }


    /**
     * Changes the case of the text between tags. $language "tr" or "az" maps i to İ and ı to I.
     */
    public function changeCase(CaseMode $mode, ?string $language = null): self
    {
        $turkic = TextCase::isTurkic($language);

        return match ($mode) {
            CaseMode::Upper    => $this->textTransformsMapRuns(fn (string $text): string => TextCase::upper($text, $turkic)),
            CaseMode::Lower    => $this->textTransformsMapRuns(fn (string $text): string => TextCase::lower($text, $turkic)),
            CaseMode::Sentence => $this->textTransformsSentenceCase($turkic, $language === null || StringHelpers::primaryLanguage($language) === "en"),
        };
    }


    /**
     * Wraps each line with right-to-left letters in the embedding marks U+202B and U+202C, inside its leading and trailing tags.
     * Players with a left-to-right base direction then show the punctuation of Arabic or Hebrew at the right end.
     */
    public function fixRightToLeft(): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array => array_map(Markup::embedRightToLeft(...), $cue->getLines()));
    }


    /**
     * Removes the bidi controls U+200E, U+200F, U+202A to U+202E and U+2066 to U+2069 from the text between tags.
     */
    public function removeBidiControls(): self
    {
        return $this->textTransformsMapRuns(fn (string $text): string => str_replace(self::TEXT_TRANSFORMS_BIDI_CONTROLS, "", $text));
    }


    private function textTransformsSentenceCase(bool $turkic, bool $english): self
    {
        $this->textTransformsSentenceCaseRuns($turkic);

        return $english ? $this->textTransformsMapCues(fn (SubtitleCue $cue): array =>
            array_map(self::textTransformsEnglishI(...), $cue->getLines())) : $this;
    }


    private function textTransformsSentenceCaseRuns(bool $turkic): self
    {
        $caser = new SentenceCaser($turkic);

        return $this->textTransformsMapRuns($caser->run(...));
    }


    /**
     * Writes the English pronoun "i" in upper case as Markup::upperCaseEnglishI() does, in the runs with &lt;, &gt; and &amp; decoded.
     */
    private static function textTransformsEnglishI(string $line): string
    {
        $runs = [];
        Markup::mapTextRuns([$line], function (string $text) use (&$runs): string {
            $runs[] = $text;

            return $text;
        });
        $upper = Markup::upperCaseEnglishI($runs, true);
        $index = 0;

        return Markup::mapTextRuns([$line], function () use ($upper, &$index): string {
            return $upper[$index++];
        })[0];
    }


    /**
     * Calls $fn for each text run between tags, in order.
     *
     * @param callable(string $text, SubtitleCue $cue, bool $startsLine): string $fn
     */
    private function textTransformsMapRuns(callable $fn): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array => Markup::mapTextRuns(
            $cue->getLines(),
            fn (string $text, bool $first): string => $fn($text, $cue, $first)
        ));
    }


    /**
     * Sets the lines that $fn returns for each cue. Removes a cue that had visible text before but has none after.
     *
     * @param callable(SubtitleCue): list<string> $fn
     */
    private function textTransformsMapCues(callable $fn): self
    {
        CueList::setLinesAndRemoveEmptied($this, fn (SubtitleCue $cue): array => $fn($cue));

        return $this;
    }
}
