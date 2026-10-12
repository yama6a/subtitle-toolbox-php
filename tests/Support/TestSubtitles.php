<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Builds small subtitles for tests and reads their cues back as plain arrays.
 */
final class TestSubtitles
{
    public static function imageCue(CueImage $image, SubtitleCue $cue): SubtitleCue
    {
        $image->applyTo($cue);

        return $cue;
    }


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
     * @param Subtitle|iterable<SubtitleCue> $cues
     *
     * @return list<array{float, float, string}> [start, end, text] of each cue
     */
    public static function describe(Subtitle|iterable $cues): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], self::cues($cues));
    }


    /**
     * @param Subtitle|iterable<SubtitleCue> $cues
     *
     * @return list<array{float, float, string, Alignment}> [start, end, text, alignment] of each cue
     */
    public static function describeWithAlignment(Subtitle|iterable $cues): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText(), $cue->getAlignment()], self::cues($cues));
    }


    /**
     * @param Subtitle|iterable<SubtitleCue> $cues
     *
     * @return list<array{float, float, list<string>}> [start, end, lines] of each cue
     */
    public static function describeLines(Subtitle|iterable $cues): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], self::cues($cues));
    }


    /**
     * @param Subtitle|iterable<SubtitleCue> $cues
     *
     * @return list<array{float, float}>
     */
    public static function times(Subtitle|iterable $cues): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], self::cues($cues));
    }


    /**
     * @param Subtitle|array<int, SubtitleCue> $cues
     * @return list<string>
     */
    public static function texts(Subtitle|array $cues): array
    {
        return array_map(fn (SubtitleCue $cue): string => $cue->getText(), self::cues($cues));
    }


    /**
     * @param Subtitle|list<ParseWarning> $warnings
     *
     * @return list<array{int, int, ParseWarningAction, string}> [line number, block index, action, message] of each warning
     */
    public static function warnings(Subtitle|array $warnings): array
    {
        return array_map(
            fn (ParseWarning $warning): array => [$warning->lineNumber, $warning->blockIndex, $warning->action, $warning->message],
            $warnings instanceof Subtitle ? $warnings->getParseWarnings() : $warnings
        );
    }


    /**
     * @param Subtitle|iterable<SubtitleCue> $cues
     *
     * @return list<SubtitleCue>
     */
    private static function cues(Subtitle|iterable $cues): array
    {
        return array_values($cues instanceof Subtitle ? $cues->getCues() : iterator_to_array($cues, false));
    }
}
