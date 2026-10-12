<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use ArrayIterator;
use Iterator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @phpstan-type CueLookupIndex array{cues: array<int, SubtitleCue>, starts: list<float>, keys: list<int>, positions: ?array<int, int>, maxEnds: ?list<float>, leafCount: int}
 *
 * @internal
 */
trait CueLookup
{
    /** @var CueLookupIndex|null */
    private ?array $cueLookupIndex = null;


    /**
     * @return Iterator<int, SubtitleCue>
     */
    public function getIterator(): Iterator
    {
        $this->sortStartChanges();

        return new ArrayIterator($this->cues);
    }


    public function count(): int
    {
        return count($this->cues);
    }


    /**
     * Returns the cues on screen at $time, keyed by cue index. A cue is on screen when start <= $time < end.
     *
     * @return array<int, SubtitleCue>
     */
    public function findCuesAt(float $time): array
    {
        return $this->cueLookupFindOverlapping($time, $time, true);
    }


    /**
     * Returns the lowest index of the cues on screen at $time, or null when no cue is on screen.
     */
    public function findCueIndexAt(float $time): ?int
    {
        return array_key_first($this->findCuesAt($time));
    }


    /**
     * Returns the cues that overlap the range from $from to $to, keyed by cue index and not cut.
     *
     * @return array<int, SubtitleCue>
     */
    public function findCuesBetween(float $from, float $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException("The range start $from must not be after the range end $to.");
        }

        return $this->cueLookupFindOverlapping($from, $to, false);
    }


    /**
     * Returns the cues for which $predicate returns true, keyed by cue index.
     *
     * @param callable(SubtitleCue): bool $predicate
     * @return array<int, SubtitleCue>
     */
    public function findCues(callable $predicate): array
    {
        $this->sortStartChanges();

        return array_filter($this->cues, $predicate);
    }


    /**
     * Removes the cues for which $predicate returns true.
     * The comments before the removed cues move to the next kept cue.
     *
     * @param callable(SubtitleCue): bool $predicate
     */
    public function removeCuesWhere(callable $predicate): self
    {
        foreach ($this->cues as $index => $cue) {
            if ($predicate($cue)) {
                unset($this->cues[$index]);
            }
        }

        return $this->reIndexCues();
    }


    /**
     * @return array<int, SubtitleCue>
     */
    private function cueLookupFindOverlapping(float $from, float $to, bool $includeTo): array
    {
        $index = $this->cueLookupCurrentIndex();
        if ($index["maxEnds"] === null) {
            return array_filter($this->cues, fn (SubtitleCue $cue): bool => $cue->getEnd() > $from &&
                ($includeTo ? $cue->getStart() <= $to : $cue->getStart() < $to));
        }

        $low = $includeTo ? SortedSearch::firstAfter($index["starts"], $to) : SortedSearch::firstAtOrAfter($index["starts"], $to);

        $positions = [];
        self::cueLookupCollectEndingAfter($index["maxEnds"], 1, 0, $index["leafCount"], ["position" => $low, "time" => $from], $positions);

        $cues = [];
        foreach ($positions as $position) {
            $cueIndex        = $index["keys"][$position];
            $cues[$cueIndex] = $this->cues[$cueIndex];
        }

        return $cues;
    }


    /**
     * Returns the start and end times of the cues.
     * For cues in start order, it adds a tree in which each node holds the latest end of its cues.
     * It does not sort. For unsorted cues, maxEnds is null and the caller scans every cue.
     * A change of the cue list makes the next call rebuild the index. A time change of a few cues updates their entries.
     *
     * @return CueLookupIndex
     */
    private function cueLookupCurrentIndex(): array
    {
        $this->sortStartChanges();
        // The cached list shares its storage with $this->cues until either changes, so this check costs O(1).
        if ($this->cueLookupIndex !== null && $this->cueLookupIndex["cues"] === $this->cues) {
            $changed = CueHolders::takeTimeChanges($this);
            if ($changed === [] || (count($changed) <= intdiv(count($this->cues), 8) && $this->cueLookupUpdate($changed))) {
                return $this->cueLookupIndex;
            }
        }

        CueHolders::hold($this, $this->cues);
        CueHolders::watchTimes($this);
        $starts    = [];
        $ends      = [];
        $positions = [];
        foreach ($this->cues as $cue) {
            $positions[spl_object_id($cue)] = count($starts);
            $starts[]                       = $cue->getStart();
            $ends[]                         = $cue->getEnd();
        }

        $count  = count($starts);
        $sorted = true;
        for ($position = 1; $position < $count; $position++) {
            if ($starts[$position] < $starts[$position - 1]) {
                $sorted = false;
                break;
            }
        }

        [$maxEnds, $leafCount] = $sorted ? self::cueLookupMaxEndTree($ends) : [null, 1];

        return $this->cueLookupIndex = [
            "cues"      => $this->cues,
            "starts"    => $starts,
            "keys"      => array_keys($this->cues),
            // A cue that the list holds twice has one id, so its entries cannot update alone.
            "positions" => count($positions) === $count ? $positions : null,
            "maxEnds"   => $maxEnds,
            "leafCount" => $leafCount,
        ];
    }


    /**
     * Writes the new times of $changed into the index and the tree path above each of them.
     * Returns false when the index cannot take them, so the caller rebuilds it.
     *
     * @param list<SubtitleCue> $changed
     */
    private function cueLookupUpdate(array $changed): bool
    {
        $index = &$this->cueLookupIndex;
        if ($index["maxEnds"] === null || $index["positions"] === null) {
            return false;
        }

        foreach ($changed as $cue) {
            $position = $index["positions"][spl_object_id($cue)] ?? null;
            if ($position === null || $index["cues"][$index["keys"][$position]] !== $cue) {
                continue;
            }
            $start = $cue->getStart();
            if (($position > 0 && $index["starts"][$position - 1] > $start) || $start > ($index["starts"][$position + 1] ?? INF)) {
                return false;
            }

            $index["starts"][$position] = $start;
            $node                       = $index["leafCount"] + $position;
            $index["maxEnds"][$node]    = $cue->getEnd();
            for ($node >>= 1; $node >= 1; $node >>= 1) {
                $index["maxEnds"][$node] = max($index["maxEnds"][2 * $node], $index["maxEnds"][2 * $node + 1]);
            }
        }

        return true;
    }


    /**
     * Builds a binary tree over the ends in cue order. Node 1 is the root, and node n has the children 2n and 2n + 1.
     * Each node holds the latest end of its leaves.
     *
     * @param list<float> $ends
     * @return array{list<float>, int} the tree and its number of leaves
     */
    private static function cueLookupMaxEndTree(array $ends): array
    {
        $leafCount = 1;
        while ($leafCount < count($ends)) {
            $leafCount <<= 1;
        }
        $maxEnds = array_fill(0, 2 * $leafCount, -INF);
        foreach ($ends as $position => $end) {
            $maxEnds[$leafCount + $position] = $end;
        }
        for ($node = $leafCount - 1; $node >= 1; $node--) {
            $maxEnds[$node] = max($maxEnds[2 * $node], $maxEnds[2 * $node + 1]);
        }

        return [$maxEnds, $leafCount];
    }


    /**
     * Adds the positions below $limit["position"] in the subtree of $node whose cue ends after $limit["time"].
     *
     * @param list<float>                       $maxEnds
     * @param array{position: int, time: float} $limit
     * @param list<int>                         $positions
     */
    private static function cueLookupCollectEndingAfter(array $maxEnds, int $node, int $firstPosition, int $size, array $limit, array &$positions): void
    {
        if ($firstPosition >= $limit["position"] || $maxEnds[$node] <= $limit["time"]) {
            return;
        }
        if ($size === 1) {
            $positions[] = $firstPosition;

            return;
        }

        $half = $size >> 1;
        self::cueLookupCollectEndingAfter($maxEnds, 2 * $node, $firstPosition, $half, $limit, $positions);
        self::cueLookupCollectEndingAfter($maxEnds, 2 * $node + 1, $firstPosition + $half, $half, $limit, $positions);
    }
}
