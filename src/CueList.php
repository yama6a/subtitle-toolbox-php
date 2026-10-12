<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
final class CueList
{
    /**
     * Sets the lines that $linesOf returns for each cue, or keeps the cue as is when it returns null.
     * Then it removes, in one pass, each cue that had text before and has none after.
     * $hasText decides, Markup::hasVisibleText() by default.
     *
     * @param callable(SubtitleCue, int): ?list<string> $linesOf
     * @param ?callable(array<string>): bool            $hasText
     *
     * @return \SplObjectStorage<SubtitleCue, true> the removed cues
     */
    public static function setLinesAndRemoveEmptied(Subtitle $subtitle, callable $linesOf, ?callable $hasText = null): \SplObjectStorage
    {
        $hasText ??= Markup::hasVisibleText(...);
        $emptied   = new \SplObjectStorage();
        foreach ($subtitle->getCues() as $index => $cue) {
            $before = $cue->getLines();
            $lines  = $linesOf($cue, $index);
            if ($lines === null) {
                continue;
            }

            $cue->setLines($lines);
            if ($hasText($before) && !$hasText($cue->getLines())) {
                $emptied[$cue] = true;
            }
        }
        if ($emptied->count() > 0) {
            $subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => isset($emptied[$cue]));
        }

        return $emptied;
    }


    /**
     * Moves the start, the end and each word timestamp of $cue to $map(seconds). A time below 0 becomes 0.
     *
     * @param callable(float): float $map
     */
    public static function mapTimes(SubtitleCue $cue, callable $map): SubtitleCue
    {
        return $cue->setStart(max(0.0, $map($cue->getStart())))
                   ->setEnd(max(0.0, $map($cue->getEnd())))
                   ->mapWordTimestamps($map);
    }


    /**
     * @param SubtitleCue[] $cues
     *
     * @return list<SubtitleCue>
     */
    public static function inStartOrder(array $cues): array
    {
        $cues = array_values($cues);
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        return $cues;
    }


    /**
     * Throws when $map moves the end of a cue to MAX_HOURS or later. The formatters cast times to int milliseconds,
     * which fails far above MAX_HOURS.
     *
     * @param array<int, SubtitleCue> $cues
     * @param callable(float): float  $map increases with the time
     * @throws InvalidArgumentException
     */
    public static function checkTimeLimit(array $cues, callable $map): void
    {
        foreach ($cues as $index => $cue) {
            $end = $map(max($cue->getStart(), $cue->getEnd()));
            if (!($end < Timecode::MAX_HOURS * 3600)) {
                throw new InvalidArgumentException(
                    "The retimed cue " . ($index + 1) . " would end at $end s. Cue times must be below " . Timecode::MAX_HOURS . " hours."
                );
            }
        }
    }


    /**
     * @param SubtitleCue[] $cues
     */
    public static function startsInOrder(array $cues): bool
    {
        $previous = null;
        foreach ($cues as $cue) {
            if ($previous !== null && $cue->getStart() < $previous->getStart()) {
                return false;
            }
            $previous = $cue;
        }

        return true;
    }


    /**
     * Returns $cues in start order, or null when they are in start order already.
     * Only the cues in $moved can be out of order. $cues are in the order of the starts that $moved holds for its cues.
     * The result equals a stable sort: equal starts keep their order in $cues.
     *
     * @param list<SubtitleCue>                     $cues
     * @param \SplObjectStorage<SubtitleCue, float> $moved each moved cue and the start that its place in $cues follows
     *
     * @return ?list<SubtitleCue>
     */
    public static function withCuesMoved(array $cues, \SplObjectStorage $moved): ?array
    {
        $movedAt = [];
        foreach ($moved as $cue) {
            foreach (self::positionsOf($cues, $cue, $moved) as $position) {
                $movedAt[$position] = $cue;
            }
        }
        $inPlace = true;
        foreach ($movedAt as $position => $cue) {
            $inPlace = $inPlace && ($position === 0 || $cues[$position - 1]->getStart() <= $cue->getStart())
                && (!isset($cues[$position + 1]) || $cue->getStart() <= $cues[$position + 1]->getStart());
        }
        if ($inPlace) {
            return null;
        }
        if (count($movedAt) === 1) {
            return self::withOneCueMoved($cues, array_key_first($movedAt));
        }

        ksort($movedAt);
        $rest      = array_diff_key($cues, $movedAt);
        $positions = array_keys($rest);
        $rest      = array_values($rest);
        foreach ($movedAt as $position => $cue) {
            $low  = 0;
            $high = count($rest);
            while ($low < $high) {
                $middle = ($low + $high) >> 1;
                $start  = $rest[$middle]->getStart();
                if ($start < $cue->getStart() || ($start === $cue->getStart() && $positions[$middle] < $position)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }
            array_splice($rest, $low, 0, [$cue]);
            array_splice($positions, $low, 0, [$position]);
        }

        return $rest;
    }


    /**
     * Moves the cue at $from to its place in the other cues, which are in start order, with one slice rotation.
     *
     * @param list<SubtitleCue> $cues
     *
     * @return list<SubtitleCue>
     */
    private static function withOneCueMoved(array $cues, int $from): array
    {
        $cue   = $cues[$from];
        $start = $cue->getStart();
        // Earlier cues with the same start stay before the cue, and later cues with the same start stay after it.
        [$low, $high] = $from > 0 && $cues[$from - 1]->getStart() > $start ? [0, $from] : [$from + 1, count($cues)];
        while ($low < $high) {
            $middle       = ($low + $high) >> 1;
            $otherStart   = $cues[$middle]->getStart();
            $cueGoesLater = $middle < $from ? $otherStart <= $start : $otherStart < $start;
            if ($cueGoesLater) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low < $from
            ? array_merge(array_slice($cues, 0, $low), [$cue], array_slice($cues, $low, $from - $low), array_slice($cues, $from + 1))
            : array_merge(array_slice($cues, 0, $from), array_slice($cues, $from + 1, $low - $from - 1), [$cue], array_slice($cues, $low));
    }


    /**
     * Finds $cue by a binary search on the start that its place follows, then in the run of cues with that start.
     *
     * @param list<SubtitleCue>                     $cues
     * @param \SplObjectStorage<SubtitleCue, float> $moved
     *
     * @return list<int>
     */
    private static function positionsOf(array $cues, SubtitleCue $cue, \SplObjectStorage $moved): array
    {
        $placeStart = fn (SubtitleCue $other): float => isset($moved[$other]) ? $moved[$other] : $other->getStart();
        $start      = $moved[$cue];
        $low        = 0;
        $high       = count($cues);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($placeStart($cues[$middle]) < $start) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $positions = [];
        for ($position = $low; isset($cues[$position]) && $placeStart($cues[$position]) === $start; $position++) {
            if ($cues[$position] === $cue) {
                $positions[] = $position;
            }
        }

        // A cue that the subtitle no longer holds is not in its place, so only a full search can rule it out.
        return $positions === [] ? array_keys($cues, $cue, true) : $positions;
    }


    /**
     * Joins the cues of $group into the first cue of $group and adds each other cue to $joinedInto.
     * The first cue ends at the latest end of the group. With $joinLines, it also gets the lines of the others.
     * removeJoined() then removes the other cues from the cue list.
     *
     * @param list<SubtitleCue>                           $group
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $joinedInto each joined cue and the cue it joined into
     */
    public static function join(array $group, bool $joinLines, \SplObjectStorage $joinedInto): void
    {
        $joined = $group[0];
        $lines  = $joined->getLines();
        $end    = $joined->getEnd();
        foreach (array_slice($group, 1) as $cue) {
            if ($joinLines) {
                $lines = array_merge($lines, $cue->getLines());
            }
            $end              = max($end, $cue->getEnd());
            $joinedInto[$cue] = $joined;
        }
        $joined->setEnd($end)->setLines($lines);
    }


    /**
     * Removes the cues of $joinedInto from $cues and moves each anchor on such a cue to the cue that holds it now.
     *
     * @param SubtitleCue[]                               $cues
     * @param array<int, ?SubtitleCue>                    $anchors
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $joinedInto
     *
     * @return array{list<SubtitleCue>, array<int, ?SubtitleCue>} the cues and the anchors after the joins
     */
    public static function removeJoined(array $cues, array $anchors, \SplObjectStorage $joinedInto): array
    {
        $joins = [];
        foreach ($joinedInto as $cue) {
            $joins[] = [$cue, $joinedInto->getInfo()];
        }
        // A cue can join into a cue that joins into a third cue later, so the later joins resolve first.
        $holders = new \SplObjectStorage();
        foreach (array_reverse($joins) as [$cue, $into]) {
            $holders[$cue] = isset($holders[$into]) ? $holders[$into] : $into;
        }

        return [
            array_values(array_filter($cues, fn (SubtitleCue $cue): bool => !isset($holders[$cue]))),
            array_map(fn (?SubtitleCue $anchor): ?SubtitleCue => $anchor !== null && isset($holders[$anchor]) ? $holders[$anchor] : $anchor, $anchors),
        ];
    }


    /**
     * Returns true when $first and $second have the same alignment, the same forced flag and the same <v> speakers.
     */
    public static function canJoin(SubtitleCue $first, SubtitleCue $second): bool
    {
        return $first->getAlignment() === $second->getAlignment()
            && $first->isForced() === $second->isForced()
            && self::speakers($first) === self::speakers($second);
    }


    /**
     * @return list<string> the sorted names of the <v> speakers in the cue
     */
    public static function speakers(SubtitleCue $cue): array
    {
        preg_match_all('/' . Markup::VOICE_TAG . '/i', $cue->getText(), $matches);
        $speakers = array_unique(array_map("trim", $matches[2]));
        sort($speakers);

        return $speakers;
    }
}
