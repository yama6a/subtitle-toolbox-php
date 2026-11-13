<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\CueList;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\SortedSearch;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class ShotChangeTiming
{
    /**
     * Moves cue times to the shot changes of $options, closes gaps and puts all times on frames.
     * A gap closes to minGapFrames when it is longer than minGapFrames and shorter than snapWindowFrames frames.
     * A gap with a shot change inside stays.
     *
     * @throws InvalidArgumentException when a cue would end at 100000 hours or later. No cue changes then.
     */
    public static function apply(Subtitle $subtitle, ShotChangeOptions $options): ShotChangeReport
    {
        $frameRate = new FrameRate($options->frameRate);
        $shots     = array_map(fn (float $time): int => $frameRate->secondsToFrames($time), $options->shotChanges);
        $shots     = array_values(array_unique($shots));
        sort($shots);

        [$cues, $starts, $ends] = self::toFrames($subtitle, $frameRate);
        [$originalStarts, $originalEnds] = [$starts, $ends];
        $count = count($cues);

        for ($i = 0; $i < $count; $i++) {
            $shot = self::firstShotFrom($shots, $ends[$i]);
            if ($shot !== null && $shot - $ends[$i] <= $options->snapWindowFrames) {
                $end = $shot - $options->minGapFrames;
                if (self::isAllowed($i, $starts[$i], $end, $starts, $ends, $options)) {
                    $ends[$i] = $end;
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $shot = self::lastShotUntil($shots, $starts[$i]);
            if ($shot !== null && $starts[$i] - $shot <= $options->snapWindowFrames
                && self::isAllowed($i, $shot, $ends[$i], $starts, $ends, $options)) {
                $starts[$i] = $shot;
            }
        }

        if ($options->chain) {
            $ends = self::chain($starts, $ends, $shots, $options);
        }

        self::write($cues, $starts, $ends, $frameRate);

        return new ShotChangeReport(
            count(array_diff_assoc($starts, $originalStarts)),
            count(array_diff_assoc($ends, $originalEnds)),
        );
    }


    /**
     * @param list<int> $starts
     * @param list<int> $ends
     * @param list<int> $shots
     * @return list<int>
     */
    private static function chain(array $starts, array $ends, array $shots, ShotChangeOptions $options): array
    {
        for ($i = 0; $i < count($starts) - 1; $i++) {
            $gap = $starts[$i + 1] - $ends[$i];
            if ($gap <= $options->minGapFrames || $gap >= $options->snapWindowFrames) {
                continue;
            }

            // A cue that ends before a shot change must not run into the next shot.
            $shot = self::firstShotFrom($shots, $ends[$i] + 1);
            if ($shot === null || $shot >= $starts[$i + 1]) {
                $ends[$i] = $starts[$i + 1] - $options->minGapFrames;
            }
        }

        return $ends;
    }


    /**
     * Rejects a move that makes a cue shorter than minDurationFrames.
     * It also rejects a move that brings the cue closer than minGapFrames to the cue before or after it.
     *
     * @param list<int> $starts
     * @param list<int> $ends
     */
    private static function isAllowed(int $i, int $start, int $end, array $starts, array $ends, ShotChangeOptions $options): bool
    {
        $duration = $end - $start;
        if ($duration <= 0 || ($duration < $options->minDurationFrames && $duration < $ends[$i] - $starts[$i])) {
            return false;
        }

        $gap = $options->minGapFrames;
        if (isset($starts[$i + 1]) && $end > $starts[$i + 1] - $gap && $end > $ends[$i]) {
            return false;
        }

        return !(isset($ends[$i - 1]) && $start < $ends[$i - 1] + $gap && $start < $starts[$i]);
    }


    /** @param list<int> $shots in ascending order */
    private static function firstShotFrom(array $shots, int $frame): ?int
    {
        return $shots[SortedSearch::firstAtOrAfter($shots, $frame)] ?? null;
    }


    /** @param list<int> $shots in ascending order */
    private static function lastShotUntil(array $shots, int $frame): ?int
    {
        return $shots[SortedSearch::firstAfter($shots, $frame) - 1] ?? null;
    }


    /** @return array{list<SubtitleCue>, list<int>, list<int>} */
    private static function toFrames(Subtitle $subtitle, FrameRate $frameRate): array
    {
        $cues = $subtitle->getCues();

        $starts = array_map(fn (SubtitleCue $cue): int => $frameRate->secondsToFrames($cue->getStart()), $cues);
        $ends   = array_map(fn (SubtitleCue $cue): int => $frameRate->secondsToFrames($cue->getEnd()), $cues);

        return [$cues, $starts, $ends];
    }


    /**
     * A time that rounds up to the next frame can reach MAX_HOURS, so it checks every cue before it changes one.
     *
     * @param list<SubtitleCue> $cues
     * @param list<int> $starts
     * @param list<int> $ends
     * @throws InvalidArgumentException
     */
    private static function write(array $cues, array $starts, array $ends, FrameRate $frameRate): void
    {
        CueList::checkEnds(array_map(fn (int $start, int $end): float => $frameRate->framesToSeconds(max($start, $end)), $starts, $ends));
        foreach ($cues as $i => $cue) {
            $cue->setStart($frameRate->framesToSeconds($starts[$i]));
            $cue->setEnd($frameRate->framesToSeconds($ends[$i]));
        }
    }
}
