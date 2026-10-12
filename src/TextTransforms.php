<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
trait TextTransforms
{
    private const TEXT_TRANSFORMS_SENTENCE_GAP = 2.0;

    /** Abbreviations whose period ends no sentence when a comma or a lower case word follows. */
    private const TEXT_TRANSFORMS_ABBREVIATIONS = ["i.e", "e.g", "etc", "vs"];

    private const TEXT_TRANSFORMS_ASCII_SPACES = [" ", "\t", "\n", "\r", "\v", "\f"];

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
        $primary = StringHelpers::primaryLanguage($language);
        $turkic  = in_array($primary, ["tr", "az"], true);

        return match ($mode) {
            CaseMode::Upper    => $this->textTransformsMapRuns(fn (string $text): string => self::textTransformsUpper($text, $turkic)),
            CaseMode::Lower    => $this->textTransformsMapRuns(fn (string $text): string => self::textTransformsLower($text, $turkic)),
            CaseMode::Sentence => $this->textTransformsSentenceCase($turkic, $primary === "en" || $language === null),
        };
    }


    /**
     * Returns plain $text in upper or lower case as changeCase() with CaseMode::Upper or CaseMode::Lower writes it.
     *
     * @internal
     */
    public static function toUpperOrLower(string $text, bool $upper): string
    {
        return $upper ? self::textTransformsUpper($text, false) : self::textTransformsLower($text, false);
    }


    /**
     * Wraps each line with right-to-left letters in the embedding marks U+202B and U+202C, inside its leading and trailing tags.
     * Players with a left-to-right base direction then show the punctuation of Arabic or Hebrew at the right end.
     */
    public function fixRightToLeft(): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array => array_map(self::textTransformsFixRightToLeft(...), $cue->getLines()));
    }


    /**
     * Removes the bidi controls U+200E, U+200F, U+202A to U+202E and U+2066 to U+2069 from the text between tags.
     */
    public function removeBidiControls(): self
    {
        return $this->textTransformsMapRuns(fn (string $text): string => str_replace(self::TEXT_TRANSFORMS_BIDI_CONTROLS, "", $text));
    }


    private static function textTransformsFixRightToLeft(string $line): string
    {
        $tag  = Markup::TAG . '|\{\\\\[^{}]*\}';
        $text = str_replace(["\u{202B}", "\u{202C}"], "", $line);
        if (preg_match('/\{[^{}]*\\\\p[1-9]/', $text) === 1
            || preg_match('/(?=\p{L})[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{Nko}]/u', Markup::visibleText($text)) !== 1
            || preg_match("/^((?:$tag)*)(.*?)((?:$tag)*)\$/su", $text, $matches) !== 1) {
            return $line;
        }

        return "$matches[1]\u{202B}$matches[2]\u{202C}$matches[3]";
    }


    private function textTransformsSentenceCase(bool $turkic, bool $english): self
    {
        $this->textTransformsSentenceCaseRuns($turkic);

        return $english ? $this->textTransformsMapCues(fn (SubtitleCue $cue): array =>
            array_map(self::textTransformsEnglishI(...), $cue->getLines())) : $this;
    }


    private function textTransformsSentenceCaseRuns(bool $turkic): self
    {
        $currentCue       = null;
        $previousEnd      = null;
        $endsSentence     = true;
        $capitalizeNext   = true;
        $afterPunctuation = false;

        return $this->textTransformsMapRuns(
            function (string $text, SubtitleCue $cue, bool $startsLine)
                use (&$currentCue, &$previousEnd, &$endsSentence, &$capitalizeNext, &$afterPunctuation, $turkic): string {
                if ($cue !== $currentCue) {
                    $currentCue       = $cue;
                    $visible          = Markup::visibleText(implode("\n", $cue->getLines()));
                    $capitalizeNext   = $endsSentence || self::textTransformsStartsAnnotation($visible) || ($previousEnd !== null
                        && $cue->getStart() - $previousEnd >= self::TEXT_TRANSFORMS_SENTENCE_GAP - Timecode::EPSILON);
                    $afterPunctuation = false;
                    $previousEnd      = $cue->getEnd();
                    $endsSentence     = self::textTransformsEndsSentence($visible);
                }
                if ($startsLine && ($afterPunctuation || self::textTransformsStartsTurn($text))) {
                    $capitalizeNext = true;
                }

                return self::textTransformsSentenceCaseRun($text, $turkic, $capitalizeNext, $afterPunctuation);
            }
        );
    }


    /**
     * Lowers a text run and capitalizes its first letter or digit after the start of a sentence.
     */
    private static function textTransformsSentenceCaseRun(string $text, bool $turkic, bool &$capitalizeNext, bool &$afterPunctuation): string
    {
        $chars        = Markup::characters(self::textTransformsLower($text, $turkic));
        $original     = Markup::characters($text);
        $word         = "";
        $abbreviation = false;
        $result       = "";
        foreach ($chars as $index => $char) {
            if (preg_match('/^[\p{L}\p{N}]$/u', $char) === 1) {
                if ($capitalizeNext) {
                    $char = self::textTransformsTitle($char, $turkic);
                }
                $capitalizeNext   = false;
                $afterPunctuation = false;
                $word            .= $char;
            } elseif (in_array($char, [".", "!", "?", "\u{2026}"], true)) {
                $afterPunctuation = true;
                $abbreviation     = $char === "." && in_array(strtolower($word), self::TEXT_TRANSFORMS_ABBREVIATIONS, true);
                $word             = $char === "." && $word !== "" ? "$word." : "";
            } else {
                $word = "";
                if ($afterPunctuation && $char === ",") {
                    $afterPunctuation = false;
                } elseif ($afterPunctuation && in_array($char, self::TEXT_TRANSFORMS_ASCII_SPACES, true)) {
                    // Lowering İ adds a combining dot, so the original case is known only when the counts match.
                    $lowerCaseFollows = count($chars) === count($original) && self::textTransformsLowerCaseFollows($original, $index);
                    $afterPunctuation = !($abbreviation && $lowerCaseFollows);
                    $capitalizeNext   = $capitalizeNext || $afterPunctuation;
                }
            }
            $result .= $char;
        }

        return $result;
    }


    /**
     * Tells if the first letter after $index in $chars is a lower case letter.
     *
     * @param list<string> $chars
     */
    private static function textTransformsLowerCaseFollows(array $chars, int $index): bool
    {
        foreach (array_slice($chars, $index + 1) as $char) {
            if (preg_match('/^\p{L}$/u', $char) === 1) {
                return preg_match('/^\p{Ll}$/u', $char) === 1;
            }
            if (!in_array($char, self::TEXT_TRANSFORMS_ASCII_SPACES, true)) {
                return false;
            }
        }

        return false;
    }


    /**
     * Tells if $text starts with the CEA-608 speaker change ">>" or a dialogue dash.
     */
    private static function textTransformsStartsTurn(string $text): bool
    {
        $text = ltrim($text);

        return str_starts_with($text, ">>") || preg_match(DialogueDash::REGEX, $text) === 1;
    }


    /**
     * Tells if $text ends a sentence, or ends with a sound description, a music note or a speaker label.
     * Text that is not valid UTF-8 makes the Unicode regex fail, so an ASCII regex decides then.
     */
    private static function textTransformsEndsSentence(string $text): bool
    {
        $match = preg_match('/(?:[.!?\x{2026}][\p{Pe}\p{Pi}\p{Pf}"\']*|[\])\x{266A}:])$/u', $text);
        if ($match === false) {
            $match = preg_match('/[.!?][)\]}"\']*$|[\]):]$|\xE2\x99\xAA$/', $text);
        }

        return $match === 1;
    }


    /**
     * Tells if $text starts with a sound description or a music note.
     */
    private static function textTransformsStartsAnnotation(string $text): bool
    {
        $text = ltrim($text);

        return str_starts_with($text, "[") || str_starts_with($text, "(") || str_starts_with($text, "\u{266A}");
    }


    /**
     * Writes the English pronoun "i" and its contractions such as "i'm" in upper case. "i.e." stays lower case.
     * Decides on the text of the whole line, so an "i" between tags inside a word stays lower case.
     */
    private static function textTransformsEnglishI(string $line): string
    {
        $visible = "";
        Markup::mapTextRuns([$line], function (string $text) use (&$visible): string {
            $visible .= $text;

            return $text;
        });
        $count = preg_match_all('/(?<![\p{L}\p{N}\'\x{2019}]|\p{L}\.)i(?=(?:[\'\x{2019}](?:m|ll|ve|d))?(?![\p{L}\p{N}\'\x{2019}]|\.\p{L}))/u', $visible, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            $count = preg_match_all('/(?<![A-Za-z0-9\']|[A-Za-z]\.)i(?=(?:\'(?:m|ll|ve|d))?(?![A-Za-z0-9\']|\.[A-Za-z]))/', $visible, $matches, PREG_OFFSET_CAPTURE);
        }
        if ($count === 0) {
            return $line;
        }

        $offsets = array_column($matches[0], 1);
        $start   = 0;

        return Markup::mapTextRuns([$line], function (string $text) use ($offsets, &$start): string {
            foreach ($offsets as $offset) {
                if ($offset >= $start && $offset < $start + strlen($text)) {
                    $text[$offset - $start] = "I";
                }
            }
            $start += strlen($text);

            return $text;
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
        $this->setLinesAndRemoveEmptied(fn (SubtitleCue $cue): array => $fn($cue));

        return $this;
    }


    private static function textTransformsUpper(string $text, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($text)) {
            return strtoupper($text);
        }

        return mb_strtoupper($turkic ? str_replace("i", "İ", $text) : $text, "UTF-8");
    }


    private static function textTransformsLower(string $text, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($text)) {
            return strtolower($text);
        }

        $lower = mb_strtolower($turkic ? str_replace(["İ", "I"], ["i", "ı"], $text) : $text, "UTF-8");

        // PHP 8.2 lacks the Unicode Final_Sigma rule, which PHP 8.3 added to mb_strtolower().
        return preg_replace('/(?<=\p{L})σ(?!\p{L})/u', "ς", $lower);
    }


    private static function textTransformsTitle(string $char, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($char)) {
            return strtoupper($char);
        }

        return mb_convert_case($turkic && $char === "i" ? "İ" : $char, MB_CASE_TITLE, "UTF-8");
    }
}
