<?php

declare(strict_types=1);

namespace SubtitleToolbox;

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
     * @param list<SubtitleCue> $cues
     */
    public static function startsInOrder(array $cues): bool
    {
        for ($index = 1; $index < count($cues); $index++) {
            if ($cues[$index]->getStart() < $cues[$index - 1]->getStart()) {
                return false;
            }
        }

        return true;
    }


    /**
     * Joins the cues of $group into the first cue of $group and removes the others from $cues.
     * The first cue ends at the latest end of the group. With $joinLines, it also gets the lines of the others.
     *
     * @param SubtitleCue[]            $cues
     * @param SubtitleCue[]            $group
     * @param array<int, ?SubtitleCue> $anchors
     *
     * @return array{list<SubtitleCue>, array<int, ?SubtitleCue>} the cues and the anchors after the join
     */
    public static function join(array $cues, array $group, array $anchors, bool $joinLines): array
    {
        $group  = array_values($group);
        $joined = $group[0];
        $lines  = $joined->getLines();
        $end    = $joined->getEnd();
        foreach (array_slice($group, 1) as $cue) {
            if ($joinLines) {
                $lines = array_merge($lines, $cue->getLines());
            }
            $end     = max($end, $cue->getEnd());
            $anchors = CommentAnchors::move($anchors, $cue, $joined);
        }
        $joined->setEnd($end)->setLines($lines);

        $cues = array_values(array_filter(
            $cues,
            fn (SubtitleCue $cue): bool => $cue === $joined || !in_array($cue, $group, true)
        ));

        return [$cues, $anchors];
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
