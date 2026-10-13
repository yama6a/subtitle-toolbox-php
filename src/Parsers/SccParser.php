<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\Cea608Cues;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Subtitle;

/**
 * Reads Scenarist Closed Captions: CEA-608 byte pairs, one pair per frame at 29.97 fps, after a SMPTE time code.
 * Cea608Decoder turns the byte pairs into the captions on screen, and each change of them starts a new cue.
 *
 * @see http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/SCC_FORMAT.HTML
 * @see https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml
 */
final class SccParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = SccReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Scc->value;

    /** @internal */
    public const HEADER = "Scenarist_SCC V1.0";

    // SCC time codes count 30 frame labels per second at 29.97 fps.
    private const NOMINAL_FRAME_RATE = 30;

    // The null byte pair with odd parity that fills frames without data.
    private const PADDING = 0x8080;


    protected function read(string $content): Subtitle
    {
        $codeLines = $this->readCodeLines($this->lines($content), $dropFrame);
        $subtitle  = new Subtitle();
        if ($dropFrame !== null) {
            $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["dropFrame" => $dropFrame]);
        }

        return $subtitle->addCues(Cea608Cues::read($codeLines, $this->formatOptions(), $this->options->lastCueDuration));
    }


    /**
     * Converts an SMPTE time code at 29.97 fps to a frame count.
     * A semicolon before the frames marks drop-frame time code.
     * It skips the frame numbers 00 and 01 at the start of each minute, except every tenth minute.
     */
    private static function timecodeToFrames(int $hours, int $minutes, int $seconds, int $frames, bool $dropFrame): int
    {
        $count = (($hours * 60 + $minutes) * 60 + $seconds) * self::NOMINAL_FRAME_RATE + $frames;
        if ($dropFrame) {
            $totalMinutes = $hours * 60 + $minutes;
            $count       -= 2 * ($totalMinutes - intdiv($totalMinutes, 10));
        }

        return $count;
    }


    /**
     * @return list<array{int, list<int>}> the start frame and the 16-bit words of each line, in time order
     */
    private function readCodeLines(array $rawLines, ?bool &$dropFrame): array
    {
        $dropFrame  = null;
        $header     = null;
        $codeLines  = [];
        $blockIndex = -1;
        foreach ($rawLines as $lineIndex => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }
            $blockIndex++;
            $lineNumber = $lineIndex + 1;
            if ($header === null) {
                $header = $rawLine;
                if ($header !== self::HEADER) {
                    throw new ParsingException("An SCC file must start with the line \"" . self::HEADER . "\".", $lineNumber);
                }
                continue;
            }

            try {
                [$start, $lineDropFrame, $pairs] = self::readTimeCode($rawLine, $lineNumber);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $blockIndex, [$rawLine]);
                continue;
            }

            $dropFrame ??= $lineDropFrame;
            $words       = [];
            foreach ($pairs as $pair) {
                try {
                    $words[] = self::readBytePair($pair, $lineNumber);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $lineNumber, $blockIndex, [$rawLine]);
                    // Padding keeps the frame of each later byte pair of the line.
                    $words[] = self::PADDING;
                }
            }
            $codeLines[] = [$start, $words];
        }

        // Some writers emit lines out of time order, see https://github.com/pbs/pycaption/issues/352.
        // A decoder plays them in time order.
        usort($codeLines, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $codeLines;
    }


    /**
     * @return array{int, bool, list<string>} the start frame, true for drop-frame time code, and the byte pairs
     */
    private static function readTimeCode(string $rawLine, int $lineNumber): array
    {
        if (!preg_match("/^(\d{2}):(\d{2}):(\d{2})([:;])(\d{2})(?:\s+(.*))?$/", $rawLine, $matches)) {
            throw new ParsingException("The SCC line \"$rawLine\" does not start with a time code.", $lineNumber);
        }

        $dropFrame = $matches[4] === ";";

        return [self::timecodeToFrames((int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[5], $dropFrame),
                $dropFrame, preg_split("/\s+/", $matches[6] ?? "", -1, PREG_SPLIT_NO_EMPTY)];
    }


    private static function readBytePair(string $pair, int $lineNumber): int
    {
        if (!preg_match("/^[0-9a-fA-F]{4}$/", $pair)) {
            throw new ParsingException("The SCC line has the invalid byte pair \"$pair\".", $lineNumber);
        }

        return hexdec($pair);
    }
}
