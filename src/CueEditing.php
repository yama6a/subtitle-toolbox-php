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
     */
    public function merge(Subtitle $other, float $offset = 0): self
    {
        $ownAnchors = CommentAnchors::of($this->cues, $this->comments);
        $otherCues  = [];
        foreach ($other->getCues() as $index => $cue) {
            $otherCues[$index] = (clone $cue)->mapTimes(fn (float $time): float => $time + $offset);
        }
        $otherAnchors = CommentAnchors::of($otherCues, $other->comments);

        $firstOtherCue = reset($otherCues) ?: null;
        foreach ($ownAnchors as $commentIndex => $anchor) {
            $ownAnchors[$commentIndex] = $anchor ?? $firstOtherCue;
        }

        $comments = array_merge($this->comments, $other->comments);
        $cues     = CueList::inStartOrder(array_merge(array_values($this->cues), array_values($otherCues)));

        $this->cues       = $cues;
        $this->metadata   = $this->metadata + $other->getAllMetadata();
        $this->formatData = $this->formatData + $other->formatData;
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

        [$this->cues, $anchors] = CueList::join($this->cues, $group, $anchors, true);
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

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
        $maxGap  = Timecode::roundToMilliseconds($maxGap);
        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $groups  = [];
        foreach ($this->cues as $cue) {
            $last  = array_key_last($groups);
            $group = $last === null ? null : $groups[$last];
            $first = $group["cues"][0] ?? null;
            if ($first !== null && $first->getText() === $cue->getText() && CueList::canJoin($first, $cue)
                && $first->getAllFormatData() === $cue->getAllFormatData()
                && Timecode::roundToMilliseconds($cue->getStart() - $group["end"]) <= $maxGap
                && Timecode::roundToMilliseconds($group["start"] - $cue->getEnd()) <= $maxGap) {
                $groups[$last]["cues"][] = $cue;
                $groups[$last]["start"]  = min($group["start"], $cue->getStart());
                $groups[$last]["end"]    = max($group["end"], $cue->getEnd());
                continue;
            }

            $groups[] = ["cues" => [$cue], "start" => $cue->getStart(), "end" => $cue->getEnd()];
        }

        foreach ($groups as ["cues" => $group, "start" => $start]) {
            if (count($group) > 1) {
                [$this->cues, $anchors] = CueList::join($this->cues, $group, $anchors, false);
                // A setStart() after addCue() can leave a later cue of the run with an earlier start.
                if ($start < $group[0]->getStart()) {
                    $group[0]->setStart($start);
                }
            }
        }
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

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

        foreach ($groups as $group) {
            if (count($group) > 1) {
                $lines = [];
                foreach ($group as $cue) {
                    $lines[$cue->getText()] ??= $cue->getLines();
                }
                [$this->cues, $anchors] = CueList::join($this->cues, $group, $anchors, false);
                $group[0]->setLines(array_merge(...array_values($lines)));
            }
        }
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

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

        return $this;
    }


    private function cueEditingCueAt(int $index, string $action): SubtitleCue
    {
        if (!array_key_exists($index, $this->cues)) {
            throw new CueNotFoundException("Cannot $action cue $index: the cue does not exist.");
        }

        return $this->cues[$index];
    }
}
