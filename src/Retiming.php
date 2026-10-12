<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
trait Retiming
{
    /**
     * Shifts the cues that start at or after $fromTime and before $toTime by $seconds. A null bound has no limit.
     * The start time of a cue decides, so a cue across $toTime moves as a whole.
     * The cues are then sorted by start time again, and each comment stays before its cue.
     * The word timestamps in the cue text move with the cue. A time that becomes negative becomes 0, and the cue stays.
     *
     * @throws InvalidArgumentException when $toTime is not after $fromTime.
     */
    public function shift(float $seconds, ?float $fromTime = null, ?float $toTime = null): self
    {
        if ($fromTime !== null && $toTime !== null && !($toTime > $fromTime)) {
            throw new InvalidArgumentException("The end time of the shift must be after its start time, got $fromTime to $toTime.");
        }

        $this->retimingApplyLinearCorrection(1, $seconds, $fromTime, $toTime);

        return $fromTime === null && $toTime === null ? $this : $this->reIndexCues();
    }


    /**
     * Multiplies the start and end time and the word timestamps of every cue by $factor.
     */
    public function scale(float $factor): self
    {
        OptionChecks::positiveFinite($factor, "The scale factor must be greater than 0, got %s.");

        return $this->retimingApplyLinearCorrection($factor, 0);
    }


    /**
     * Converts the cue times from a video at the frame rate $from to the same video at the frame rate $to.
     */
    public function convertFrameRate(float $from, float $to): self
    {
        return $this->scale((new FrameRate($from))->getFramesPerSecond() / (new FrameRate($to))->getFramesPerSecond());
    }


    /**
     * Moves each old time of $points to its new time, and corrects all other times and the word timestamps between them linearly.
     * 1 point shifts all cues. Times before the first point and after the last point follow the nearest 2 points.
     * A time that becomes negative becomes 0, and the cue stays.
     *
     * @param list<SyncPoint> $points sorted by old time
     * @throws InvalidArgumentException when $points is empty, holds another type, or its old or new times do not increase strictly.
     */
    public function syncByPoints(array $points): self
    {
        $points = self::retimingCheckSyncPoints($points);
        if (count($points) === 1) {
            return $this->retimingApplyLinearCorrection(1, $points[0]->newSeconds - $points[0]->oldSeconds);
        }

        $segments = self::retimingPointSegments($points);
        foreach ($this->getCues() as $cue) {
            $cue->mapTimes(function (float $time) use ($segments): float {
                $segment = $segments[0];
                foreach ($segments as $candidate) {
                    if ($time < $candidate["from"]) {
                        break;
                    }
                    $segment = $candidate;
                }

                return $time * $segment["factor"] + $segment["offset"];
            });
        }

        return $this;
    }


    /**
     * @param array<SyncPoint> $points
     *
     * @return list<SyncPoint>
     */
    private static function retimingCheckSyncPoints(array $points): array
    {
        if ($points === []) {
            throw new InvalidArgumentException("The sync points must not be empty.");
        }
        $points = array_values($points);
        foreach ($points as $index => $point) {
            if (!$point instanceof SyncPoint) {
                throw new InvalidArgumentException("The sync point $index must be a SyncPoint, got " . get_debug_type($point) . ".");
            }
        }
        for ($index = 1; $index < count($points); $index++) {
            [$previous, $point] = [$points[$index - 1], $points[$index]];
            if (!($point->oldSeconds > $previous->oldSeconds && $point->newSeconds > $previous->newSeconds)) {
                throw new InvalidArgumentException(
                    "The old and new times of the sync points must both increase, got {$previous->oldSeconds}={$previous->newSeconds}"
                    . " before {$point->oldSeconds}={$point->newSeconds}."
                );
            }
        }

        return $points;
    }


    /**
     * Returns the line between each 2 neighbouring points: a time from "from" on maps to time * factor + offset.
     *
     * @param list<SyncPoint> $points
     *
     * @return list<array{from: float, factor: float, offset: float}>
     */
    private static function retimingPointSegments(array $points): array
    {
        $segments = [];
        for ($index = 1; $index < count($points); $index++) {
            [$a, $b]    = [$points[$index - 1], $points[$index]];
            $factor     = ($b->newSeconds - $a->newSeconds) / ($b->oldSeconds - $a->oldSeconds);
            $segments[] = ["from" => $a->oldSeconds, "factor" => $factor, "offset" => $a->newSeconds - $a->oldSeconds * $factor];
        }

        return $segments;
    }


    private function retimingApplyLinearCorrection(float $factor, float $offset, ?float $fromTime = null, ?float $toTime = null): self
    {
        foreach ($this->getCues() as $cue) {
            if (($fromTime !== null && $cue->getStart() < $fromTime) || ($toTime !== null && $cue->getStart() >= $toTime)) {
                continue;
            }

            $cue->mapTimes(fn (float $time): float => $time * $factor + $offset);
        }

        return $this;
    }
}
