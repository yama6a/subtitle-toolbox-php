<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class SubtitleStatistics
{
    /**
     * The number of most used words that toArray() and the info command show.
     *
     * @internal
     */
    public const MOST_USED_WORDS = 10;

    /**
     * @param int                                            $cueCount            The number of cues, also image cues.
     * @param int                                            $wordCount           The number of words of the text without tags.
     * @param int                                            $characterCount      The characters without tags, with an entity such as &amp; as one character.
     * @param float                                          $totalDisplayTime    The sum of the cue durations in seconds.
     * @param ?float                                         $span                The seconds from the first start to the last end, null without cues.
     * @param ?array{min: float, average: float, max: float} $charactersPerSecond The reading speed of the cues with text and a duration, null without such cues.
     * @param ?array{min: float, average: float, max: float} $wordsPerMinute      The words per minute of the cues with text and a duration, null without such cues.
     * @param ?array{min: float, average: float, max: float} $charactersPerLine   The characters of the lines with text, null without such lines.
     * @param ?array{min: float, average: float, max: float} $gaps                The seconds from the latest end of the earlier cues to the start of each cue, negative for an overlap. Null with fewer than 2 cues.
     * @param list<array{word: string, count: int}>          $mostUsedWords       Every word in lower case with its count, the most used first.
     */
    private function __construct(
        public readonly int $cueCount,
        public readonly int $wordCount,
        public readonly int $characterCount,
        public readonly float $totalDisplayTime,
        public readonly ?float $span,
        public readonly ?array $charactersPerSecond,
        public readonly ?array $wordsPerMinute,
        public readonly ?array $charactersPerLine,
        public readonly ?array $gaps,
        public readonly array $mostUsedWords,
    ) {
    }


    /**
     * Computes the statistics of all cues of $subtitle at the time of the call.
     */
    public static function of(Subtitle $subtitle): self
    {
        $cues = CueList::inStartOrder($subtitle->getCues());
        $span = $cues === [] ? null
            : Timecode::roundToMilliseconds(max(array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $cues)) - $cues[0]->getStart());

        $text             = ["characters" => 0, "words" => 0, "charactersPerSecond" => [], "wordsPerMinute" => [],
                             "charactersPerLine" => [], "frequencies" => []];
        $totalDisplayTime = 0.0;
        $gaps             = [];
        $previousEnd      = null;
        foreach ($cues as $cue) {
            $duration          = Timecode::roundToMilliseconds($cue->getEnd() - $cue->getStart());
            $totalDisplayTime += $duration;

            if ($previousEnd !== null) {
                $gaps[] = Timecode::roundToMilliseconds($cue->getStart() - $previousEnd);
            }
            $previousEnd = max($previousEnd ?? $cue->getEnd(), $cue->getEnd());

            self::addText($cue, $duration, $text);
        }
        arsort($text["frequencies"]);

        $mostUsedWords = [];
        foreach ($text["frequencies"] as $word => $count) {
            $mostUsedWords[] = ["word" => (string) $word, "count" => $count];
        }

        return new self(
            cueCount: count($cues),
            wordCount: $text["words"],
            characterCount: $text["characters"],
            totalDisplayTime: Timecode::roundToMilliseconds($totalDisplayTime),
            span: $span,
            charactersPerSecond: self::range($text["charactersPerSecond"]),
            wordsPerMinute: self::range($text["wordsPerMinute"]),
            charactersPerLine: self::range($text["charactersPerLine"]),
            gaps: self::range($gaps),
            mostUsedWords: $mostUsedWords,
        );
    }


    /**
     * Adds the line lengths, the characters, the words and the reading speed of one cue to $text.
     *
     * @param array{characters: int, words: int, charactersPerSecond: list<float>, wordsPerMinute: list<float>, charactersPerLine: list<int>, frequencies: array<string, int>} $text
     */
    private static function addText(SubtitleCue $cue, float $duration, array &$text): void
    {
        $lineLengths = LineWrapper::visibleLineLengths($cue->getLines());
        array_push($text["charactersPerLine"], ...$lineLengths);
        $characters = array_sum($lineLengths);
        if ($characters === 0) {
            return;
        }

        $words               = Markup::words(Markup::plainText(implode(LineEnding::Lf->value, $cue->getLines())));
        $text["characters"] += $characters;
        $text["words"]      += count($words);
        foreach ($words as $word) {
            $word = self::normalizeWord($word);
            if ($word !== "") {
                $text["frequencies"][$word] = ($text["frequencies"][$word] ?? 0) + 1;
            }
        }

        // A cue without duration has no reading speed.
        if ($duration > 0) {
            $text["charactersPerSecond"][] = $characters / $duration;
            $text["wordsPerMinute"][]      = count($words) / $duration * 60;
        }
    }


    /**
     * Returns all numbers as an array for JSON, with the 10 most used words.
     */
    public function toArray(): array
    {
        return [
            "cueCount"            => $this->cueCount,
            "wordCount"           => $this->wordCount,
            "characterCount"      => $this->characterCount,
            "totalDisplayTime"    => $this->totalDisplayTime,
            "span"                => $this->span,
            "charactersPerSecond" => $this->charactersPerSecond,
            "wordsPerMinute"      => $this->wordsPerMinute,
            "charactersPerLine"   => $this->charactersPerLine,
            "gaps"                => $this->gaps,
            "mostUsedWords"       => array_slice($this->mostUsedWords, 0, self::MOST_USED_WORDS),
        ];
    }


    private static function normalizeWord(string $word): string
    {
        $word = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', "", $word)
            ?? preg_replace('/^[[:punct:]]+|[[:punct:]]+$/', "", $word);

        return StringHelpers::isValidUtf8($word)
            ? mb_strtolower($word, "UTF-8")
            : strtolower($word);
    }


    /**
     * @param list<int|float> $values
     *
     * @return ?array{min: float, average: float, max: float}
     */
    private static function range(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        return [
            "min"     => (float)min($values),
            "average" => (float)(array_sum($values) / count($values)),
            "max"     => (float)max($values),
        ];
    }
}
