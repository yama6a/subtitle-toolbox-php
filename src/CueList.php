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
