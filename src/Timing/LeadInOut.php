<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\CueList;
use SubtitleToolbox\SortedSearch;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * @internal
 */
final class LeadInOut
{
    /**
     * @param SubtitleCue[] $cues
     */
    public static function apply(array $cues, float $leadIn, float $leadOut, float $minGap): void
    {
        $cues   = CueList::inStartOrder($cues);
        $starts = array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $cues);
        $ends   = array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $cues);
        [$startInside, $endInside] = self::insideOtherCues($starts, $ends);

        foreach ($cues as $i => $cue) {
            if ($leadOut <= 0 || $endInside[$i]) {
                continue;
            }
            $end  = $ends[$i] + $leadOut;
            $next = SortedSearch::firstAtOrAfter($starts, $ends[$i]);
            if ($next === $i) {
                $next++;
            }
            if ($next < count($starts)) {
                $end = min($end, $starts[$next] - $minGap);
            }
            if (Timecode::roundToMilliseconds($end) > $ends[$i]) {
                $cue->setEnd($end);
            }
        }

        $newEnds = array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $cues);
        asort($newEnds);
        $endOrder  = array_keys($newEnds);
        $sortedEnd = array_values($newEnds);
        foreach ($cues as $i => $cue) {
            if ($leadIn <= 0 || $startInside[$i]) {
                continue;
            }
            $start    = max(0, $starts[$i] - $leadIn);
            $previous = SortedSearch::firstAfter($sortedEnd, $starts[$i]) - 1;
            if ($previous >= 0 && $endOrder[$previous] === $i) {
                $previous--;
            }
            if ($previous >= 0) {
                $start = max($start, $sortedEnd[$previous] + $minGap);
            }
            if (Timecode::roundToMilliseconds($start) < $starts[$i]) {
                $cue->setStart($start);
            }
        }
    }


    /**
     * Finds the cues whose start or end lies inside another cue. A shared boundary of overlapping cues stays where it is.
     *
     * @param list<float> $starts in ascending order
     * @param list<float> $ends
     *
     * @return array{list<bool>, list<bool>}
     */
    private static function insideOtherCues(array $starts, array $ends): array
    {
        $startInside = [];
        $endInside   = [];
        $maxEnd      = -INF;
        $count       = count($starts);
        for ($i = 0; $i < $count; $i++) {
            $startInside[$i] = $maxEnd > $starts[$i];
            $endInside[$i]   = $maxEnd >= $ends[$i];
            for ($k = $i + 1; $k < $count && $starts[$k] < $ends[$i]; $k++) {
                $endInside[$i] = $endInside[$i] || $ends[$k] >= $ends[$i];
            }
            for ($k = $i + 1; $k < $count && $starts[$k] === $starts[$i]; $k++) {
                $startInside[$i] = $startInside[$i] || $ends[$k] > $starts[$i];
            }
            $maxEnd = max($maxEnd, $ends[$i]);
        }

        return [$startInside, $endInside];
    }
}
