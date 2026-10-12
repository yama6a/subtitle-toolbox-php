<?php

declare(strict_types=1);

namespace SubtitleToolbox\Dual;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\CueList;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class DualSubtitle
{
    /**
     * Returns a new subtitle with the cues of both inputs. Metadata, comments and format data come from $primary.
     */
    public static function fromPair(Subtitle $primary, Subtitle $secondary, ?DualSubtitleOptions $options = null): Subtitle
    {
        $options ??= new DualSubtitleOptions();
        $primaryCues   = array_map(fn (SubtitleCue $cue): SubtitleCue => clone $cue, $primary->getCues());
        $secondaryCues = array_map(
            fn (SubtitleCue $cue): SubtitleCue => self::copySecondaryCue($cue, $options),
            array_values($secondary->getCues())
        );

        $unpairedCues = $options->mode === DualSubtitleMode::Stack
            ? self::stack($primaryCues, $secondaryCues)
            : self::placeApart($primaryCues, $secondaryCues, $options);

        $cues = CueList::inStartOrder(array_merge(array_values($primaryCues), $unpairedCues));

        $result = self::emptyCopy($primary);
        $result->addCues($cues);
        $anchors = CommentAnchors::of($primaryCues, $primary->getComments());
        foreach (CommentAnchors::comments($cues, $primary->getComments(), $anchors) as $comment) {
            $result->addComment($comment->text, $comment->beforeCueIndex);
        }

        $primaryLanguage   = $primary->findMetadata(Subtitle::METADATA_LANGUAGE);
        $secondaryLanguage = $secondary->findMetadata(Subtitle::METADATA_LANGUAGE);
        if ($primaryLanguage !== null && $secondaryLanguage !== null) {
            $result->setMetadata(Subtitle::METADATA_LANGUAGE, "$primaryLanguage+$secondaryLanguage");
        }

        return $result;
    }


    /**
     * Adds the lines of each secondary cue to the primary cue it overlaps most, and returns the cues without a partner.
     *
     * @param array<int, SubtitleCue> $primaryCues
     * @param list<SubtitleCue> $secondaryCues
     *
     * @return list<SubtitleCue>
     */
    private static function stack(array $primaryCues, array $secondaryCues): array
    {
        $partners     = [];
        $unpairedCues = [];
        foreach ($secondaryCues as $secondaryCue) {
            $bestIndex   = null;
            $bestOverlap = 0;
            foreach ($primaryCues as $primaryIndex => $primaryCue) {
                $overlap = min($primaryCue->getEnd(), $secondaryCue->getEnd())
                           - max($primaryCue->getStart(), $secondaryCue->getStart());
                if ($overlap > $bestOverlap) {
                    $bestIndex   = $primaryIndex;
                    $bestOverlap = $overlap;
                }
            }

            if ($bestIndex === null) {
                $unpairedCues[] = $secondaryCue;
            } else {
                $partners[$bestIndex][] = $secondaryCue;
            }
        }

        foreach ($partners as $primaryIndex => $cues) {
            $joined = $primaryCues[$primaryIndex];
            $lines  = $joined->getLines();
            $start  = $joined->getStart();
            $end    = $joined->getEnd();
            foreach ($cues as $cue) {
                $lines = array_merge($lines, $cue->getLines());
                $start = min($start, $cue->getStart());
                $end   = max($end, $cue->getEnd());
            }
            $joined->setStart($start)->setEnd($end)->setLines($lines);
        }

        return $unpairedCues;
    }


    /**
     * Moves the secondary cues to the alignment of the options and snaps their times to close primary times.
     *
     * @param array<int, SubtitleCue> $primaryCues
     * @param list<SubtitleCue> $secondaryCues
     *
     * @return list<SubtitleCue>
     */
    private static function placeApart(array $primaryCues, array $secondaryCues, DualSubtitleOptions $options): array
    {
        $primaryTimes = [];
        foreach ($primaryCues as $cue) {
            $primaryTimes[] = $cue->getStart();
            $primaryTimes[] = $cue->getEnd();
        }
        sort($primaryTimes);

        foreach ($secondaryCues as $cue) {
            $start = self::snap($cue->getStart(), $primaryTimes, $options->snapTolerance);
            $end   = self::snap($cue->getEnd(), $primaryTimes, $options->snapTolerance);
            // Snapping both times of a short cue to the same primary time would hide the cue.
            if ($end > $start) {
                $cue->setStart($start)->setEnd($end);
            }
            $cue->setAlignment($options->secondaryAlignment);
        }

        return $secondaryCues;
    }


    /**
     * @param list<float> $times sorted, so the earlier of two equally close times wins
     */
    private static function snap(float $time, array $times, float $tolerance): float
    {
        $snapped  = $time;
        $distance = INF;
        foreach ($times as $candidate) {
            $candidateDistance = Timecode::roundToMilliseconds(abs($candidate - $time));
            if ($candidateDistance <= $tolerance && $candidateDistance < $distance) {
                $snapped  = $candidate;
                $distance = $candidateDistance;
            }
        }

        return $snapped;
    }


    /**
     * Returns a copy of $subtitle with its metadata, format data and format, but without cues and comments.
     */
    private static function emptyCopy(Subtitle $subtitle): Subtitle
    {
        $copy = (new Subtitle())
            ->setFormat($subtitle->findFormat())
            ->setSourceEncoding($subtitle->findSourceEncoding())
            ->setParseWarnings($subtitle->getParseWarnings());
        foreach ($subtitle->getAllMetadata() as $key => $value) {
            $copy->setMetadata((string)$key, $value);
        }
        foreach ($subtitle->toArray()["formatData"] as $key => $data) {
            $copy->setFormatData((string)$key, $data);
        }

        return $copy;
    }


    /**
     * Copies the times, styled lines and alignment, but not the identifier or format data of the secondary format.
     */
    private static function copySecondaryCue(SubtitleCue $cue, DualSubtitleOptions $options): SubtitleCue
    {
        $lines = $cue->getLines();
        if ($options->secondaryStyle !== null) {
            $openTag  = "<" . trim($options->secondaryStyle) . ">";
            $closeTag = "</" . $options->getSecondaryTagName() . ">";
            $lines    = array_map(fn (string $line): string => $openTag . $line . $closeTag, $lines);
        }

        return (new SubtitleCue($cue->getStart(), $cue->getEnd(), $lines))->setAlignment($cue->getAlignment());
    }
}
