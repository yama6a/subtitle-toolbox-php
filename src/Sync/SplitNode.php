<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

/**
 * The start of one segment on the best path of a layer in SplitSearch.
 *
 * @internal
 */
final readonly class SplitNode
{
    /**
     * @param int $offsetIndex the index of the coarse offset of the segment
     * @param int $startBlock  the first block of the segment
     * @param int $parent      the node of the segment before, or -1 for the first segment
     */
    public function __construct(
        public int $offsetIndex,
        public int $startBlock,
        public int $parent,
    ) {
    }
}
