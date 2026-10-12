<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\TimeRanges;
use SubtitleToolbox\Timecode;

final class ReferenceSync
{
    private const SPLIT_BLOCKS = 200;

    // An earlier cue ends at least 1 ms before the first cue of a later segment.
    private const SEGMENT_GAP = 0.001;

    // A video converted between 2 of these frame rates plays at another speed, so its subtitles drift.
    private const FRAME_RATE_PAIRS = [[24, 23.976], [25, 24], [25, 23.976]];


    /**
     * Finds the scale and offset that make the cue times of $subtitle match those of the reference in $options.
     * Then it retimes $subtitle with them.
     */
    public static function apply(Subtitle $subtitle, ReferenceSyncOptions $options): ReferenceSyncReport
    {
        $report   = self::find($subtitle, $options);
        $segments = $report->segments;
        if (count($segments) > 1) {
            self::retimeSegments($subtitle, $segments);

            return $report;
        }

        if ($report->scale != 1) {
            $subtitle->scale($report->scale);
        }
        if ($report->offset != 0) {
            $subtitle->shift($report->offset);
        }

        return $report;
    }


    /**
     * Scales and then shifts each cue with the segment that holds its start. An earlier cue ends before a later segment starts.
     *
     * @param list<SyncSegment> $segments
     */
    private static function retimeSegments(Subtitle $target, array $segments): void
    {
        $segmentCues = array_fill(0, count($segments), []);
        foreach ($target->getCues() as $cue) {
            $index = count($segments) - 1;
            while ($index > 0 && $cue->getStart() < $segments[$index]->from) {
                $index--;
            }
            $segmentCues[$index][] = $cue;
        }

        foreach ($segmentCues as $index => $cues) {
            foreach ($cues as $cue) {
                $cue->mapTimes(fn (float $time): float => $time * $segments[$index]->scale + $segments[$index]->offset);
            }
        }

        for ($index = 1; $index < count($segmentCues); $index++) {
            if ($segmentCues[$index] === []) {
                continue;
            }

            $laterStart = min(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $segmentCues[$index]));
            for ($earlier = 0; $earlier < $index; $earlier++) {
                self::endBefore($segmentCues[$earlier], $laterStart);
            }
        }
    }


    /**
     * Ends each cue that starts before $laterStart at least SEGMENT_GAP before it.
     *
     * @param list<SubtitleCue> $cues
     */
    private static function endBefore(array $cues, float $laterStart): void
    {
        foreach ($cues as $cue) {
            if ($cue->getStart() < $laterStart && $cue->getEnd() > $laterStart - self::SEGMENT_GAP) {
                $cue->setEnd(max($cue->getStart(), $laterStart - self::SEGMENT_GAP));
            }
        }
    }


    private static function find(Subtitle $target, ReferenceSyncOptions $options): ReferenceSyncReport
    {
        $targetSpans    = self::toSpans($target);
        $referenceSpans = self::toSpans($options->reference);
        if ($targetSpans === [] || $referenceSpans === []) {
            return new ReferenceSyncReport(0, 1, 0);
        }

        $targetTime    = SpanOverlap::totalTime($targetSpans);
        $referenceTime = SpanOverlap::totalTime($referenceSpans);
        $best          = new ReferenceSyncReport(0, 1, 0);
        $bestSplit     = null;

        foreach (self::scaleFactors($options->searchScale) as $scale) {
            $scaled = array_map(fn (array $span): array => [$span[0] * $scale, $span[1] * $scale], $targetSpans);

            $coarse     = SpanOverlap::overlapPerOffset($scaled, $referenceSpans, $options->minOffset, $options->maxOffset);
            $coarseBest = $options->minOffset + array_search(max($coarse), $coarse, true) * SpanOverlap::COARSE_STEP;

            [$offset, $overlap] = SpanOverlap::refine($scaled, $referenceSpans, $coarseBest, $options);

            $score = $overlap / ($targetTime * $scale + $referenceTime - $overlap);
            if ($score > $best->score + Timecode::EPSILON) {
                $best = new ReferenceSyncReport(Timecode::roundToMilliseconds($offset), $scale, min(1, max(0, $score)));
            }

            $candidates = $options->maxSplits > 0 ? self::segmentCandidates($targetSpans, $scaled, $referenceSpans, $options) : [];
            foreach ($candidates as [$segments, $segmentsOverlap]) {
                $segmentsScore = $segmentsOverlap / ($targetTime * $scale + $referenceTime - $segmentsOverlap);
                $value         = $segmentsScore - (count($segments) - 1) * $options->splitPenalty;
                if ($bestSplit === null || $value > $bestSplit[0] + Timecode::EPSILON) {
                    $bestSplit = [$value, new ReferenceSyncReport($segments[0]["offset"], $scale, min(1, max(0, $segmentsScore)), $segments)];
                }
            }
        }

        if ($bestSplit !== null && $bestSplit[0] > $best->score + Timecode::EPSILON) {
            return $bestSplit[1];
        }

        return $best;
    }


    /**
     * Returns the best segments for each number of splits from 1 to maxSplits, with the overlap of all segments together.
     * A search over single spans takes too long in PHP. So the splits first fall on block boundaries.
     * Then each split moves to the best span near its boundary.
     *
     * @param list<array{float, float}> $unscaledTarget
     * @param list<array{float, float}> $scaledTarget
     * @param list<array{float, float}> $reference
     * @return list<array{list<array{from: float, offset: float}>, float}>
     */
    private static function segmentCandidates(array $unscaledTarget, array $scaledTarget, array $reference, ReferenceSyncOptions $options): array
    {
        $blockSize = (int)ceil(count($scaledTarget) / self::SPLIT_BLOCKS);
        $search    = new SplitSearch(array_chunk($scaledTarget, $blockSize), $reference, $options);

        $candidates = [];
        for ($layer = 1; $layer <= $options->maxSplits; $layer++) {
            $chain = $search->backtrack($layer, $blockSize);
            if (count($chain) >= 2) {
                $candidates[] = self::refineChain($chain, $unscaledTarget, $scaledTarget, $reference, $blockSize, $options);
            }
        }

        return $candidates;
    }


    /**
     * Moves each split to its best span and refines the offset of each segment.
     *
     * @param list<array{offsetIndex: int, start: int}> $chain
     * @param list<array{float, float}>                 $unscaledTarget
     * @param list<array{float, float}>                 $scaledTarget
     * @param list<array{float, float}>                 $reference
     * @return array{list<array{from: float, offset: float}>, float}
     */
    private static function refineChain(array $chain, array $unscaledTarget, array $scaledTarget, array $reference, int $blockSize, ReferenceSyncOptions $options): array
    {
        foreach ($chain as $index => &$segment) {
            $segment["offset"] = $options->minOffset + $segment["offsetIndex"] * SpanOverlap::COARSE_STEP;
            if ($index > 0) {
                $limit            = ($chain[$index + 1]["start"] ?? count($scaledTarget)) - 1;
                $segment["start"] = self::bestSplit($scaledTarget, $reference, $chain[$index - 1], $segment, $blockSize, $limit);
            }
        }
        unset($segment);

        $segments = [];
        $overlap  = 0.0;
        foreach ($chain as $index => $segment) {
            $end                       = $chain[$index + 1]["start"] ?? count($scaledTarget);
            [$offset, $segmentOverlap] = SpanOverlap::refine(array_slice($scaledTarget, $segment["start"], $end - $segment["start"]),
                                                             $reference, $segment["offset"], $options);
            $segments[]                = ["from" => $index === 0 ? 0.0 : $unscaledTarget[$segment["start"]][0], "offset" => Timecode::roundToMilliseconds($offset)];
            $overlap                  += $segmentOverlap;
        }

        return [$segments, $overlap];
    }


    /**
     * Returns the span index within one block of the split between $before and $after where the overlap is highest.
     * The split stays after the first span of $before and at or before $limit, the last span before the next split.
     *
     * @param list<array{float, float}>        $scaledTarget
     * @param list<array{float, float}>        $reference
     * @param array{start: int, offset: float} $before
     * @param array{start: int, offset: float} $after
     */
    private static function bestSplit(array $scaledTarget, array $reference, array $before, array $after, int $blockSize, int $limit): int
    {
        $from   = max($before["start"] + 1, $after["start"] - $blockSize);
        $to     = min($limit, $after["start"] + $blockSize);
        $window = array_slice($scaledTarget, $from, $to - $from);
        $early  = SpanOverlap::overlapPerSpan($window, $reference, $before["offset"]);
        $late   = SpanOverlap::overlapPerSpan($window, $reference, $after["offset"]);

        $total     = array_sum($late);
        $bestTotal = $total;
        $split     = $from;
        foreach (array_keys($window) as $index) {
            $total += $early[$index] - $late[$index];
            if ($total > $bestTotal + Timecode::EPSILON) {
                $bestTotal = $total;
                $split     = $from + $index + 1;
            }
        }

        return $split;
    }


    /** @return list<float> */
    private static function scaleFactors(bool $searchScale): array
    {
        if (!$searchScale) {
            return [1];
        }

        $factors = [1];
        foreach (self::FRAME_RATE_PAIRS as [$faster, $slower]) {
            $factors[] = $faster / $slower;
            $factors[] = $slower / $faster;
        }

        return $factors;
    }


    /**
     * Returns the cue times as sorted spans without overlaps, so time that two cues of one file share counts once.
     *
     * @return list<array{float, float}>
     */
    private static function toSpans(Subtitle $subtitle): array
    {
        $spans = [];
        foreach ($subtitle->getCues() as $cue) {
            if ($cue->getEnd() > $cue->getStart()) {
                $spans[] = [$cue->getStart(), $cue->getEnd()];
            }
        }

        return TimeRanges::merged($spans);
    }
}
