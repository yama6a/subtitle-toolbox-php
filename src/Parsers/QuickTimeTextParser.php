<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Reads QuickTime text: {QTtext} and text descriptors, then samples that each start at a timestamp such as [00:00:01.12].
 * A sample ends at the next timestamp. A sample without text is a gap.
 *
 * @see QuickTimeText
 */
final class QuickTimeTextParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::QuickTimeText->value;


    protected function read(string $content): Subtitle
    {
        $lines  = $this->lines($content);
        $header = [];
        while ($lines !== [] && !preg_match(QuickTimeText::TIMESTAMP_REGEX, trim($lines[0]))) {
            $header[] = array_shift($lines);
        }
        $this->checkHeader($header);

        $timeScale = self::timeScale($header);
        $relative  = strtolower(QuickTimeText::findDescriptor($header, "timeStamps") ?? "") === "relative";
        $style     = QuickTimeText::PLAIN;
        QuickTimeText::readLines($header, $style);

        $samples  = [];
        $previous = 0.0;
        $paired   = false;
        $blank    = false;
        foreach ($lines as $offset => $line) {
            $lineNumber = count($header) + $offset + 1;
            if (!preg_match(QuickTimeText::TIMESTAMP_REGEX, trim($line), $matches)) {
                $blank = $blank || (trim($line) === "" && count($samples) > 1 && end($samples)["lines"] === []);
                if (trim($line) !== "" && $samples !== []) {
                    $samples[array_key_last($samples)]["lines"][] = $line;
                    $blank = false;
                }
                continue;
            }

            try {
                $time = $this->time($matches, $timeScale, $lineNumber) + ($relative ? $previous : 0.0);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, null, [$line]);
                continue;
            }
            // Subtitle Edit writes an empty line after the end timestamp of each cue.
            $paired    = $paired || $blank;
            $blank     = false;
            $previous  = $time;
            $rest      = substr(trim($line), strlen($matches[0]));
            $samples[] = ["time" => $time, "line" => $lineNumber, "lines" => trim($rest) === "" ? [] : [$rest]];
        }

        $cues = [];
        foreach ($samples as $index => $sample) {
            if ($sample["lines"] === []) {
                continue;
            }
            $end = $samples[$index + 1]["time"] ?? Timecode::roundToMilliseconds($sample["time"] + $this->options->lastCueDuration);
            try {
                [$start, $end] = $this->orderedTimes($sample["time"], $end, $sample["line"], null, $sample["lines"]);
            } catch (ParsingException $exception) {
                $this->fail($exception, $sample["line"], null, $sample["lines"]);
                continue;
            }
            $cueLines = QuickTimeText::readLines($sample["lines"], $style);
            if ($cueLines !== []) {
                $cue = new SubtitleCue($start, $end, $cueLines);
                // The source keeps descriptors such as {font:Geneva} for the round trip.
                if (preg_grep(QuickTimeText::DESCRIPTOR_REGEX, $sample["lines"]) !== []) {
                    $cue->setFormatData(self::FORMAT_DATA_KEY, ["source" => $sample["lines"]]);
                }
                $cues[] = $cue;
            }
        }

        return (new Subtitle())
            ->setFormatData(self::FORMAT_DATA_KEY, ["header" => $header] + ($paired ? ["pairedTimestamps" => true] : []))
            ->addCues($cues);
    }


    /**
     * @param list<string> $header
     */
    private function checkHeader(array $header): void
    {
        if (preg_match('/^\s*\{QTtext\}/i', $header[0] ?? "")) {
            return;
        }

        $message = "The file does not start with {QTtext}.";
        if (!$this->options->lenient) {
            throw new ParsingException($message, 1);
        }
        $this->warn("$message The parser read it as QuickTime text.", 1, null, [], ParseWarningAction::Repaired);
    }


    /**
     * Returns the {timeScale} of the header, or null when the fraction of a timestamp is a decimal fraction of a second.
     *
     * @param list<string> $header
     *
     * @internal
     */
    public static function timeScale(array $header): ?int
    {
        $value = QuickTimeText::findDescriptor($header, "timeScale");

        return $value !== null && preg_match('/^\d+$/D', $value) === 1 && (int) $value > 0 ? (int) $value : null;
    }


    /**
     * @param array<int, string> $matches the groups of TIMESTAMP_REGEX
     */
    private function time(array $matches, ?int $timeScale, int $lineNumber): float
    {
        $fraction = $matches[4] ?? "";
        $units    = $timeScale ?? 10 ** strlen($fraction);
        if ($fraction !== "" && (int) $fraction >= $units) {
            $message = "The timestamp \"$matches[0]\" counts $fraction units, but the time scale is $units per second.";
            if (!$this->options->lenient) {
                throw new ParsingException($message, $lineNumber);
            }
            $this->warn("$message The parser added them to the seconds.", $lineNumber, null, [$matches[0]], ParseWarningAction::Repaired);
        }

        $seconds = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3] + ($fraction === "" ? 0 : (int) $fraction / $units);

        return self::boundedTime(Timecode::roundToMilliseconds($seconds), trim($matches[0], "[]"), $lineNumber);
    }
}
