<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\AlignmentGrid;

/**
 * The layout and the control codes of PAC files by Screen Electronics. PAC has no public spec.
 * A file holds a header of 20 bytes, then one record per subtitle and an end block from FFh.
 * Each record starts with 00h, the subtitle number in 2 bytes, 60h, the time codes, the text length and the vertical position.
 * Each text line starts with FEh, an alignment byte and 03h.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Pac.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Pac.cs), MIT.
 *
 * @internal
 */
final class Pac
{
    public const HEADER = "\x01" . "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
    public const FOOTER = "\xFF\0\0\0\0\0\0\0\0\0\0\0\x11\0dummy end of file";

    public const RECORD_START = "\0";
    public const RECORD_MARK  = 0x60;
    public const END          = 0xFF;

    // Bytes from the record start: number, mark, time code in, time code out, text length, vertical position and text.
    public const RECORD_NUMBER   = 1;
    public const RECORD_TIME_IN  = 4;
    public const RECORD_TIME_OUT = 8;
    public const RECORD_LENGTH   = 12;
    public const RECORD_VERTICAL = 14;
    public const RECORD_TEXT     = 15;

    public const LINE_START = 0xFE;
    public const LINE_END   = 0x03;

    public const ALIGN_RIGHT  = 0;
    public const ALIGN_LEFT   = 1;
    public const ALIGN_CENTER = 2;
    public const ALIGN_MASK   = 0x03;

    // The vertical positions of new subtitles. Rows 0 to 4 are the top, 5 to 8 the middle and 9 to 11 the bottom.
    public const TOP_ROW    = 1;
    public const MIDDLE_ROW = 6;
    public const LAST_ROW   = 11;

    public const FIRST_MIDDLE_ROW = 5;
    public const FIRST_BOTTOM_ROW = 9;

    public const ITALIC_ON  = "<";
    public const ITALIC_OFF = ">";

    public const DOUBLE_BYTE = "\x1FW16.";
    public const UNICODE     = "\x1F\xEF\xBB\xBF";
    public const UNICODE_END = ".";
    public const UNICODE_DOT = "\xFF";

    // 1Fh "C" and a digit is a 3-byte code. 1Fh "C" and another byte starts a comment up to the end of the text.
    private const CODE        = "\x1F";
    private const CODE_LENGTH = 3;

    public const FRAME_RATE = 25;

    // A frame number from 25 to 29 marks a file at 30 fps.
    public const HIGH_FRAME_RATE = 30;

    // Subtitle Edit keeps Latin letters and digits of Arabic and Hebrew lines in the order of display.
    public const ARABIC_LATIN = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    public const HEBREW_LATIN = "0123456789abcdefghijklmnopqrstuvwxyz";


    /**
     * Splits the text of a record into lines. Each line holds its alignment byte and runs of single-byte, double-byte
     * and Unicode bytes. Bytes before the first line start are not text.
     *
     * @return list<array{alignment: int, runs: list<array{string, string}>}> each run as a mode and its bytes
     */
    public static function lines(string $text): array
    {
        $lines  = [];
        $line   = -1;
        $mode   = "single";
        $length = strlen($text);
        $index  = strpos($text, chr(self::LINE_START));
        while ($index !== false && $index < $length) {
            $byte = $text[$index];
            if (ord($byte) === self::LINE_START) {
                $lines[++$line] = ["alignment" => ord($text[$index + 1] ?? "\0"), "runs" => []];
                [$mode, $index] = ["single", $index + 3];
                continue;
            }

            if (str_starts_with(substr($text, $index, strlen(self::DOUBLE_BYTE)), self::DOUBLE_BYTE)) {
                [$mode, $index] = ["double", $index + strlen(self::DOUBLE_BYTE)];
                continue;
            }
            if (substr($text, $index, strlen(self::UNICODE)) === self::UNICODE) {
                [$mode, $index] = ["unicode", $index + strlen(self::UNICODE)];
                continue;
            }
            if ($mode === "unicode" && $byte === self::UNICODE_END) {
                [$mode, $index] = ["single", $index + 1];
                continue;
            }
            if ($byte === self::CODE && ($text[$index + 1] ?? "") === "C") {
                if (preg_match('/\A[0-9]/', $text[$index + 2] ?? "") !== 1) {
                    break;
                }
                $index += self::CODE_LENGTH;
                continue;
            }

            $runs = $lines[$line]["runs"];
            if ($runs === [] || $runs[count($runs) - 1][0] !== $mode) {
                $runs[] = [$mode, ""];
            }
            $runs[count($runs) - 1][1] .= $byte;
            $lines[$line]["runs"] = $runs;
            $index++;
        }

        return $lines;
    }


    /**
     * Returns the cue alignment of a vertical position and an alignment byte.
     */
    public static function alignment(int $verticalPosition, int $alignment): Alignment
    {
        $row = match (true) {
            $verticalPosition < self::FIRST_MIDDLE_ROW => AlignmentGrid::TOP_ROW,
            $verticalPosition < self::FIRST_BOTTOM_ROW => AlignmentGrid::MIDDLE_ROW,
            default                                    => AlignmentGrid::BOTTOM_ROW,
        };
        $column = match ($alignment & self::ALIGN_MASK) {
            self::ALIGN_LEFT  => AlignmentGrid::LEFT,
            self::ALIGN_RIGHT => AlignmentGrid::RIGHT,
            default           => AlignmentGrid::CENTER,
        };

        return AlignmentGrid::of($row, $column);
    }


    /**
     * Swaps the parentheses and brackets and reverses each run of the characters in $latin, as Subtitle Edit does for Arabic and
     * Hebrew lines. Doing it twice gives the line back.
     */
    public static function rightToLeft(string $line, string $latin): string
    {
        $line = strtr($line, "()[]", ")(][");

        return preg_replace_callback('/[' . preg_quote($latin, "/") . ']+/', fn (array $run): string => strrev($run[0]), $line);
    }
}
