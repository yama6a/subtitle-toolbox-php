<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Markup;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Keeps the start and the end of each word timestamp of a transcript cue in its format data, because a word timestamp
 * holds only the start. The transcript parsers store them, and PodcastTranscriptFormatter ends a word segment at the end of its word.
 *
 * @internal
 */
final class WordTimes
{
    public const FORMAT_DATA_KEY = "words";


    /**
     * Stores the time of each word timestamp of the cue. $times lists the words in the order of the text, also words
     * without a timestamp in the cue. The cue gets no times when a word timestamp has no word in $times.
     *
     * @param list<array{?float, ?float}> $times the start and the end of each word, null when the source has none
     */
    public static function store(SubtitleCue $cue, array $times): SubtitleCue
    {
        $stored = [];
        $next   = 0;
        foreach (self::starts($cue) as $start) {
            while ($next < count($times) && ($times[$next][0] === null || !self::sameTime($times[$next][0], $start))) {
                $next++;
            }
            if ($next === count($times)) {
                return $cue;
            }
            $stored[] = ["start" => $times[$next][0]] + ($times[$next][1] === null ? [] : ["end" => $times[$next][1]]);
            $next++;
        }

        $hasEnds = array_filter($stored, fn (array $time): bool => isset($time["end"])) !== [];

        return $hasEnds ? $cue->setFormatData(self::FORMAT_DATA_KEY, ["times" => $stored]) : $cue;
    }


    /**
     * Returns the stored end of each word timestamp of the cue, in the order of the text, or null for a word without one.
     * It returns [] when the word timestamps no longer have the stored starts, for example after a retiming or an edit
     * that adds or removes a word timestamp.
     *
     * @return list<?float>
     */
    public static function ends(SubtitleCue $cue): array
    {
        $times  = array_values($cue->findFormatData(self::FORMAT_DATA_KEY)["times"] ?? []);
        $starts = self::starts($cue);
        if (count($times) !== count($starts)) {
            return [];
        }

        $ends = [];
        foreach ($times as $index => $time) {
            if (!self::sameTime($time["start"], $starts[$index])) {
                return [];
            }
            $ends[] = isset($time["end"]) ? Timecode::roundToMilliseconds((float) $time["end"]) : null;
        }

        return $ends;
    }


    /**
     * Returns the time of each word timestamp of the cue, as PodcastTranscriptFormatter reads them.
     *
     * @return list<float>
     */
    private static function starts(SubtitleCue $cue): array
    {
        $starts = [];
        foreach (Markup::splitTags(Markup::rubyAsText(implode(" ", $cue->getLines()))) as $position => $token) {
            $start = $position % 2 === 1 ? Markup::wordTimestampSeconds($token) : null;
            if ($start !== null) {
                $starts[] = $start;
            }
        }

        return $starts;
    }


    private static function sameTime(int|float $time, float $start): bool
    {
        return Timecode::roundToMilliseconds((float) $time) === Timecode::roundToMilliseconds($start);
    }
}
