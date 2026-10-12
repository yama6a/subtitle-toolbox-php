<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

final class ReferenceSyncReport
{
    /** @var list<SyncSegment> the parts of the subtitle in time order. Without a split, one part from 0 to INF */
    public readonly array $segments;


    /**
     * @internal
     *
     * @param float                                   $offset     the seconds to add to each time after the scale. After a split, the offset of the first part
     * @param float                                   $scale      the factor to multiply each time with before the offset
     * @param float                                   $score      the shared cue time after the sync divided by the cue time of either file, from 0 to 1
     * @param list<array{from: float, offset: float}> $splitParts
     */
    public function __construct(
        public readonly float $offset,
        public readonly float $scale,
        public readonly float $score,
        array $splitParts = [],
    ) {
        $parts    = $splitParts === [] ? [["from" => 0.0, "offset" => $offset]] : $splitParts;
        $segments = [];
        foreach ($parts as $index => $part) {
            $segments[] = new SyncSegment($part["from"], $parts[$index + 1]["from"] ?? INF, $scale, $part["offset"]);
        }
        $this->segments = $segments;
    }
}
