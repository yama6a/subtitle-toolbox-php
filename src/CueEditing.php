<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\CueNotFoundException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Image\CueImage;

/**
 * @internal
 */
trait CueEditing
{
    /**
     * Appends copies of the cues of $other, with their times and word timestamps moved by $offset seconds.
     * Metadata and format data of $this win.
     *
     * @throws InvalidArgumentException when $offset is not finite, or a cue of $other would end at Timecode::MAX_HOURS or later.
     */
    public function merge(Subtitle $other, float $offset = 0): self
    {
        OptionChecks::finite($offset, "The merge offset must be a finite number of seconds, got %s.");
        $move = fn (float $time): float => $time + $offset;
        CueList::checkTimeLimit($other->getCues(), $move);

        $this->sortStartChanges();
        $ownAnchors = CommentAnchors::of($this->cues, $this->comments);
        $otherCues  = [];
        foreach ($other->getCues() as $index => $cue) {
            $otherCues[$index] = (clone $cue)->mapTimes($move);
        }
        $otherAnchors = CommentAnchors::of($otherCues, $other->comments);

        $firstOtherCue = reset($otherCues) ?: null;
        foreach ($ownAnchors as $commentIndex => $anchor) {
            $ownAnchors[$commentIndex] = $anchor ?? $firstOtherCue;
        }

        $comments = array_merge($this->comments, $other->comments);
        CueHolders::hold($this, $otherCues);

        $this->cues       = array_merge(array_values($this->cues), array_values($otherCues));
        $this->metadata   = $this->metadata + $other->getAllMetadata();
        $this->formatData = $this->formatData + $other->formatData;
        $this->sortCues();
        $this->comments   = CommentAnchors::comments($this->cues, $comments, array_merge($ownAnchors, $otherAnchors));

        return $this;
    }


    /**
     * Returns a copy with the cues from $from to $to seconds, cut at both times, and the comments before these cues.
     * $moveToZero moves the times and word timestamps back by $from.
     */
    public function withSlice(float $from, float $to, bool $moveToZero = false): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException("The slice start $from must not be after the slice end $to.");
        }

        $copies = new \SplObjectStorage();
        foreach ($this->cues as $cue) {
            if ($cue->getEnd() <= $from || $cue->getStart() >= $to) {
                continue;
            }

            $shift = $moveToZero ? $from : 0.0;
            $copy  = (clone $cue)->setStart(max($cue->getStart(), $from) - $shift)->setEnd(min($cue->getEnd(), $to) - $shift);
            if ($moveToZero) {
                $copy->mapWordTimestamps(fn (float $time): float => $time - $shift);
            }

            $copies[$cue] = $copy;
        }

        return $this->cueEditingCopyWithCues($copies);
    }


    /**
     * Returns a copy with copies of the forced cues and the comments before these cues.
     */
    public function withForcedCuesOnly(): self
    {
        $copies = new \SplObjectStorage();
        foreach ($this->cues as $cue) {
            if ($cue->isForced()) {
                $copies[$cue] = clone $cue;
            }
        }

        return $this->cueEditingCopyWithCues($copies);
    }


    /**
     * Returns a copy that holds the cue copies in $copies, in the order of their originals.
     *
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $copies original cue => copy
     */
    private function cueEditingCopyWithCues(\SplObjectStorage $copies): self
    {
        $this->sortStartChanges();
        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $copy    = clone $this;
        $cues    = [];
        foreach ($this->cues as $cue) {
            if (isset($copies[$cue])) {
                $cues[] = $copies[$cue];
            }
        }

        $lastCue     = end($this->cues) ?: null;
        $keepsEnd    = $lastCue !== null && isset($copies[$lastCue]);
        $comments    = [];
        $keptAnchors = [];
        foreach ($this->comments as $commentIndex => $comment) {
            $anchor = $anchors[$commentIndex];
            if ($anchor === null ? $keepsEnd : isset($copies[$anchor])) {
                $comments[]    = $comment;
                $keptAnchors[] = $anchor;
            }
        }

        $copy->cues     = $cues;
        $copy->comments = CommentAnchors::comments($copy->cues, $comments, CommentAnchors::remap($keptAnchors, $copies));

        return $copy;
    }


    /**
     * Splits the cue at $index at $at seconds. The first cue keeps the lines up to line $splitAfterLine, counted from 1.
     *
     * @throws CueNotFoundException when no cue has the index $index.
     */
    public function splitCue(int $index, float $at, int $splitAfterLine): self
    {
        $cue = $this->cueEditingCueAt($index, "split");
        if ($at <= $cue->getStart() || $at >= $cue->getEnd()) {
            throw new InvalidArgumentException("Cannot split cue $index at $at: the time must be after the cue " .
                                               "start {$cue->getStart()} and before the cue end {$cue->getEnd()}.");
        }

        $lines = $cue->getLines();
        if ($splitAfterLine < 1 || $splitAfterLine >= count($lines)) {
            throw new InvalidArgumentException("Cannot split cue $index after line $splitAfterLine: " .
                                               "the cue has " . count($lines) . " lines.");
        }

        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $second  = (clone $cue)
            ->setIdentifier(null)
            ->setStart($at)
            ->setLines(array_slice($lines, $splitAfterLine));
        $cue->setEnd($at)->setLines(array_slice($lines, 0, $splitAfterLine));

        $cues     = array_values($this->cues);
        $position = array_search($cue, $cues, true);
        array_splice($cues, $position + 1, 0, [$second]);

        $this->cues = $cues;
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);
        // The second cue can start after the next cue, so it counts as a start change from the place after the first cue.
        CueHolders::hold($this, [$second]);
        CueHolders::startChanged($second, $cue->getStart());

        return $this;
    }


    /**
     * Joins the cues from $first to $last into one cue that holds the lines of all of them.
     * The joined cue keeps the identifier, alignment and format data of cue $first.
     *
     * @throws CueNotFoundException when no cue has the index $first or $last.
     */
    public function joinCues(int $first, int $last): self
    {
        $this->cueEditingCueAt($first, "join");
        $this->cueEditingCueAt($last, "join");
        if ($first >= $last) {
            throw new InvalidArgumentException("Cannot join cues $first to $last: the first index must be " .
                                               "lower than the last index.");
        }

        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $group   = array_filter(
            $this->cues,
            fn (int $index): bool => $index >= $first && $index <= $last,
            ARRAY_FILTER_USE_KEY
        );

        $joinedInto = new \SplObjectStorage();
        CueList::join(array_values($group), true, $joinedInto);
        [$this->cues, $anchors] = CueList::removeJoined($this->cues, $anchors, $joinedInto);
        $this->comments         = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Joins each run of adjacent cues with the same text that are identical, overlap, touch, or are at most $maxGap
     * seconds apart. The cues must also have the same alignment, forced flag and format data, such as an ASS style
     * and layer. The joined cue runs from the earliest start to the latest end of the run.
     *
     * @throws InvalidArgumentException when $maxGap is negative, NAN or INF.
     */
    public function removeDuplicateCues(float $maxGap = 0.0): self
    {
        OptionChecks::nonNegativeFinite($maxGap, "The maximum gap must be a finite number of 0 or more seconds, got %s.");
        $maxGap     = Timecode::roundToMilliseconds($maxGap);
        $this->sortStartChanges();
        $anchors    = CommentAnchors::of($this->cues, $this->comments);
        $joinedInto = new \SplObjectStorage();
        $runCues    = [];
        $runStart   = 0.0;
        $runEnd     = 0.0;
        foreach ($this->cues as $cue) {
            if ($runCues !== [] && self::cueEditingContinuesRun($runCues[0], $cue, $runStart, $runEnd, $maxGap)) {
                $runCues[] = $cue;
                $runStart  = min($runStart, $cue->getStart());
                $runEnd    = max($runEnd, $cue->getEnd());
                continue;
            }

            self::cueEditingJoinRun($runCues, $runStart, $joinedInto);
            $runCues  = [$cue];
            $runStart = $cue->getStart();
            $runEnd   = $cue->getEnd();
        }
        self::cueEditingJoinRun($runCues, $runStart, $joinedInto);

        [$this->cues, $anchors] = CueList::removeJoined($this->cues, $anchors, $joinedInto);
        $this->comments         = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Joins each run of adjacent cues whose start and end both differ by at most $tolerance seconds from the first
     * cue of the run. The cues must also have the same alignment and forced flag. Image cues never join. The joined cue
     * holds the lines of the run in input order, without a repeated cue text, and runs to the latest end of the run.
     *
     * @throws InvalidArgumentException when $tolerance is negative, NAN or INF.
     */
    public function mergeSameTimeCues(float $tolerance = 0.0): self
    {
        OptionChecks::nonNegativeFinite($tolerance, "The tolerance must be a finite number of 0 or more seconds, got %s.");
        $tolerance = Timecode::roundToMilliseconds($tolerance);
        $this->sortStartChanges();
        $anchors   = CommentAnchors::of($this->cues, $this->comments);
        $groups    = [];
        foreach ($this->cues as $cue) {
            $last  = array_key_last($groups);
            $first = $last === null ? null : $groups[$last][0];
            if ($first !== null && !CueImage::isImageCue($first) && !CueImage::isImageCue($cue)
                && $first->isForced() === $cue->isForced()
                && $first->getAlignment() === $cue->getAlignment()
                && abs(Timecode::roundToMilliseconds($cue->getStart() - $first->getStart())) <= $tolerance
                && abs(Timecode::roundToMilliseconds($cue->getEnd() - $first->getEnd())) <= $tolerance) {
                $groups[$last][] = $cue;
                continue;
            }
            $groups[] = [$cue];
        }

        $joinedInto = new \SplObjectStorage();
        foreach ($groups as $group) {
            if (count($group) > 1) {
                $lines = [];
                foreach ($group as $cue) {
                    $lines[$cue->getText()] ??= $cue->getLines();
                }
                CueList::join($group, false, $joinedInto);
                $group[0]->setLines(array_merge(...array_values($lines)));
            }
        }
        [$this->cues, $anchors] = CueList::removeJoined($this->cues, $anchors, $joinedInto);
        $this->comments         = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Sets the cue list to $cues. Each comment moves to the cue that $anchors holds at its position.
     * A null anchor puts the comment after the last cue. CommentAnchors::of() returns the anchors.
     *
     * @internal
     *
     * @param SubtitleCue[]            $cues
     * @param array<int, ?SubtitleCue> $anchors
     */
    public function replaceCues(array $cues, array $anchors): self
    {
        $this->cues     = array_values($cues);
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);
        CueHolders::hold($this, $this->cues);

        return CueList::startsInOrder($this->cues) ? $this : $this->reIndexCues();
    }


    /**
     * Returns true when $cue has the text, the join properties and the format data of $first, the first cue of the run,
     * and is at most $maxGap seconds away from the run.
     */
    private static function cueEditingContinuesRun(SubtitleCue $first, SubtitleCue $cue, float $runStart, float $runEnd, float $maxGap): bool
    {
        return $first->getText() === $cue->getText() && CueList::canJoin($first, $cue)
            && $first->getAllFormatData() === $cue->getAllFormatData()
            && Timecode::roundToMilliseconds($cue->getStart() - $runEnd) <= $maxGap
            && Timecode::roundToMilliseconds($runStart - $cue->getEnd()) <= $maxGap;
    }


    /**
     * Joins a run of 2 or more cues into its first cue, which then starts at $runStart.
     *
     * @param list<SubtitleCue>                           $runCues
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $joinedInto
     */
    private static function cueEditingJoinRun(array $runCues, float $runStart, \SplObjectStorage $joinedInto): void
    {
        if (count($runCues) < 2) {
            return;
        }

        CueList::join($runCues, false, $joinedInto);
        // A setStart() after addCue() can leave a later cue of the run with an earlier start.
        if ($runStart < $runCues[0]->getStart()) {
            $runCues[0]->setStart($runStart);
        }
    }


    private function cueEditingCueAt(int $index, string $action): SubtitleCue
    {
        $this->sortStartChanges();
        if (!array_key_exists($index, $this->cues)) {
            throw new CueNotFoundException("Cannot $action cue $index: the cue does not exist.");
        }

        return $this->cues[$index];
    }
}
