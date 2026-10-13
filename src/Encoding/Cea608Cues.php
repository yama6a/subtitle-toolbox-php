<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\SccRollUp;
use SubtitleToolbox\SubtitleCue;

/**
 * Turns CEA-608 byte pairs into cues with the scc format data. SCC files and MP4 c608 tracks use it.
 *
 * @internal
 */
final class Cea608Cues
{
    // CEA-608 sends one byte pair per field and frame at 29.97 fps, so a frame lasts 1001/30000 s.
    private const FRAME_RATE_NUMERATOR = 30000;
    private const FRAME_RATE_DIVISOR   = 1001;


    /**
     * Decodes the byte pairs of the code lines and returns a cue for each change of the displayed captions.
     *
     * @param list<array{int, list<int>}> $codeLines the start frame at 29.97 fps and the 16-bit words of each line, in time order
     * @return list<SubtitleCue>
     */
    public static function read(array $codeLines, SccReadOptions $options, float $lastCueDuration): array
    {
        $decoder = new Cea608Decoder($options->channel);
        $states  = $decoder->decode($codeLines);
        $byLine  = $options->rollUp === SccRollUp::Lines;

        $cues = [];
        foreach ($states as $index => $state) {
            if ($state["lines"] === [] || ($byLine && $state["mode"] === Cea608Decoder::MODE_ROLL_UP)) {
                continue;
            }

            $start = self::frameToSeconds($state["frame"]);
            if (!isset($states[$index + 1])) {
                $end = $start + $lastCueDuration;
            } else {
                $end = self::frameToSeconds($states[$index + 1]["frame"]);
                if ($end <= $start) {
                    continue;
                }
            }

            $cues[] = self::cue($start, $end, $state["lines"], $state["mode"]);
        }
        if ($byLine) {
            foreach ($decoder->rollUpLines() as $line) {
                $start = self::frameToSeconds($line["start"]);
                $end   = $line["end"] === null ? $start + $lastCueDuration : self::frameToSeconds($line["end"]);
                if ($end > $start) {
                    $cues[] = self::cue($start, $end, [$line], Cea608Decoder::MODE_ROLL_UP);
                }
            }
            usort($cues, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());
        }

        return $cues;
    }


    public static function secondsToFrame(float $seconds): int
    {
        return (int) round($seconds * self::FRAME_RATE_NUMERATOR / self::FRAME_RATE_DIVISOR);
    }


    private static function frameToSeconds(int $frame): float
    {
        return $frame * self::FRAME_RATE_DIVISOR / self::FRAME_RATE_NUMERATOR;
    }


    /**
     * @param list<array{row: int, column: int, text: string}> $lines
     */
    private static function cue(float $start, float $end, array $lines, string $mode): SubtitleCue
    {
        $cue = new SubtitleCue($start, $end, array_column($lines, "text"));
        $cue->setAlignment($lines[0]["row"] <= Cea608::MAX_LINES ? Alignment::TopCenter : Alignment::BottomCenter);
        $cue->setFormatData(Format::Scc->value, [
            "mode"    => $mode,
            "rows"    => array_column($lines, "row"),
            "columns" => array_column($lines, "column"),
        ]);

        return $cue;
    }
}
