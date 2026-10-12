<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * CommentAnchors keeps each comment before the same cue while cues split, join or move.
 *
 * An anchor is the cue that a comment comes before, or null for a comment after the last cue.
 *
 * @internal
 */
final class CommentAnchors
{
    /**
     * @param SubtitleCue[] $cues
     * @param list<Comment> $comments
     *
     * @return array<int, ?SubtitleCue>
     */
    public static function of(array $cues, array $comments): array
    {
        if ($comments === []) {
            return [];
        }

        $keys = array_keys($cues);

        return array_map(fn (Comment $comment): ?SubtitleCue => self::anchor($cues, $keys, $comment->beforeCueIndex), $comments);
    }


    /**
     * Adds the parsed cues, then puts each comment before the cue that followed it in the file.
     *
     * @param array<int, SubtitleCue>        $cues     in file order, keyed by the count of cues before them in the file
     * @param list<array{0: string, 1: int}> $comments the text and the count of cues before it in the file
     */
    public static function addParsed(Subtitle $subtitle, array $cues, array $comments): Subtitle
    {
        $subtitle->addCues($cues);
        if ($comments === []) {
            return $subtitle;
        }

        $positions = self::positions($subtitle->getCues());
        $keys      = array_keys($cues);
        foreach ($comments as [$text, $cueCount]) {
            $anchor = self::anchor($cues, $keys, $cueCount);
            $subtitle->addComment($text, $anchor === null ? count($subtitle) : $positions[$anchor]);
        }

        return $subtitle;
    }


    /**
     * Replaces each anchor with the cue that $map holds for it. A null anchor stays null.
     *
     * @param array<int, ?SubtitleCue>                    $anchors
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $map
     *
     * @return array<int, ?SubtitleCue>
     */
    public static function remap(array $anchors, \SplObjectStorage $map): array
    {
        return array_map(fn (?SubtitleCue $anchor): ?SubtitleCue => $anchor === null ? null : $map[$anchor], $anchors);
    }


    /**
     * Returns the comments sorted by their new cue index. A comment whose anchor is not in $cues goes after the last cue.
     *
     * @param SubtitleCue[]            $cues
     * @param list<Comment>            $comments
     * @param array<int, ?SubtitleCue> $anchors
     *
     * @return list<Comment>
     */
    public static function comments(array $cues, array $comments, array $anchors): array
    {
        $comments  = array_values($comments);
        $anchors   = array_values($anchors);
        $positions = $comments === [] ? new \SplObjectStorage() : self::positions($cues);
        foreach ($comments as $commentIndex => $comment) {
            $anchor = $anchors[$commentIndex];

            $comments[$commentIndex] = $comment->withBeforeCueIndex($anchor !== null && isset($positions[$anchor]) ? $positions[$anchor] : count($cues));
        }

        return self::sorted($comments);
    }


    /**
     * @param SubtitleCue[] $cues
     * @param list<int>     $keys the keys of $cues
     */
    private static function anchor(array $cues, array $keys, int $beforeCueIndex): ?SubtitleCue
    {
        $low  = 0;
        $high = count($keys);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($keys[$middle] < $beforeCueIndex) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low < count($keys) ? $cues[$keys[$low]] : null;
    }


    /**
     * @param SubtitleCue[] $cues
     *
     * @return \SplObjectStorage<SubtitleCue, int> each cue and its first key in $cues
     */
    private static function positions(array $cues): \SplObjectStorage
    {
        $positions = new \SplObjectStorage();
        foreach ($cues as $index => $cue) {
            if (!isset($positions[$cue])) {
                $positions[$cue] = $index;
            }
        }

        return $positions;
    }


    /**
     * @param list<Comment> $comments
     *
     * @return list<Comment> the comments in the order of their cue index, ties in the given order
     */
    public static function sorted(array $comments): array
    {
        usort($comments, fn (Comment $comment1, Comment $comment2): int => $comment1->beforeCueIndex <=> $comment2->beforeCueIndex);

        return $comments;
    }
}
