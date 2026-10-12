<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Image\CueImage;

/**
 * @internal
 */
trait ShortCueMerging
{
    /**
     * Joins each short cue with the next cue, or else with the previous cue, when the joined cue fits the limits of $options.
     */
    public function mergeShortCues(?MergeShortCuesOptions $options = null): self
    {
        $options ??= new MergeShortCuesOptions();
        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $cues       = CueList::inStartOrder($this->cues);
        $joinedInto = new \SplObjectStorage();
        $index      = 0;
        while ($index < count($cues)) {
            if (!$options->mergeSameSpeakerAnyDuration && !self::shortCueMergingIsShort($cues[$index], $options)) {
                $index++;
                continue;
            }

            $lines = null;
            foreach ([$index, $index - 1] as $first) {
                if (isset($cues[$first], $cues[$first + 1])) {
                    $lines = self::shortCueMergingJoinLines($cues[$first], $cues[$first + 1], $options);
                }
                if ($lines !== null) {
                    break;
                }
            }
            if ($lines === null) {
                $index++;
                continue;
            }

            CueList::join([$cues[$first], $cues[$first + 1]], false, $joinedInto);
            $cues[$first]->setLines($lines);
            array_splice($cues, $first + 1, 1);
            $index = $first;
        }
        [$this->cues, $anchors] = CueList::removeJoined($this->cues, $anchors, $joinedInto);
        $this->comments         = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    private static function shortCueMergingIsShort(SubtitleCue $cue, MergeShortCuesOptions $options): bool
    {
        return Timecode::roundToMilliseconds($cue->getEnd() - $cue->getStart()) < Timecode::roundToMilliseconds($options->limits->minDuration)
            || ($options->minCharacters !== null && LineWrapper::visibleCharacters($cue->getLines()) < $options->minCharacters);
    }


    /**
     * Returns the lines of $first and $second joined into one cue, or null when the joined cue breaks a limit.
     *
     * @return ?list<string>
     */
    private static function shortCueMergingJoinLines(SubtitleCue $first, SubtitleCue $second, MergeShortCuesOptions $options): ?array
    {
        $speakers = CueList::speakers($first);
        if (CueImage::isImageCue($first) || CueImage::isImageCue($second) || !CueList::canJoin($first, $second)
            || ($options->mergeSameSpeakerAnyDuration && $speakers === [])) {
            return null;
        }

        $firstText = rtrim(Markup::plainText($first->getText()));
        if ($options->keepSentenceEnds && preg_match('/[.?!]$/', $firstText) === 1) {
            return null;
        }

        $duration = Timecode::roundToMilliseconds(max($first->getEnd(), $second->getEnd()) - $first->getStart());
        if (Timecode::roundToMilliseconds($second->getStart() - $first->getEnd()) > Timecode::roundToMilliseconds($options->maxGap)
            || (!$options->mergeSameSpeakerAnyDuration && $duration > Timecode::roundToMilliseconds($options->limits->maxDuration))) {
            return null;
        }

        $lines = LineWrapper::wrapToFit(self::shortCueMergingOneVoiceTag($first, $second, $speakers)
                                        ?? [...$first->getLines(), ...$second->getLines()],
                                        $options->limits->maxCharactersPerLine, $options->limits->maxLinesPerCue);

        return $lines !== null && LineWrapper::fitsCharactersPerSecond($lines, $duration, $options->limits->maxCharactersPerSecond)
            ? $lines
            : null;
    }


    /**
     * Returns the lines of both cues under the <v> tag of $first, or null.
     * Both cues must start with a <v> tag of the one speaker.
     *
     * @param list<string> $speakers
     *
     * @return ?list<string>
     */
    private static function shortCueMergingOneVoiceTag(SubtitleCue $first, SubtitleCue $second, array $speakers): ?array
    {
        $voiceTag = '/^' . Markup::VOICE_TAG . '/i';
        if (count($speakers) !== 1 || preg_match($voiceTag, $first->getText(), $match) !== 1
            || preg_match($voiceTag, $second->getText()) !== 1) {
            return null;
        }

        $lines    = preg_replace('/' . Markup::VOICE_TAG . '|<\/v>/i', "", [...$first->getLines(), ...$second->getLines()]);
        $lines[0] = $match[0] . $lines[0];
        if (preg_match('/<\/v>$/i', $second->getText()) === 1) {
            $lines[count($lines) - 1] .= "</v>";
        }

        return $lines;
    }
}
