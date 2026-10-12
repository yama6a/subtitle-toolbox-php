<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

/**
 * The split of one long cue.
 *
 * @internal
 */
final readonly class CueSplit
{
    /**
     * @param list<float> $times  the start time of each piece, then the cue end
     * @param list<int>   $breaks the indexes of the pieces that start a new cue
     */
    public function __construct(
        public CuePieces $pieces,
        public array $times,
        public array $breaks,
    ) {
    }
}
