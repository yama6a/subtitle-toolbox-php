<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SpruceStlReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Reads the Spruce STL text subtitles of DVD Studio Pro and Spruce DVDMaestro, such as "00:02:17:10 , 00:02:20:10 , Hello|world".
 *
 * @see SpruceStl
 */
final class SpruceStlParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = SpruceStlReadOptions::class;
    public const FORMAT_DATA_KEY = Format::SpruceStl->value;

    private const TIMECODE = '(\d{2,}):([0-5]\d):([0-5]\d):(\d{2})';

    private const CUE_REGEX = '/^' . self::TIMECODE . '([ \t]*,[ \t]*)' . self::TIMECODE . '[ \t]*,[ \t]*(.*)$/';

    private const LINE_BREAK_REGEX = '/[ \t]*\|[ \t]*/';

    private const TOGGLE_REGEX = '/(\^[BIU])/';


    protected function read(string $content): Subtitle
    {
        $lines     = $this->lines($content);
        $frameRate = $this->frameRate($lines);
        $state     = SpruceStl::DEFAULT_STATE;
        $fileData  = ["frameRate" => $frameRate, "header" => []];
        $cues      = [];
        $comments  = [];
        $commands  = [];
        $inHeader  = true;
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed    = trim($line);
            if (preg_match(self::CUE_REGEX, $trimmed, $matches)) {
                $inHeader = false;
                try {
                    $start = $this->time(array_slice($matches, 1, 4), $frameRate, $lineNumber);
                    $end   = $this->time(array_slice($matches, 6, 4), $frameRate, $lineNumber);
                    [$start, $end] = $this->orderedTimes($start, $end, $lineNumber, null, [$line]);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $lineNumber, null, [$line]);
                    continue;
                }

                $fileData["separator"] ??= $matches[5];
                if (!isset($fileData["lineBreak"]) && preg_match(self::LINE_BREAK_REGEX, $matches[10], $lineBreak)) {
                    $fileData["lineBreak"] = $lineBreak[0];
                }
                $cue = (new SubtitleCue($start, $end, $this->cueLines($matches[10], $state)))->setAlignment(SpruceStl::alignment($state));
                if ($commands !== []) {
                    $cue->setFormatData(self::FORMAT_DATA_KEY, ["commands" => $commands]);
                    $commands = [];
                }
                $cues[] = $cue;
                continue;
            }

            if ($trimmed !== "" && !str_starts_with($trimmed, "$") && !str_starts_with($trimmed, "//")) {
                $this->fail(new ParsingException("The line " . self::quote($trimmed) . " is no Spruce STL subtitle, command or comment.", $lineNumber), $lineNumber, null, [$line]);
                continue;
            }

            $state = SpruceStl::apply($state, $line);
            if ($inHeader) {
                $fileData["header"][] = $line;
            } elseif (str_starts_with($trimmed, "//")) {
                $comments[] = [substr($trimmed, 2), count($cues)];
            } elseif ($trimmed !== "") {
                $commands[] = $line;
            }
        }

        $subtitle = (new Subtitle())->setFormatData(self::FORMAT_DATA_KEY, $fileData);

        return CommentAnchors::addParsed($subtitle, $cues, $comments);
    }


    /**
     * Returns the frame rate of the options. Without it, a frame number of 25 to 29 means 30 fps, as on NTSC DVDs, and
     * otherwise the parser takes 25 fps, as on PAL DVDs. The file has no field for the frame rate.
     * Adapted from mantas-done/subtitles, src/Code/Converters/StlConverter.php
     * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/src/Code/Converters/StlConverter.php), MIT.
     *
     * @param list<string> $lines
     */
    private function frameRate(array $lines): float
    {
        $frameRate = $this->formatOptions()->frameRate;
        if ($frameRate !== null) {
            return $frameRate;
        }

        $highest = null;
        foreach ($lines as $line) {
            if (preg_match(self::CUE_REGEX, trim($line), $matches)) {
                $highest = max($highest ?? 0, (int) $matches[4], (int) $matches[9]);
            }
        }
        $frameRate = $highest !== null && $highest >= 25 ? 30.0 : 25.0;
        if ($highest !== null && $this->options->lenient) {
            $this->warn("The file does not give its frame rate. The parser read the frames at $frameRate fps.", null, null, [], ParseWarningAction::Repaired);
        }

        return $frameRate;
    }


    /**
     * @param list<string> $fields hours, minutes, seconds and frames
     */
    private function time(array $fields, float $frameRate, int $lineNumber): float
    {
        [$hours, $minutes, $seconds, $frames] = array_map(intval(...), $fields);
        $text = implode(":", $fields);
        if ($frames >= ceil($frameRate)) {
            throw new ParsingException("The frame number in \"$text\" is not below the frame rate of $frameRate fps.", $lineNumber);
        }

        return self::boundedTime(Timecode::roundToMilliseconds($hours * 3600 + $minutes * 60 + $seconds + $frames / $frameRate), $text, $lineNumber);
    }


    /**
     * Reads the lines of a cue. ^B, ^I and ^U switch bold, italics and underline on or off, from the state of the commands.
     *
     * @param array{b: bool, i: bool, u: bool} $state
     *
     * @return list<string>
     */
    private function cueLines(string $text, array $state): array
    {
        $style = ["b" => $state["b"], "i" => $state["i"], "u" => $state["u"]];
        $lines = [];
        foreach (preg_split(self::LINE_BREAK_REGEX, $text) as $rawLine) {
            $runs = [];
            foreach (preg_split(self::TOGGLE_REGEX, trim($rawLine), -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $token) {
                if ($index % 2 === 1) {
                    $flag         = strtolower($token[1]);
                    $style[$flag] = !$style[$flag];
                } elseif ($token !== "") {
                    $runs[] = [$token, $style];
                }
            }
            $line = trim(StyleRuns::toMarkup($runs, true));
            if ($line !== "") {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
