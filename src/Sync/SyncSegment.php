<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

final class SyncSegment
{
    /**
     * A part of the subtitle that ReferenceSync retimes with one scale and offset.
     *
     * @internal
     *
     * @param float $from   the first subtitle cue start time of the part, in seconds before the sync
     * @param float $to     the cue start time in seconds where the next part starts, or INF for the last part
     * @param float $scale  the factor to multiply each time with before the offset
     * @param float $offset the seconds to add to each time after the scale
     */
    public function __construct(
        public readonly float $from,
        public readonly float $to,
        public readonly float $scale,
        public readonly float $offset,
    ) {
    }
}
