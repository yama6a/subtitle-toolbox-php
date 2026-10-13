<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\Cea608Cues;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;

/**
 * Reads MCC (MacCaption) files of Telestream MacCaption and CaptionMaker, version 1.0 and 2.0.
 * Each line holds a time code and the caption data of one frame. The parser decodes the CEA-608 byte pairs of field 1
 * as SccParser does, so SccReadOptions apply.
 *
 * @see Mcc
 */
final class MccParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = SccReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Mcc->value;

    private const FILE_FORMAT = "File Format=MacCaption_MCC V";
    private const TIME_CODE_RATE = "Time Code Rate";

    // The CEA-608 null byte pair with odd parity fills a frame without data.
    private const PADDING = 0x8080;


    protected function read(string $content): Subtitle
    {
        $lines    = $this->lines($content);
        $version  = $this->readVersion($lines);
        $header   = [];
        $packets  = [];
        $rateCode = null;
        foreach ($lines as $lineIndex => $line) {
            $line       = trim($line);
            $lineNumber = $lineIndex + 1;
            if ($line === "" || str_starts_with($line, "//") || str_starts_with($line, self::FILE_FORMAT)) {
                continue;
            }
            if (preg_match('/^([A-Za-z][A-Za-z ]*)=(.*)$/', $line, $matches) === 1) {
                $header[trim($matches[1])] = trim($matches[2]);
                continue;
            }

            try {
                [$timeCode, $packet] = self::readLine($line, $lineNumber);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, null, [$line]);
                continue;
            }
            if ($packet !== null) {
                $rateCode ??= $packet[0];
                $packets[]  = [$timeCode, self::field1Words($packet[1])];
            }
        }

        [$frameRate, $dropFrame] = $this->frameRate($header, $rateCode);
        unset($header[self::TIME_CODE_RATE]);

        $subtitle = new Subtitle();
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["version" => $version, "frameRate" => $frameRate, "dropFrame" => $dropFrame, "header" => $header]);

        return $subtitle->addCues(Cea608Cues::read(self::codeLines($packets, $frameRate, $dropFrame), $this->formatOptions(), $this->options->lastCueDuration));
    }


    /**
     * @param list<string> $lines
     */
    private function readVersion(array $lines): string
    {
        $index = 0;
        while (trim($lines[$index]) === "") {
            $index++;
        }
        if (preg_match('/^' . preg_quote(self::FILE_FORMAT, "/") . '(\d+\.\d+)$/', trim($lines[$index]), $matches) !== 1) {
            throw new ParsingException("An MCC file must start with the line \"" . self::FILE_FORMAT . "2.0\" or \"" . self::FILE_FORMAT . "1.0\".", $index + 1);
        }
        if (!in_array($matches[1], Mcc::VERSIONS, true)) {
            throw new ParsingException("The MCC version $matches[1] is unknown. The parser reads " . implode(" and ", Mcc::VERSIONS) . ".", $index + 1);
        }

        return $matches[1];
    }


    /**
     * @return array{int, ?array{int, list<array{int, bool, int}>}} the time code as HHMMSSFF digits, and the CDP or null
     */
    private static function readLine(string $line, int $lineNumber): array
    {
        // Version 2.0 may add the field and the line number after the frames, for example 01:00:00:00.1,9.
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})[:;](\d{2})(?:\.[01](?:,\d+)?)?[ \t]+(\S+)$/', $line, $matches) !== 1) {
            throw new ParsingException("The MCC line \"$line\" does not start with a time code.", $lineNumber);
        }

        return [(int) ($matches[1] . $matches[2] . $matches[3] . $matches[4]), Mcc::readPacket(Mcc::decodeHex($matches[5], $lineNumber), $lineNumber)];
    }


    /**
     * Returns the frame rate and the drop-frame flag of the Time Code Rate field.
     * The CDP frame rate code of the first packet tells 23.976 from 24, 29.97 from 30 and 59.94 from 60.
     *
     * @return array{string, bool}
     */
    private function frameRate(array $header, ?int $code): array
    {
        $rate = $header[self::TIME_CODE_RATE] ?? null;
        if ($rate === null || preg_match('/^(24|25|30|50|60)(DF)?$/i', $rate, $matches) !== 1 || (isset($matches[2]) && !in_array($matches[1], ["30", "60"], true))) {
            $message = $rate === null ? "The MCC file has no Time Code Rate line." : "The MCC Time Code Rate \"$rate\" is unknown.";
            if (!$this->options->lenient) {
                throw new ParsingException($message);
            }
            $this->warn("$message The parser read 30DF.", null, null, [], ParseWarningAction::Repaired);
            $matches = [1 => "30", 2 => "DF"];
        }

        $labels    = (int) $matches[1];
        $dropFrame = isset($matches[2]);
        foreach (Mcc::FRAME_RATES as $name => [$rateLabels, , $denominator, $rateCode]) {
            if ($rateLabels === $labels && $rateCode === $code && ($denominator !== 1 || !$dropFrame)) {
                return [(string) $name, $dropFrame];
            }
        }

        return [$dropFrame ? ($labels === 30 ? "29.97" : "59.94") : (string) $labels, $dropFrame];
    }


    /**
     * Joins the CEA-608 field 1 byte pairs of consecutive frames into code lines, as an SCC file holds them.
     * A null pair ends a line, and so does a gap of more than one frame at 29.97 fps.
     *
     * @return list<array{int, list<int>}>
     */
    private static function codeLines(array $packets, string $frameRate, bool $dropFrame): array
    {
        [$labels, $numerator, $denominator] = Mcc::FRAME_RATES[$frameRate];
        $byFrame = [];
        foreach ($packets as [$timeCode, $words]) {
            if ($words !== []) {
                $frame             = self::frameNumber($timeCode, $labels, $dropFrame);
                $byFrame[$frame] = [...$byFrame[$frame] ?? [], ...$words];
            }
        }
        ksort($byFrame);

        $codeLines = [];
        $line      = null;
        foreach ($byFrame as $frame => $words) {
            $frame608 = Cea608Cues::secondsToFrame($frame * $denominator / $numerator);
            foreach ($words as $word) {
                if (($word & 0x7F7F) === (self::PADDING & 0x7F7F)) {
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


    /**
     * @param list<array{int, bool, int}> $triplets
     * @return list<int>
     */
    private static function field1Words(array $triplets): array
    {
        $words = [];
        foreach ($triplets as [$type, $valid, $word]) {
            if ($valid && $type === Mcc::NTSC_FIELD_1) {
                $words[] = $word;
            }
        }

        return $words;
    }


    /**
     * Counts the frames of a time code. Drop-frame time code skips 2 labels per minute at 30 labels and 4 at 60,
     * except every tenth minute.
     *
     * @param int $timeCode the digits HHMMSSFF
     */
    private static function frameNumber(int $timeCode, int $labels, bool $dropFrame): int
    {
        $totalMinutes = intdiv($timeCode, 1000000) * 60 + intdiv($timeCode, 10000) % 100;
        [$seconds, $frames] = [intdiv($timeCode, 100) % 100, $timeCode % 100];
        $count        = ($totalMinutes * 60 + $seconds) * $labels + $frames;

        return $dropFrame ? $count - intdiv($labels, 15) * ($totalMinutes - intdiv($totalMinutes, 10)) : $count;
    }
}
