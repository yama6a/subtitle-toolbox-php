<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

use SubtitleToolbox\CueList;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\TimeRanges;
use SubtitleToolbox\Timecode;

final class ProfanityFilter
{
    private const WORD_CHARACTER = '[\p{L}\p{M}\p{N}_]';


    /**
     * Masks the words of the options in the cue text and reports the time ranges of the matches, sorted and joined.
     */
    public static function apply(Subtitle $subtitle, ProfanityOptions $options): ProfanityReport
    {
        $pattern = self::pattern($options->words);
        $ranges  = [];

        CueList::setLinesAndRemoveEmptied($subtitle, function (SubtitleCue $cue) use ($pattern, $options, &$ranges): ?array {
            $lines = array_values($cue->getLines());

            return $lines === [] ? null : self::filterCue($cue, $lines, $pattern, $options, $ranges);
        });

        return new ProfanityReport(self::join($ranges, $options->padding));
    }


    /**
     * @param list<string> $words
     */
    private static function pattern(array $words): string
    {
        usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = array_map(
            fn (string $word): string => str_ends_with($word, "*")
                ? preg_quote(substr($word, 0, -1), "/") . self::WORD_CHARACTER . "*"
                : preg_quote($word, "/"),
            $words
        );

        return "/(?<!" . self::WORD_CHARACTER . ")(?:" . implode("|", $alternatives) . ")(?!" . self::WORD_CHARACTER . ")/iu";
    }


    /**
     * Masks the text runs of the cue and adds one range for each run with a match. Returns null when no text changed.
     *
     * @param list<string> $lines
     * @param list<array{float, float}> $ranges
     * @return list<string>|null
     */
    private static function filterCue(SubtitleCue $cue, array $lines, string $pattern, ProfanityOptions $options,
                                      array &$ranges): ?array
    {
        $tokens = array_map(fn (string $line): array => Markup::splitTags($line), $lines);
        $starts = [];
        $time   = null;
        foreach ($tokens as $lineIndex => $lineTokens) {
            foreach ($lineTokens as $tokenIndex => $token) {
                $time = Markup::wordTimestampSeconds($token) ?? $time;
                $starts[$lineIndex][$tokenIndex] = $time;
            }
        }
        $ends = [];
        $time = null;
        foreach (array_reverse($tokens, true) as $lineIndex => $lineTokens) {
            foreach (array_reverse($lineTokens, true) as $tokenIndex => $token) {
                $ends[$lineIndex][$tokenIndex] = $time;
                $time = Markup::wordTimestampSeconds($token) ?? $time;
            }
        }

        $changed = false;
        foreach ($tokens as $lineIndex => $lineTokens) {
            foreach ($lineTokens as $tokenIndex => $token) {
                if ($tokenIndex % 2 === 1 || $token === "") {
                    continue;
                }

                $text   = Markup::unescapeText($token);
                $masked = preg_replace_callback($pattern, fn (array $match): string => self::mask($match[0], $options->mask), $text, -1, $count);
                if ($masked === null || $count === 0) {
                    continue;
                }

                $ranges[] = [$starts[$lineIndex][$tokenIndex] ?? $cue->getStart(), $ends[$lineIndex][$tokenIndex] ?? $cue->getEnd()];
                if ($masked !== $text) {
                    $tokens[$lineIndex][$tokenIndex] = Markup::escapeTextLike($masked, $token);
                    $changed = true;
                }
            }
        }

        return $changed ? array_map(fn (array $lineTokens): string => implode("", $lineTokens), $tokens) : null;
    }


    private static function mask(string $word, ProfanityMask|\Closure $mask): string
    {
        if ($mask instanceof \Closure) {
            return $mask($word);
        }

        preg_match_all('/\X/u', $word, $characters);
        $characters = $characters[0];

        return match ($mask) {
            ProfanityMask::Stars       => str_repeat("*", count($characters)),
            ProfanityMask::FirstLetter => $characters[0] . str_repeat("*", count($characters) - 1),
            ProfanityMask::Remove      => "",
            ProfanityMask::None        => $word,
        };
    }


    /**
     * @param list<array{float, float}> $ranges
     * @return list<MuteRange>
     */
    private static function join(array $ranges, float $padding): array
    {
        $ranges = array_map(fn (array $range): array => [Timecode::roundToMilliseconds(max(0, $range[0] - $padding)), Timecode::roundToMilliseconds($range[1] + $padding)], $ranges);

        return array_map(fn (array $range): MuteRange => new MuteRange($range[0], $range[1]), TimeRanges::merged($ranges));
    }
}
