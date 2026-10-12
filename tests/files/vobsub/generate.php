<?php

declare(strict_types=1);

// Writes the VobSub test fixtures in this folder. Run: php tests/files/vobsub/generate.php
// The encoder here is separate from src/Parsers/VobSubParser.php, so the tests compare two implementations.

require_once __DIR__ . "/../ocr/generator/TextBitmap.php";

use SubtitleToolbox\Ocr\TextBitmap;

const PACK_SIZE = 2048;
const PALETTE   = "000000, f0f0f0, cccccc, 999999, 3333fa, 1111bb, fa3333, bb1111, " .
                  "33fa33, 11bb11, fafa33, bbbb11, fa33fa, bb11bb, 33fafa, 11bbbb";

/**
 * A box: 2 pixels of background (0) at the edge, a 1 pixel outline of emphasis 2 (3), and inside it
 * 8 pixel wide stripes of pattern (1) and emphasis 1 (2) that swap on every line.
 */
function box(int $width, int $height): array
{
    $rows = [];
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rows[$y][$x] = match (true) {
                $x < 2 || $y < 2 || $x >= $width - 2 || $y >= $height - 2        => 0,
                $x === 2 || $y === 2 || $x === $width - 3 || $y === $height - 3 => 3,
                (($x >> 3) + $y) % 2 === 0                                      => 1,
                default                                                         => 2,
            };
        }
    }

    return $rows;
}


/**
 * Stairs: the value (x + 2y) mod 4 changes on every pixel, so each pixel needs its own run and the unit spans several packs.
 */
function stairs(int $width, int $height): array
{
    $rows = [];
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rows[$y][$x] = ($x + 2 * $y) % 4;
        }
    }

    return $rows;
}


function runLengthLine(array $line): string
{
    $runs = [];
    foreach ($line as $value) {
        if ($runs !== [] && $runs[count($runs) - 1][1] === $value) {
            $runs[count($runs) - 1][0]++;
        } else {
            $runs[] = [1, $value];
        }
    }

    $nibbles = [];
    foreach ($runs as $index => [$length, $value]) {
        if ($index === count($runs) - 1 && $length >= 64) {
            array_push($nibbles, 0, 0, 0, $value);
            continue;
        }
        while ($length > 0) {
            $part    = min($length, 255);
            $length -= $part;
            $code    = $part << 2 | $value;
            $digits  = match (true) {
                $part < 4  => 1,
                $part < 16 => 2,
                $part < 64 => 3,
                default    => 4,
            };
            for ($digit = $digits - 1; $digit >= 0; $digit--) {
                $nibbles[] = $code >> (4 * $digit) & 0x0F;
            }
        }
    }
    if (count($nibbles) % 2 === 1) {
        $nibbles[] = 0;
    }

    $bytes = "";
    for ($i = 0; $i < count($nibbles); $i += 2) {
        $bytes .= chr($nibbles[$i] << 4 | $nibbles[$i + 1]);
    }

    return $bytes;
}


/**
 * Builds a subpicture unit (SPU).
 *
 * @param list<int> $colors palette indexes for background, pattern, emphasis 1, emphasis 2
 * @param list<int> $alphas contrast 0 to 15 in the same order
 * @param list<array{int, list<string>}> $sequences each a date in units of 1024/90000 s and its commands, where
 *        "params" stands for SET_COLOR, SET_CONTR, SET_DAREA and SET_DSPXA
 * @param bool $bottomFieldFirst stores the bottom field before the top field, so SET_DSPXA points to the second field first
 */
function unit(array $rows, int $x, int $y, array $colors, array $alphas, array $sequences, bool $bottomFieldFirst = false): string
{
    $top    = "";
    $bottom = "";
    foreach ($rows as $index => $row) {
        $index % 2 === 0 ? $top .= runLengthLine($row) : $bottom .= runLengthLine($row);
    }

    $width  = count($rows[0]);
    $height = count($rows);
    $x2     = $x + $width - 1;
    $y2     = $y + $height - 1;
    $params = "\x03" . chr($colors[3] << 4 | $colors[2]) . chr($colors[1] << 4 | $colors[0])
            . "\x04" . chr($alphas[3] << 4 | $alphas[2]) . chr($alphas[1] << 4 | $alphas[0])
            . "\x05" . chr($x >> 4) . chr(($x & 0x0F) << 4 | $x2 >> 8) . chr($x2 & 0xFF)
            . chr($y >> 4) . chr(($y & 0x0F) << 4 | $y2 >> 8) . chr($y2 & 0xFF)
            . "\x06" . ($bottomFieldFirst ? pack("nn", 4 + strlen($bottom), 4) : pack("nn", 4, 4 + strlen($top)));

    $bodies = array_map(fn (array $sequence): string =>
        implode("", array_map(fn (string $command): string => $command === "params" ? $params : $command, $sequence[1])) . "\xFF",
        $sequences);

    $offset  = 4 + strlen($top) + strlen($bottom);
    $control = "";
    foreach ($sequences as $index => [$date]) {
        $next     = $index === count($sequences) - 1 ? $offset : $offset + 4 + strlen($bodies[$index]);
        $control .= pack("nn", $date, $next) . $bodies[$index];
        $offset   = $next;
    }

    $unit = ($bottomFieldFirst ? $bottom . $top : $top . $bottom) . $control;

    return pack("nn", 4 + strlen($unit), 4 + strlen($top) + strlen($bottom)) . $unit;
}


function timestamp(int $ticks): string
{
    return chr(($ticks >> 30 & 0x07) << 1 | 0x21) . pack("n", ($ticks >> 15 & 0x7FFF) << 1 | 1) . pack("n", ($ticks & 0x7FFF) << 1 | 1);
}


function packHeader(int $ticks): string
{
    return "\x00\x00\x01\xBA"
        . chr(0x44 | ($ticks >> 30 & 0x07) << 3 | $ticks >> 28 & 0x03)
        . chr($ticks >> 20 & 0xFF)
        . chr(($ticks >> 15 & 0x1F) << 3 | 0x04 | $ticks >> 13 & 0x03)
        . chr($ticks >> 5 & 0xFF)
        . chr(($ticks & 0x1F) << 3 | 0x04)
        . "\x01\x01\x89\xC3\xF8";
}


/**
 * Splits the unit into 2048 byte packs. The first PES packet holds the PTS. A gap below 6 bytes
 * becomes PES header stuffing, a larger gap a padding stream packet.
 */
function packs(string $unit, int $track, float $time): string
{
    $ticks  = (int) round($time * 90000);
    $output = "";
    for ($offset = 0, $first = true; $offset < strlen($unit); $first = false) {
        $room      = PACK_SIZE - 14 - 9 - 1 - ($first ? 5 : 0);
        $chunk     = substr($unit, $offset, $room);
        $offset   += strlen($chunk);
        $gap       = $room - strlen($chunk);
        $stuffing  = $gap > 0 && $gap < 6 ? $gap : 0;
        $header    = ($first ? timestamp($ticks) : "") . str_repeat("\xFF", $stuffing);
        $body      = "\x81" . ($first ? "\x80" : "\x00") . chr(strlen($header)) . $header . chr(0x20 + $track) . $chunk;
        $output   .= packHeader($ticks) . "\x00\x00\x01\xBD" . pack("n", strlen($body)) . $body;
        if ($gap >= 6) {
            $output .= "\x00\x00\x01\xBE" . pack("n", $gap - 6) . str_repeat("\xFF", $gap - 6);
        }
    }

    return $output;
}


/**
 * Packs the unit into one PES packet after one pack header, without padding packets.
 */
function onePacket(string $unit, int $track, float $time): string
{
    $ticks  = (int) round($time * 90000);
    $header = timestamp($ticks);
    $body   = "\x81\x80" . chr(strlen($header)) . $header . chr(0x20 + $track) . $unit;

    return packHeader($ticks) . "\x00\x00\x01\xBD" . pack("n", strlen($body)) . $body;
}


function idxTime(float $time): string
{
    $ms = (int) round($time * 1000);

    return sprintf("%02d:%02d:%02d:%03d", intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
}


/**
 * Text in Liberation Sans with a 2 pixel outline: background (0), pattern for the fill (1), emphasis 1 for
 * the anti-aliased fill edge (2) and emphasis 2 for the outline (3).
 */
function text(array $lines, float $size): array
{
    $bitmap = TextBitmap::render($lines, $size, 2.0, 4);
    $rows   = [];
    foreach ($bitmap->fill as $index => $fill) {
        $rows[intdiv($index, $bitmap->width)][] = match (true) {
            $fill >= 0.6                      => 1,
            $fill >= 0.25                     => 2,
            $bitmap->outline[$index] >= 0.5   => 3,
            default                           => 0,
        };
    }

    return $rows;
}


/**
 * @param list<array{lines: list<string>, index: int, delay?: float, units: list<array{float, string}>}> $tracks
 */
function writeFixture(string $name, array $header, array $tracks, string $eol): void
{
    [$idx, $sub] = buildFixture($header, $tracks, $eol);
    file_put_contents(__DIR__ . "/$name.idx", $idx);
    file_put_contents(__DIR__ . "/$name.sub", $sub);
}


/**
 * @param list<array{lines: list<string>, index: int, delay?: float, units: list<array{float, string}>}> $tracks
 * @param string $pack the function that packs a unit into the .sub content
 * @return array{string, string} the .idx and the .sub content
 */
function buildFixture(array $header, array $tracks, string $eol, string $pack = "packs"): array
{
    $all = [];
    foreach ($tracks as $trackNumber => $track) {
        foreach ($track["units"] as $unitNumber => [$time, $unit]) {
            $all[] = [$time + ($track["delay"] ?? 0), $trackNumber, $unitNumber, $unit];
        }
    }
    usort($all, fn (array $a, array $b): int => $a[0] <=> $b[0]);

    $sub     = "";
    $filepos = [];
    foreach ($all as [$time, $trackNumber, $unitNumber, $unit]) {
        $filepos[$trackNumber][$unitNumber] = strlen($sub);
        $sub .= $pack($unit, $tracks[$trackNumber]["index"], $time);
    }

    $lines = $header;
    foreach ($tracks as $trackNumber => $track) {
        array_push($lines, ...$track["lines"]);
        foreach ($track["units"] as $unitNumber => [$time]) {
            $lines[] = sprintf("timestamp: %s, filepos: %09x", idxTime($time), $filepos[$trackNumber][$unitNumber]);
        }
        $lines[] = "";
    }

    return [implode($eol, $lines), $sub];
}


function idxHeader(string $size, string $customColors): array
{
    return [
        "# VobSub index file, v7 (do not modify this line!)",
        "#",
        "# Written by generate.php in this folder for the subtitle-toolbox tests.",
        "#",
        "",
        "# Settings",
        "",
        "# Original frame size",
        "size: $size",
        "",
        "# Origin, relative to the upper-left corner, can be overloaded by alignment",
        "org: 0, 0",
        "",
        "# Image scaling (hor,ver), origin is at the upper-left corner or at the alignment coord (x, y)",
        "scale: 100%, 100%",
        "",
        "# Alpha blending",
        "alpha: 100%",
        "",
        "# Smoothing for very blocky images (use OLD for no filtering)",
        "smooth: OFF",
        "",
        "# In millisecs",
        "fadein/out: 50, 50",
        "",
        "# Force subpicture placement and/or justification",
        "align: OFF at LEFT TOP",
        "",
        "# For correcting non-progressive desync. (in millisecs or hh:mm:ss:ms)",
        "time offset: 0",
        "",
        "# ON: displays only forced subtitles, OFF: shows everything",
        "forced subs: OFF",
        "",
        "# The original palette of the DVD",
        "palette: " . PALETTE,
        "",
        "# Custom colors (transp idxs and the four colors)",
        "custom colors: $customColors",
        "",
        "# Language index in use",
        "langidx: 0",
        "",
    ];
}


/** Start and end in seconds, the text lines with <i> runs and the font size in pixels. */
const TEXT_CUES = [
    [1.0, 3.5, ["The ferry leaves from pier 2."], 28],
    [4.0, 7.0, ["Bring a warm coat.", "It gets cold on deck."], 28],
    [7.5, 10.0, ["<i>Fog over the harbour this morning.</i>"], 26],
    [10.5, 13.0, ["The cafe sells soup until 4 p.m."], 24],
    [13.5, 16.5, ["Wind from the west,", "about 20 km per hour."], 30],
    [17.0, 19.5, ["Please keep your ticket."], 26],
];


/**
 * One English track of text units on a 720x576 screen, see TEXT_CUES.
 *
 * @return array{string, string} the .idx and the .sub content
 */
function textFixture(): array
{
    $units = [];
    foreach (TEXT_CUES as [$start, $end, $lines, $size]) {
        $rows    = text($lines, $size);
        $stop    = (int)round(($end - $start) * 90000 / 1024);
        $units[] = [$start, unit($rows, intdiv(720 - count($rows[0]), 2), 536 - count($rows), [0, 1, 3, 0], [0, 15, 15, 15],
                                 [[0, ["params", "\x01"]], [$stop, ["\x02"]]])];
    }

    return buildFixture(idxHeader("720x576", "OFF, tridx: 0000, colors: 000000, 000000, 000000, 000000"), [
        ["lines" => ["# English", "id: en, index: 0"], "index" => 0, "units" => $units],
    ], "\r\n");
}


/** Start and end in seconds, the text lines, the font size in pixels and whether the bottom field comes first. */
const ONE_PACKET_CUES = [
    [1.0, 3.0, ["The museum opens at ten."], 26, false],
    [3.5, 6.0, ["Tickets are sold", "at the north door."], 28, true],
    [6.5, 9.0, ["Photos are allowed without flash."], 24, false],
];


/**
 * One English track in the layout of Subtitle Edit and ffmpeg: one PES packet per unit without padding packets, and an
 * .idx with only the size and palette lines and no custom colors line.
 *
 * @return array{string, string} the .idx and the .sub content
 */
function onePacketFixture(): array
{
    $units = [];
    foreach (ONE_PACKET_CUES as [$start, $end, $lines, $size, $bottomFieldFirst]) {
        $rows    = text($lines, $size);
        $stop    = (int)round(($end - $start) * 90000 / 1024);
        $units[] = [$start, unit($rows, intdiv(720 - count($rows[0]), 2), 536 - count($rows), [0, 1, 3, 0], [0, 15, 15, 15],
                                 [[0, ["params", "\x01"]], [$stop, ["\x02"]]], $bottomFieldFirst)];
    }

    return buildFixture(["# VobSub index file, v7 (do not modify this line!)", "size: 720x576", "palette: " . PALETTE, ""], [
        ["lines" => ["id: en, index: 0"], "index" => 0, "units" => $units],
    ], "\n", "onePacket");
}


if (realpath($_SERVER["SCRIPT_FILENAME"] ?? "") !== __FILE__) {
    return;
}

[$idx, $sub] = textFixture();
file_put_contents(__DIR__ . "/text-pal.idx", $idx);
file_put_contents(__DIR__ . "/text-pal.sub", $sub);

[$idx, $sub] = onePacketFixture();
file_put_contents(__DIR__ . "/one-packet-pal.idx", $idx);
file_put_contents(__DIR__ . "/one-packet-pal.sub", $sub);

$show = fn (int $stop): array => [[0, ["params", "\x01"]], [$stop, ["\x02"]]];

writeFixture("two-tracks-pal", idxHeader("720x576", "OFF, tridx: 0000, colors: 000000, 000000, 000000, 000000"), [
    [
        "lines" => ["# English", "id: en, index: 0", "# Decimation and offset: 0", "# Vob/Cell ID: 1, 1 (PTS: 0)"],
        "index" => 0,
        "units" => [
            [1.5, unit(box(300, 40), 210, 500, [0, 1, 6, 0], [0, 15, 15, 15], $show(220))],
            [5.25, unit(box(200, 24), 260, 40, [0, 10, 4, 1], [0, 15, 8, 15], [[0, ["params"]], [45, ["\x00"]], [264, ["\x02"]]])],
            [9.0, unit(box(120, 30), 300, 520, [0, 1, 2, 3], [0, 15, 15, 15], [[0, ["params", "\x01"]]])],
            [11.0, unit(stairs(720, 32), 0, 544, [0, 1, 6, 8], [0, 15, 15, 15], $show(176))],
            [20.0, unit(box(64, 16), 10, 10, [0, 1, 6, 0], [0, 15, 15, 15], [[0, ["params", "\x01"]]])],
        ],
    ],
    [
        "lines" => ["# German", "id: de, index: 1", "# Decimation and offset: 0", "delay: 00:00:01:000"],
        "index" => 1,
        "delay" => 1.0,
        "units" => [
            [2.0, unit(box(101, 25), 309, 470, [0, 1, 6, 0], [0, 15, 15, 15], $show(87))],
            [3723.456, unit(box(50, 10), 0, 0, [0, 1, 6, 0], [0, 15, 15, 15], $show(175))],
        ],
    ],
], "\r\n");

writeFixture("custom-colors-ntsc", idxHeader("720x480", "ON, tridx: 1000, colors: 000000, ffffff, 808080, ff0000"), [
    [
        "lines" => ["# Japanese", "id: ja, index: 0"],
        "index" => 0,
        "units" => [
            [0.5, unit(box(160, 20), 280, 440, [0, 1, 6, 0], [0, 15, 15, 15], $show(88))],
        ],
    ],
    [
        "lines" => ["# French", "id: fr, index: 2"],
        "index" => 2,
        "units" => [
            [61.0, unit(box(240, 36), 240, 420, [0, 1, 6, 0], [0, 15, 15, 15], $show(132))],
            [125.125, unit(box(80, 12), 320, 30, [0, 1, 6, 0], [0, 15, 15, 15], [[0, ["params", "\x00"]], [44, ["\x02"]]])],
        ],
    ],
], "\n");
