<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SbvParser extends SubtitleParser
{
    private const LOOSE_TIME = '(\d+):([0-5]?\d):([0-5]?\d)[.,:](\d{1,4})';

    // Lenient mode reads the separators and fractions that hand-edited files use, such as "0:00:07,98.0:00:11,3".
    private const LOOSE_TIMING_LINE = '/^' . self::LOOSE_TIME . '\s*[.,]\s*' . self::LOOSE_TIME . '$/';

    protected function read(string $content): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $index      = 0;
        $rules      = $this->blockRules();
        $blocks     = $this->joinCueTextBlocks($this->splitAtEmptyLines($this->lines($content)), $rules);
        foreach ($blocks as $lineNumber => $rawLines) {
            $cues = $this->parseRepairedBlock($rawLines, $lineNumber, $index, $rules,
                                              fn (array $part, int $partLine): SubtitleCue => $this->parseCueBlock($part, $index, $partLine));
            array_push($parsedCues, ...$cues);
            $index++;
        }

        return $subtitle->addCues($parsedCues);
    }


    private function parseCueBlock(array $rawLines, int $index, int $lineNumber): SubtitleCue
    {
        if ($this->options->lenient && !$this->isStrictTimingLine($rawLines[0])
            && preg_match(self::LOOSE_TIMING_LINE, $rawLines[0], $matches)) {
            [$start, $end] = $this->orderedTimes(
                $this->looseSeconds(array_slice($matches, 1, 4), $lineNumber),
                $this->looseSeconds(array_slice($matches, 5, 4), $lineNumber),
                $lineNumber,
                $index,
                $rawLines
            );
            $cue = new SubtitleCue($start, $end, array_map(Markup::escapeText(...), array_slice($rawLines, 1)));
            $this->warn(
                "Block #$index has a timing line with other separators or fraction digits than SBV uses. The parser read it.",
                $lineNumber,
                $index,
                $rawLines,
                ParseWarningAction::Repaired
            );

            return $cue;
        }

        if (substr_count($rawLines[0], ",") !== 1) {
            throw new ParsingException("Block #$index has no timing line on its first line. The line is " . self::quote($rawLines[0]) . ".", $lineNumber);
        }

        $times = explode(",", $rawLines[0]);

        [$start, $end] = $this->orderedTimes(
            $this->secondsFromString($times[0], $lineNumber),
            $this->secondsFromString($times[1], $lineNumber),
            $lineNumber,
            $index,
            $rawLines
        );

        return new SubtitleCue($start, $end, array_map(Markup::escapeText(...), array_slice($rawLines, 1)));
    }


    private function blockRules(): CueBlockRules
    {
        return new CueBlockRules($this->isTimingLine(...), false);
    }


    private function isTimingLine(string $line): bool
    {
        return preg_match("/^\d+:\d\d:\d\d\.\d+,/", $line) === 1
            || ($this->options->lenient && preg_match(self::LOOSE_TIMING_LINE, $line) === 1);
    }


    private function isStrictTimingLine(string $line): bool
    {
        return preg_match("/^\d+:[0-5]\d:[0-5]\d\.\d{3}\s*,\s*\d+:[0-5]\d:[0-5]\d\.\d{3}$/", $line) === 1;
    }


    /**
     * @param list<string> $fields hours, minutes, seconds and fraction digits
     */
    private function looseSeconds(array $fields, int $lineNumber): float
    {
        [$hours, $minutes, $seconds, $fraction] = $fields;
        $time = Timecode::roundToMilliseconds(Timecode::toSeconds((int) $hours, (int) $minutes, (int) $seconds, $fraction));

        return self::boundedTime($time, "$hours:$minutes:$seconds.$fraction", $lineNumber);
    }


    private function secondsFromString(string $timeString, int $lineNumber): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d+):([0-5]\d):([0-5]\d)\.(\d{3})$/", $timeString, $matches)) {
            throw new ParsingException("The time \"$timeString\" is not valid.", $lineNumber);
        }

        return self::boundedTime(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]), $timeString, $lineNumber);
    }
}
