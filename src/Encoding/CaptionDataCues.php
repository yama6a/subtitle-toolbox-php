<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\FormatOptionsList;
use SubtitleToolbox\Parsers\Mcc;
use SubtitleToolbox\Parsers\Options\MccReadOptions;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\SubtitleCue;

/**
 * Turns the cc_data triplets of caption distribution packets into cues. MCC files and MP4 c708 tracks hold them.
 * The cues of the CEA-708 service of MccReadOptions win when the service has any. Otherwise the cues come from the
 * CEA-608 data of field 1.
 *
 * @internal
 */
final class CaptionDataCues
{
    // The CEA-608 null byte pair with odd parity fills a frame without data.
    private const NULL_PAIR = 0x8080;


    /**
     * @param list<array{float, string}> $frames the time in seconds and the triplets of each packet, as Mcc::readCdp() returns them, in time order
     * @return list<SubtitleCue>
     */
    public static function read(array $frames, ReadOptions $options): array
    {
        $service = (FormatOptionsList::find($options->formats, MccReadOptions::class) ?? new MccReadOptions())->service;
        if ($service !== null) {
            $cues = self::cea708Cues((new Cea708Decoder($service))->decode($frames), $options->lastCueDuration);
            if ($cues !== []) {
                return $cues;
            }
        }

        return Cea608Cues::read(self::codeLines($frames), FormatOptionsList::find($options->formats, SccReadOptions::class) ?? new SccReadOptions(),
                                $options->lastCueDuration);
    }


    /**
     * @param list<array{time: float, lines: list<string>, alignment: ?\SubtitleToolbox\Alignment}> $states
     * @return list<SubtitleCue>
     */
    private static function cea708Cues(array $states, float $lastCueDuration): array
    {
        $cues = [];
        foreach ($states as $index => $state) {
            if ($state["lines"] === []) {
                continue;
            }
            $end = isset($states[$index + 1]) ? $states[$index + 1]["time"] : $state["time"] + $lastCueDuration;
            if ($end > $state["time"]) {
                $cues[] = (new SubtitleCue($state["time"], $end, $state["lines"]))->setAlignment($state["alignment"]);
            }
        }

        return $cues;
    }


    /**
     * Joins the CEA-608 field 1 byte pairs of consecutive frames into code lines, as an SCC file holds them.
     * A null pair ends a line, and so does a gap of more than one frame at 29.97 fps.
     *
     * @param list<array{float, string}> $frames
     * @return list<array{int, list<int>}>
     */
    private static function codeLines(array $frames): array
    {
        $codeLines = [];
        $line      = null;
        foreach ($frames as [$time, $triplets]) {
            $frame608 = Cea608Cues::secondsToFrame($time);
            for ($offset = 0; $offset < strlen($triplets); $offset += 3) {
                if (ord($triplets[$offset]) !== Mcc::NTSC_FIELD_1) {
                    continue;
                }
                $word = unpack("n", $triplets, $offset + 1)[1];
                if (($word & 0x7F7F) === (self::NULL_PAIR & 0x7F7F)) {
                    $line = null;
                    continue;
                }
                if ($line === null || $frame608 > $codeLines[$line][0] + count($codeLines[$line][1]) + 1) {
                    $codeLines[] = [$frame608, []];
                    $line        = count($codeLines) - 1;
                }
                $codeLines[$line][1][] = $word;
            }
        }

        return $codeLines;
    }
}
