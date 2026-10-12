<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\SubtitleCue;

/**
 * One piece of a cue with a word timestamp, with its start and end time.
 *
 * @internal
 */
final readonly class TimedWord
{
    public function __construct(
        public SubtitleCue $cue,
        public CuePieces $pieces,
        public int $index,
        public float $start,
        public float $end,
        public bool $endsSentence,
    ) {
    }
}
