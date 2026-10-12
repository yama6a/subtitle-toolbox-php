<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Timecode;

/**
 * Finds the highest overlap up to each block, for each layer and each coarse offset.
 * A layer is the number of splits so far.
 * A segment that starts at a block takes the best value of the layer below as its floor.
 *
 * @internal
 */
final class SplitSearch
{
    /** @var list<list<float>> the highest overlap per layer and coarse offset */
    private array $values;

    /** @var list<list<int>> the first block of the current segment per layer and coarse offset */
    private array $starts;

    /** @var list<list<int>> the node of the segment before the current segment per layer and coarse offset */
    private array $parents;

    /** @var list<array{float, int}> the floor value and its node per layer */
    private array $floors;

    /** @var list<SplitNode> */
    private array $nodes = [];


    /**
     * @param list<list<array{float, float}>> $blocks
     * @param list<array{float, float}>       $reference
     */
    public function __construct(array $blocks, array $reference, ReferenceSyncOptions $options)
    {
        $layers        = $options->maxSplits + 1;
        $offsets       = (int)floor(($options->maxOffset - $options->minOffset) / SpanOverlap::COARSE_STEP + Timecode::EPSILON) + 1;
        $this->values  = array_fill(0, $layers, array_fill(0, $offsets, 0.0));
        $this->starts  = array_fill(0, $layers, array_fill(0, $offsets, 0));
        $this->parents = array_fill(0, $layers, array_fill(0, $offsets, -1));
        $this->floors  = array_fill(0, $layers, [-INF, -1]);

        foreach ($blocks as $blockIndex => $block) {
            for ($layer = $layers - 1; $layer >= 1 && $blockIndex > 0; $layer--) {
                $this->floors[$layer] = $this->bestNode($layer - 1);
            }

            $overlaps = SpanOverlap::overlapPerOffset($block, $reference, $options->minOffset, $options->maxOffset);
            for ($layer = 0; $layer < $layers; $layer++) {
                $this->addBlock($layer, $blockIndex, $overlaps);
            }
        }
    }


    /**
     * Returns the segments of the best path of a layer, each with its coarse offset index and its first span.
     *
     * @return list<array{offsetIndex: int, start: int}>
     */
    public function backtrack(int $layer, int $blockSize): array
    {
        $chain = [];
        for ($node = $this->bestNode($layer)[1]; $node >= 0; $node = $this->nodes[$node]->parent) {
            $offsetIndex = $this->nodes[$node]->offsetIndex;
            $startBlock  = $this->nodes[$node]->startBlock;
            if ($chain !== [] && $chain[0]["offsetIndex"] === $offsetIndex) {
                $chain[0]["start"] = $startBlock * $blockSize;
            } else {
                array_unshift($chain, ["offsetIndex" => $offsetIndex, "start" => $startBlock * $blockSize]);
            }
        }

        return $chain;
    }


    /**
     * Adds the overlaps of one block to a layer. An offset whose value is below the floor starts a new segment here.
     *
     * @param list<float> $overlaps
     */
    private function addBlock(int $layer, int $blockIndex, array $overlaps): void
    {
        [$floor, $floorNode] = $this->floors[$layer];
        $row                 = &$this->values[$layer];
        foreach ($overlaps as $offsetIndex => $overlap) {
            if ($floor > $row[$offsetIndex] + Timecode::EPSILON) {
                $row[$offsetIndex]                   = $floor;
                $this->starts[$layer][$offsetIndex]  = $blockIndex;
                $this->parents[$layer][$offsetIndex] = $floorNode;
            }
            $row[$offsetIndex] += $overlap;
        }
        unset($row);
    }


    /**
     * Returns the best value of a layer and the node that records it, or the floor of the layer when that is higher.
     *
     * @return array{float, int}
     */
    private function bestNode(int $layer): array
    {
        $value = max($this->values[$layer]);
        if ($value < $this->floors[$layer][0]) {
            return $this->floors[$layer];
        }

        $offsetIndex   = array_search($value, $this->values[$layer], true);
        $this->nodes[] = new SplitNode($offsetIndex, $this->starts[$layer][$offsetIndex], $this->parents[$layer][$offsetIndex]);

        return [$value, count($this->nodes) - 1];
    }
}
