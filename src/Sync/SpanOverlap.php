<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Timecode;

/**
 * The time that 2 lists of sorted spans without overlaps share when the first list moves by an offset.
 *
 * @internal
 */
final class SpanOverlap
{
    public const COARSE_STEP = 0.1;
    public const FINE_STEP   = 0.01;


    /** @param list<array{float, float}> $spans */
    public static function totalTime(array $spans): float
    {
        return array_sum(array_map(fn (array $span): float => $span[1] - $span[0], $spans));
    }


    /**
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     */
    public static function overlap(array $target, array $reference, float $offset): float
    {
        $overlap        = 0.0;
        $targetCount    = count($target);
        $referenceCount = count($reference);
        $i              = 0;
        $j              = 0;

        while ($i < $targetCount && $j < $referenceCount) {
            $targetEnd = $target[$i][1] + $offset;
            $from      = max($target[$i][0] + $offset, $reference[$j][0]);
            $to        = min($targetEnd, $reference[$j][1]);
            if ($to > $from) {
                $overlap += $to - $from;
            }

            if ($targetEnd < $reference[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $overlap;
    }


    /**
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return list<float>
     */
    public static function overlapPerSpan(array $target, array $reference, float $offset): array
    {
        $overlaps       = array_fill(0, count($target), 0.0);
        $targetCount    = count($target);
        $referenceCount = count($reference);
        $i              = 0;
        $j              = 0;

        while ($i < $targetCount && $j < $referenceCount) {
            $targetEnd = $target[$i][1] + $offset;
            $from      = max($target[$i][0] + $offset, $reference[$j][0]);
            $to        = min($targetEnd, $reference[$j][1]);
            if ($to > $from) {
                $overlaps[$i] += $to - $from;
            }

            if ($targetEnd < $reference[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $overlaps;
    }


    /**
     * Returns the overlap for each offset from $minOffset to $maxOffset in steps of COARSE_STEP.
     * One sweep per offset is too slow in PHP.
     * So the overlap of a target span and a reference span becomes four ramps max(0, o - k).
     * The corners k are referenceStart - targetEnd, referenceStart - targetStart, referenceEnd - targetEnd and
     * referenceEnd - targetStart. The ramps add up in second differences on the grid.
     *
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return list<float>
     */
    public static function overlapPerOffset(array $target, array $reference, float $minOffset, float $maxOffset): array
    {
        $step           = self::COARSE_STEP;
        $last           = (int)floor(($maxOffset - $minOffset) / $step + Timecode::EPSILON);
        $maxGridOffset  = $minOffset + $last * $step;
        $secondDiffs    = array_fill(0, $last + 2, 0.0);
        $firstValue     = 0.0;
        $firstSlope     = 0.0;
        $referenceCount = count($reference);
        $firstCandidate = 0;

        $addRamp = function (float $weight, float $corner) use (&$secondDiffs, &$firstValue, &$firstSlope, $minOffset, $step, $last): void {
            $position = ($corner - $minOffset) / $step;
            if ($position < 0) {
                $firstValue += $weight * ($minOffset - $corner);
                $firstSlope += $weight * $step;

                return;
            }

            $index = (int)floor($position);
            if ($index >= $last) {
                return;
            }

            $fraction = $position - $index;
            if ($index === 0) {
                $firstSlope += $weight * (1 - $fraction) * $step;
            } else {
                $secondDiffs[$index] += $weight * (1 - $fraction) * $step;
            }
            $secondDiffs[$index + 1] += $weight * $fraction * $step;
        };

        foreach ($target as [$targetStart, $targetEnd]) {
            while ($firstCandidate < $referenceCount && $reference[$firstCandidate][1] - $targetStart <= $minOffset) {
                $firstCandidate++;
            }

            for ($j = $firstCandidate; $j < $referenceCount && $reference[$j][0] - $targetEnd < $maxGridOffset; $j++) {
                [$referenceStart, $referenceEnd] = $reference[$j];
                $addRamp(1, $referenceStart - $targetEnd);
                $addRamp(-1, $referenceStart - $targetStart);
                $addRamp(-1, $referenceEnd - $targetEnd);
                $addRamp(1, $referenceEnd - $targetStart);
            }
        }

        return self::integrate($firstValue, $firstSlope, $secondDiffs, $last);
    }


    /**
     * Returns the offset with the highest overlap within COARSE_STEP of $coarseBest, in steps of FINE_STEP.
     *
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return array{float, float} the offset and its overlap
     */
    public static function refine(array $target, array $reference, float $coarseBest, ReferenceSyncOptions $options): array
    {
        $offset  = $coarseBest;
        $overlap = self::overlap($target, $reference, $offset);
        $from    = max($options->minOffset, $coarseBest - self::COARSE_STEP);
        $to      = min($options->maxOffset, $coarseBest + self::COARSE_STEP);
        $steps   = (int)floor(($to - $from) / self::FINE_STEP + Timecode::EPSILON);
        for ($n = 0; $n <= $steps; $n++) {
            $candidate        = $from + $n * self::FINE_STEP;
            $candidateOverlap = self::overlap($target, $reference, $candidate);
            if ($candidateOverlap > $overlap + Timecode::EPSILON) {
                $offset  = $candidate;
                $overlap = $candidateOverlap;
            }
        }

        return [$offset, $overlap];
    }


    /**
     * Returns the values on the grid from the first value, the first slope and the second differences.
     *
     * @param list<float> $secondDiffs
     * @return list<float>
     */
    private static function integrate(float $firstValue, float $firstSlope, array $secondDiffs, int $last): array
    {
        $values = [$firstValue];
        if ($last >= 1) {
            $values[] = $firstValue + $firstSlope;
        }
        for ($n = 1; $n < $last; $n++) {
            $values[] = 2 * $values[$n] - $values[$n - 1] + $secondDiffs[$n];
        }

        return $values;
    }
}
