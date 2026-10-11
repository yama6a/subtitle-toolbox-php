<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Builds small subtitles for tests and reads their cues back as plain arrays.
 */
final class TestSubtitles
{
    /**
     * Builds a subtitle from cues or from [start, end, text] tuples, for example [[1.0, 2.5, "Hello"]].
     * The text can also be a list of lines.
     *
     * @param list<SubtitleCue|array{float|int, float|int, string|list<string>}> $cues
     */
    public static function fromCues(array $cues): Subtitle
    {
        return (new Subtitle())->addCues(array_map(
            fn (SubtitleCue|array $cue): SubtitleCue => $cue instanceof SubtitleCue ? $cue : new SubtitleCue($cue[0], $cue[1], $cue[2]),
            $cues
        ));
    }


    /**
     * Builds a subtitle from [start, end] pairs. Cue N gets the text "textN".
     *
     * @param array<int, array{float|int, float|int}> $times
     */
    public static function fromTimes(array $times): Subtitle
    {
        $cues = [];
        foreach ($times as $index => [$start, $end]) {
            $cues[] = new SubtitleCue($start, $end, "text$index");
        }

        return (new Subtitle())->addCues($cues);
    }


    /**
     * Builds a subtitle with one cue for each text. Each cue lasts 1 second. Cue N starts at $start + N * $step.
     *
     * @param list<string|list<string>> $texts
     */
    public static function fromTexts(array $texts, float $start = 0.0, float $step = 1.0): Subtitle
    {
        $cues = [];
        foreach (array_values($texts) as $index => $text) {
            $cues[] = new SubtitleCue($start + $index * $step, $start + $index * $step + 1, $text);
        }

        return (new Subtitle())->addCues($cues);
    }


    /**
     * @return list<array{float, float, string}>|list<array{float, float, string, int}> [start, end, text] of each
     *         cue, with the alignment as 4th value when $withAlignment is true
     */
    public static function describe(Subtitle $subtitle, bool $withAlignment = false): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => $withAlignment
                ? [$cue->getStart(), $cue->getEnd(), $cue->getText(), $cue->getAlignment()->value]
                : [$cue->getStart(), $cue->getEnd(), $cue->getText()],
            array_values($subtitle->getCues())
        );
    }


    /**
     * @return list<array{float, float}>
     */
    public static function times(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], array_values($subtitle->getCues()));
    }


    /**
     * @param Subtitle|array<int, SubtitleCue> $cues
     * @return list<string>
     */
    public static function texts(Subtitle|array $cues): array
    {
        return array_map(fn (SubtitleCue $cue): string => $cue->getText(), array_values($cues instanceof Subtitle ? $cues->getCues() : $cues));
    }
}
