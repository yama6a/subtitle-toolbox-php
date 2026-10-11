<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Helpers of the transcript JSON formatters. They compare a cue with the source fields that its parser stored,
 * so a formatter writes an unedited value as it was read and builds an edited one again from the cue.
 *
 * @phpstan-type Word array{text: string, start: ?float, end: float}
 *
 * @internal
 */
final class TranscriptWords
{
    public const NEW_WORD_CONFIDENCE = 1.0;


    /**
     * Returns the text of the cue on one line, without tags and with single spaces.
     */
    public static function text(SubtitleCue $cue): string
    {
        return self::normalize(Markup::plainText(Markup::rubyAsText(implode(" ", $cue->getLines()))));
    }


    /**
     * Returns true when $source, a text as the parser read it, gives the text of the cue.
     */
    public static function sameText(SubtitleCue $cue, mixed $source): bool
    {
        return is_string($source) && self::normalize($source) === self::text($cue);
    }


    /**
     * Returns true when $sourceSeconds, a time as the parser read it, rounds to $seconds.
     */
    public static function sameTime(?float $sourceSeconds, float $seconds): bool
    {
        return $sourceSeconds !== null && Timecode::roundToMilliseconds($sourceSeconds) === $seconds;
    }


    /**
     * Returns the speaker of the first voice tag of the cue, or null.
     */
    public static function speaker(SubtitleCue $cue): ?string
    {
        $speaker = Markup::speaker(implode(" ", $cue->getLines()));

        return $speaker === "" ? null : $speaker;
    }


    /**
     * Returns the words of the core word timestamps of the cue, or null for a cue without word timestamps.
     * A word is the text from its timestamp to the next one. It ends where the next word starts, or at the cue end.
     * Text before the first timestamp is a word without a start.
     *
     * @return ?list<Word>
     */
    public static function fromMarkup(SubtitleCue $cue): ?array
    {
        $words = [["text" => "", "start" => null, "end" => null]];
        foreach (Markup::splitTags(Markup::rubyAsText(implode(" ", $cue->getLines()))) as $index => $token) {
            $time = $index % 2 === 1 ? Markup::wordTimestampSeconds($token) : null;
            if ($time !== null) {
                $words[] = ["text" => "", "start" => Timecode::roundToMilliseconds($time), "end" => null];
            } elseif ($index % 2 === 0) {
                $words[count($words) - 1]["text"] .= Markup::decodeEntities($token);
            }
        }
        if (count($words) === 1) {
            return null;
        }

        $end = $cue->getEnd();
        for ($index = count($words) - 1; $index >= 0; $index--) {
            $words[$index]["text"] = self::normalize($words[$index]["text"]);
            $words[$index]["end"]  = max($end, $words[$index]["start"] ?? $cue->getStart());
            $end                   = $words[$index]["start"] ?? $end;
        }

        return array_values(array_filter($words, fn (array $word): bool => $word["text"] !== ""));
    }


    /**
     * Returns true when the cue has no word timestamps, or has the words of $sourceWords with their texts and starts.
     *
     * @param list<array{0: string, 1: ?float}> $sourceWords the text and the start of each source word, as the parser reads them
     */
    public static function sameWords(SubtitleCue $cue, array $sourceWords): bool
    {
        $words = self::words($cue, $sourceWords, true, true, fn (array $word): array => $word, fn (): null => null);

        return $words === null || $words === $sourceWords;
    }


    /**
     * Returns the words to write for the cue.
     * With word timestamps, a word of the markup that has the start and the text of source words is those source
     * words, and $newWord builds the others. A word without a start starts at the cue start.
     * Without word timestamps, a cue with its source times keeps $sourceWords. When its text changed but has as many
     * words, each changed word gets the times of its source word from $newWord. Else the cue has no words.
     *
     * @param list<mixed>                                    $sourceWords the words that the parser stored for the cue
     * @param callable(mixed): array{string, ?float, ?float} $readWord    returns the text, the start and the end of a source word as the parser reads them
     * @param callable(string, float, float): mixed          $newWord     builds a word from its text, start and end
     *
     * @return ?list<mixed> null when the cue has no words
     */
    public static function words(SubtitleCue $cue, array $sourceWords, bool $sameTimes, bool $sameText, callable $readWord, callable $newWord): ?array
    {
        $words = self::fromMarkup($cue);
        if ($words === null) {
            return $sameTimes && $sourceWords !== [] ? self::retext($cue, $sourceWords, $sameText, $readWord, $newWord) : null;
        }

        $source = array_map($readWord, $sourceWords);
        $next   = 0;
        $result = [];
        foreach ($words as $word) {
            $match = null;
            for ($index = $next; $index < count($source) && $match === null; $index++) {
                $match = self::match($word, $source, $index);
            }

            if ($match === null) {
                $result[] = $newWord($word["text"], $word["start"] ?? max(0.0, $cue->getStart()), $word["end"]);
                continue;
            }
            array_push($result, ...array_slice($sourceWords, $match[0], $match[1] - $match[0]));
            $next = $match[1];
        }

        return $result;
    }


    /**
     * Returns the source words with the texts of the cue, word by word, or null when the cue has another number of words.
     */
    private static function retext(SubtitleCue $cue, array $sourceWords, bool $sameText, callable $readWord, callable $newWord): ?array
    {
        if ($sameText) {
            return $sourceWords;
        }
        $texts = explode(" ", self::text($cue));
        if (count($texts) !== count($sourceWords)) {
            return null;
        }

        $result = [];
        foreach (array_values($sourceWords) as $index => $word) {
            [$text, $start, $end] = $readWord($word) + [null, null, null];
            $result[]             = self::sameWordText($texts[$index], $text)
                ? $word
                : $newWord($texts[$index], $start ?? max(0.0, $cue->getStart()), $end ?? $start ?? max(0.0, $cue->getStart()));
        }

        return $result;
    }


    /**
     * Returns the first and the end index of the source words from $first on that give the word of the markup, or null.
     * Source words without a start follow the word before them in the markup, as the parsers write no timestamp for them.
     *
     * @param Word                        $word
     * @param list<array{0: string, 1: ?float}> $source
     *
     * @return ?array{int, int}
     */
    private static function match(array $word, array $source, int $first): ?array
    {
        $start = $source[$first][1];
        if ($start === null ? $word["start"] !== null : $word["start"] !== Timecode::roundToMilliseconds($start)) {
            return null;
        }

        $text = "";
        for ($end = $first; $end < count($source) && ($end === $first || $source[$end][1] === null); $end++) {
            $text = ltrim("$text {$source[$end][0]}");
            if (self::sameWordText($word["text"], $text)) {
                return [$first, $end + 1];
            }
        }

        return null;
    }


    /**
     * Returns true when $text, a word of the cue, is the source word $source.
     * The text may end with punctuation that the source word lacks, as the words of the OpenAI API lack the full stops of the text.
     */
    private static function sameWordText(string $text, string $source): bool
    {
        return $text === $source
            || ($source !== "" && str_starts_with($text, $source) && preg_match('/^\p{P}+$/u', substr($text, strlen($source))) === 1);
    }


    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', " ", $text) ?? $text);
    }
}
