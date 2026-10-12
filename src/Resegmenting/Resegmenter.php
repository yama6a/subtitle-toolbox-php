<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\CueList;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\LineWrapper;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class Resegmenter
{
    /**
     * Splits long cues or builds new cues from the word timestamps, as the mode of $options selects.
     */
    public static function apply(Subtitle $subtitle, ResegmentOptions $options): ResegmentReport
    {
        $cuesBefore = count($subtitle->getCues());
        match ($options->mode) {
            ResegmentMode::SplitLong => self::splitLong($subtitle, $options),
            ResegmentMode::ByWords   => self::byWords($subtitle, $options),
        };

        return new ResegmentReport($cuesBefore, count($subtitle->getCues()));
    }


    private static function splitLong(Subtitle $subtitle, ResegmentOptions $options): void
    {
        $anchors = CommentAnchors::of($subtitle->getCues(), $subtitle->getComments());
        $parts   = [];
        foreach ($subtitle->getCues() as $cue) {
            $parts[] = self::splitCue($cue, self::findSplit($cue, $options), $options);
        }

        $subtitle->replaceCues(array_merge(...$parts), $anchors);
    }


    private static function byWords(Subtitle $subtitle, ResegmentOptions $options): void
    {
        $anchors    = CommentAnchors::of($subtitle->getCues(), $subtitle->getComments());
        $newCues    = new \SplObjectStorage();
        $newCueList = [];
        $group      = [];
        foreach (CueList::inStartOrder($subtitle->getCues()) as $cue) {
            $words = CueImage::isImageCue($cue) ? [] : self::words($cue);
            if ($words === []) {
                $newCueList[]  = self::cueOfGroup($group, $newCues, $options);
                $group         = [];
                $newCueList[]  = $cue;
                $newCues[$cue] = $cue;
                continue;
            }

            foreach ($words as $word) {
                $last = end($group) ?: null;
                if ($last !== null && ($last->cue !== $cue && !CueList::canJoin($last->cue, $cue)
                    || Timecode::roundToMilliseconds($word->start - $last->end) >= Timecode::roundToMilliseconds($options->maxWordGap)
                    || !self::groupFits([...$group, $word], $options))) {
                    $newCueList[] = self::cueOfGroup($group, $newCues, $options);
                    $group        = [];
                }

                $group[] = $word;
                if ($word->endsSentence) {
                    $newCueList[] = self::cueOfGroup($group, $newCues, $options);
                    $group        = [];
                }
            }
        }
        $newCueList[] = self::cueOfGroup($group, $newCues, $options);

        $subtitle->replaceCues(array_filter($newCueList), CommentAnchors::remap($anchors, $newCues));
    }


    private static function findSplit(SubtitleCue $cue, ResegmentOptions $options): ?CueSplit
    {
        if (CueImage::isImageCue($cue)) {
            return null;
        }

        $pieces = CuePieces::of($cue);
        if ($pieces->count() === 0) {
            return null;
        }

        $times  = $pieces->times($cue->getStart(), $cue->getEnd());
        $breaks = self::findBreaks($pieces, $times, 0, $pieces->count(), $options);

        return $breaks === [] ? null : new CueSplit($pieces, $times, $breaks);
    }


    /**
     * @return list<SubtitleCue> $cue itself first, then the new cues
     */
    private static function splitCue(SubtitleCue $cue, ?CueSplit $split, ResegmentOptions $options): array
    {
        if ($split === null) {
            return [$cue];
        }

        $parts = [];
        foreach ([0, ...$split->breaks] as $partIndex => $first) {
            $parts[] = [$partIndex === 0 ? $cue : (clone $cue)->setIdentifier(null), $first, $split->breaks[$partIndex] ?? $split->pieces->count()];
        }
        foreach ($parts as [$part, $first, $after]) {
            $lines = explode("\n", $split->pieces->text($first, $after));
            $part->setStart($split->times[$first])->setEnd($split->times[$after])->setLines(self::wrap($lines, $options));
        }

        return array_column($parts, 0);
    }


    /**
     * Returns the indexes of the pieces that start a new cue, best break point first, until each part fits.
     *
     * @param list<float> $times
     *
     * @return list<int>
     */
    private static function findBreaks(CuePieces $pieces, array $times, int $first, int $after, ResegmentOptions $options): array
    {
        $lines = explode("\n", $pieces->text($first, $after));
        if (self::fits($lines, $times[$first], $times[$after], $options)) {
            return [];
        }

        $positions = $pieces->positions();
        $best      = null;
        for ($index = $first + 1; $index < $after; $index++) {
            $rank = $pieces->breakRank($index);
            if ($rank === null
                || Timecode::roundToMilliseconds($times[$index] - $times[$first]) < Timecode::roundToMilliseconds($options->limits->minDuration)
                || Timecode::roundToMilliseconds($times[$after] - $times[$index]) < Timecode::roundToMilliseconds($options->limits->minDuration)) {
                continue;
            }

            $candidate = [$rank, abs(2 * $positions[$index] - $positions[$first] - $positions[$after]), $index];
            if ($best === null || $candidate < $best) {
                $best = $candidate;
            }
        }
        if ($best === null) {
            return [];
        }

        $bestIndex = $best[2];

        return [
            ...self::findBreaks($pieces, $times, $first, $bestIndex, $options),
            $bestIndex,
            ...self::findBreaks($pieces, $times, $bestIndex, $after, $options),
        ];
    }


    /**
     * @param list<string> $lines
     */
    private static function fits(array $lines, float $start, float $end, ResegmentOptions $options): bool
    {
        $duration = Timecode::roundToMilliseconds($end - $start);

        return $duration <= Timecode::roundToMilliseconds($options->limits->maxDuration)
            && LineWrapper::wrapToFit($lines, $options->limits->maxCharactersPerLine, $options->limits->maxLinesPerCue) !== null
            && LineWrapper::fitsCharactersPerSecond($lines, $duration, $options->limits->maxCharactersPerSecond);
    }


    /**
     * Wraps the lines as mergeShortCues() does, or as wrapLines() does when they do not fit.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function wrap(array $lines, ResegmentOptions $options): array
    {
        return LineWrapper::wrapToFit($lines, $options->limits->maxCharactersPerLine, $options->limits->maxLinesPerCue)
            ?? LineWrapper::wrap($lines, $options->limits->maxCharactersPerLine, $options->limits->maxLinesPerCue);
    }


    /**
     * Returns the words of the cue with their times, or [] when the cue has no word timestamp.
     *
     * @return list<TimedWord>
     */
    private static function words(SubtitleCue $cue): array
    {
        $pieces = CuePieces::of($cue);
        $times  = array_map(fn (CuePiece $piece): ?float => $piece->time, $pieces->pieces);
        if (array_filter($times, fn (?float $time): bool => $time !== null) === []) {
            return [];
        }

        $starts   = [];
        $previous = $cue->getStart();
        foreach ($times as $index => $time) {
            $previous       = $time !== null && $time >= $previous && $time <= $cue->getEnd() ? $time : $previous;
            $starts[$index] = $previous;
        }

        $words = [];
        foreach ($starts as $index => $start) {
            $end     = $starts[$index + 1] ?? max($cue->getEnd(), $start);
            $words[] = new TimedWord($cue, $pieces, $index, $start, $end, $pieces->endsSentence($index));
        }

        return $words;
    }


    /**
     * @param list<TimedWord> $group
     *
     * @return list<string>
     */
    private static function groupLines(array $group): array
    {
        $texts = [];
        $first = 0;
        foreach ($group as $number => $word) {
            $next = $group[$number + 1] ?? null;
            if ($next === null || $next->cue !== $word->cue) {
                $texts[] = $word->pieces->text($group[$first]->index, $word->index + 1);
                $first   = $number + 1;
            }
        }

        return explode("\n", implode("\n", $texts));
    }


    /**
     * @param list<TimedWord> $group
     */
    private static function groupFits(array $group, ResegmentOptions $options): bool
    {
        return self::fits(self::groupLines($group), $group[0]->start, end($group)->end, $options);
    }


    /**
     * Builds one cue from the words of $group and records it in $newCues for each cue whose first word it holds.
     *
     * @param list<TimedWord>                             $group
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $newCues
     */
    private static function cueOfGroup(array $group, \SplObjectStorage $newCues, ResegmentOptions $options): ?SubtitleCue
    {
        if ($group === []) {
            return null;
        }

        $first = $group[0];
        $cue   = (clone $first->cue)
            ->setIdentifier($first->index === 0 ? $first->cue->getIdentifier() : null)
            ->setStart($first->start)
            ->setEnd(end($group)->end)
            ->setLines(self::wrap(self::groupLines($group), $options));

        foreach ($group as $word) {
            if ($word->index === 0) {
                $newCues[$word->cue] = $cue;
            }
        }

        return $cue;
    }
}
