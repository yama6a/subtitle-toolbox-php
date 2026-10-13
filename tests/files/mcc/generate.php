<?php

declare(strict_types=1);

// Writes the synthetic MCC fixtures in real/ from the byte pairs of SCC fixtures. Run: php tests/files/mcc/generate.php
// The packet writer here is separate from MccFormatter, so that the tests do not read back what the library writes.

const SCC_DIR = __DIR__ . "/../scc/real/";

const HEADER = <<<'MCC'
    File Format=MacCaption_MCC V%s

    ///////////////////////////////////////////////////////////////////////////////////
    // Computer Prompting and Captioning Company
    // Ancillary Data Packet Transfer File
    //
    // Permission to generate this format is granted provided that
    //   1. This ANC Transfer file format is used on an as-is basis and no warranty is given, and
    //   2. This entire descriptive information text is included in a generated .mcc file.
    //
    // General file format:
    //   HH:MM:SS:FF(tab)[Hexadecimal ANC data in groups of 2 characters]
    //     Hexadecimal data starts with the Ancillary Data Packet DID (Data ID defined in S291M)
    //       and concludes with the Check Sum following the User Data Words.
    //     Each time code line must contain at most one complete ancillary data packet.
    //     To transfer additional ANC Data successive lines may contain identical time code.
    //     Time Code Rate=[24, 25, 30, 30DF, 50, 60%s]
    //
    //   ANC data bytes may be represented by one ASCII character according to the following schema:
    //     G  FAh 00h 00h
    //     H  2 x (FAh 00h 00h)
    //     I  3 x (FAh 00h 00h)
    //     J  4 x (FAh 00h 00h)
    //     K  5 x (FAh 00h 00h)
    //     L  6 x (FAh 00h 00h)
    //     M  7 x (FAh 00h 00h)
    //     N  8 x (FAh 00h 00h)
    //     O  9 x (FAh 00h 00h)
    //     P  FBh 80h 80h
    //     Q  FCh 80h 80h
    //     R  FDh 80h 80h
    //     S  96h 69h
    //     T  61h 01h
    //     U  E1h 00h 00h 00h
    //     Z  00h
    //
    ///////////////////////////////////////////////////////////////////////////////////
    MCC;

// The service info section of a CDP with two services: CEA-608 CC1 and CEA-708 service 1, both in English.
// captionmaker_608_708.mcc keeps it, as a service info section lists only some services.
const SERVICE_INFO = "\x73\xF2\xE0eng\x7E\x3F\xFF\xE1eng\xC1\x3F\xFF";


/**
 * Returns the CEA-608 words of an SCC file by frame at 29.97 fps, one word per frame from the time code of each line.
 *
 * @return array<int, int>
 */
function sccWords(string $file): array
{
    $words = [];
    foreach (file(SCC_DIR . $file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^(\d\d):(\d\d):(\d\d)([:;])(\d\d)\s+(.*)$/', trim($line), $m) !== 1) {
            continue;
        }
        $minutes = (int) $m[1] * 60 + (int) $m[2];
        $frame   = ($minutes * 60 + (int) $m[3]) * 30 + (int) $m[5] - ($m[4] === ";" ? 2 * ($minutes - intdiv($minutes, 10)) : 0);
        foreach (preg_split('/\s+/', $m[6]) as $index => $pair) {
            $words[$frame + $index] = hexdec($pair);
        }
    }
    ksort($words);

    return $words;
}


/**
 * Returns a cc_data triplet: the marker bits, cc_valid, cc_type and the 2 data bytes.
 */
function triplet(int $type, int $word, bool $valid = true): string
{
    return pack("Cn", 0xF8 | ($valid ? 0x04 : 0) | $type, $word);
}


/**
 * Returns an ANC packet with a CDP that holds the triplets, padded with empty DTVCC triplets to $count.
 */
function packet(int $rateCode, int $sequence, string $triplets, int $count, string $serviceInfo = "", string $timeCode = ""): string
{
    $triplets .= str_repeat("\xFA\0\0", $count - intdiv(strlen($triplets), 3));
    $flags     = 0x43 | ($serviceInfo === "" ? 0 : 0x24) | ($timeCode === "" ? 0 : 0x80);
    $body      = ($timeCode === "" ? "" : "\x71$timeCode") . "\x72" . chr(0xE0 | $count) . $triplets . $serviceInfo . "\x74" . pack("n", $sequence);
    $cdp       = pack("nCCCn", 0x9669, 7 + strlen($body) + 1, $rateCode << 4 | 0x0F, $flags, $sequence) . $body;
    $cdp      .= chr(-array_sum(unpack("C*", $cdp)) & 0xFF);
    $anc       = "\x61\x01" . chr(strlen($cdp)) . $cdp;

    return $anc . chr(array_sum(unpack("C*", $anc)) & 0xFF);
}


/**
 * Writes bytes as hex with the alias letters of the MCC header. Runs of FA 00 00 take the letters G to O.
 */
function compress(string $bytes): string
{
    $hex = strtoupper(bin2hex($bytes));
    $out = "";
    for ($offset = 0; $offset < strlen($hex); ) {
        $run = 0;
        while ($run < 9 && substr($hex, $offset + 6 * $run, 6) === "FA0000") {
            $run++;
        }
        if ($run > 0) {
            $out    .= chr(ord("F") + $run);
            $offset += 6 * $run;
            continue;
        }
        foreach ([["FC8080", "Q"], ["FD8080", "R"], ["9669", "S"], ["6101", "T"], ["00", "Z"]] as [$bytesHex, $letter]) {
            if (substr($hex, $offset, strlen($bytesHex)) === $bytesHex) {
                $out    .= $letter;
                $offset += strlen($bytesHex);
                continue 2;
            }
        }
        $out    .= substr($hex, $offset, 2);
        $offset += 2;
    }

    return $out;
}


/**
 * Returns the time code of a frame. Drop-frame time code skips 2 labels per minute at 30 labels and 4 at 60, except every tenth minute.
 *
 * @return array{int, int, int, int}
 */
function timeCode(int $frame, int $labels, bool $dropFrame): array
{
    if ($dropFrame) {
        $dropped   = intdiv($labels, 15);
        $perMinute = 60 * $labels - $dropped;
        $perTen    = 10 * $perMinute + $dropped;
        $tens      = intdiv($frame, $perTen);
        $rest      = $frame % $perTen;
        $frame    += 9 * $dropped * $tens + ($rest >= $dropped ? $dropped * intdiv($rest - $dropped, $perMinute) : 0);
    }

    return [intdiv($frame, 3600 * $labels), intdiv($frame, 60 * $labels) % 60, intdiv($frame, $labels) % 60, $frame % $labels];
}


function fileHeader(string $version, string $rate, string $uuid, string $date, string $time): string
{
    return sprintf(HEADER, $version, $version === "1.0" ? "" : ", 60DF") . "\n\nUUID=$uuid\nCreation Program=Subtitle Toolbox fixture generator\n" .
           "Creation Date=$date\nCreation Time=$time\nTime Code Rate=$rate\n\n";
}


/**
 * CaptionMaker shape at 29.97 fps drop-frame: one line per frame, field 1 data or a null pair, a null pair for field 2,
 * 18 empty DTVCC triplets and the service info section.
 */
function captionMakerPopOn(): string
{
    $words  = sccWords("captionmaker_popon_df.scc");
    $output = fileHeader("2.0", "30DF", "6F1D3C2A-4B5E-4F60-8A71-92B3C4D5E6F7", "Monday, October 12, 2026", "09:14:05");
    for ($frame = array_key_first($words) - 15, $sequence = 0; $frame <= array_key_last($words) + 15; $frame++, $sequence++) {
        $triplets = triplet(0, $words[$frame] ?? 0x8080) . triplet(1, 0x8080);
        $output  .= vsprintf("%02d:%02d:%02d:%02d", timeCode($frame, 30, true)) . "\t" . compress(packet(4, $sequence, $triplets, 20, SERVICE_INFO)) . "\n";
    }

    return $output;
}


/**
 * Version 1.0 at 23.976 fps with CR LF: Time Code Rate 24 and the CDP frame rate code 1. Each frame holds 1 or 2
 * field 1 pairs, as 29.97 pairs per second do not fit one per frame. Each CDP holds a time code section.
 */
function v1At23976(): string
{
    $byFrame = [];
    foreach (sccWords("premiere_popon_df.scc") as $frame608 => $word) {
        $byFrame[intdiv($frame608 * 4, 5)][] = $word;
    }
    $output = fileHeader("1.0", "24", "0A1B2C3D-4E5F-4061-8273-8495A6B7C8D9", "Sunday, October 11, 2026", "17:40:22");
    for ($frame = 0, $last = array_key_last($byFrame) + 12; $frame <= $last; $frame++) {
        $triplets = "";
        foreach ($byFrame[$frame] ?? [0x8080] as $word) {
            $triplets .= triplet(0, $word);
        }
        [$hours, $minutes, $seconds, $frames] = timeCode($frame, 24, false);
        $bcd    = fn (int $value): int => intdiv($value, 10) << 4 | $value % 10;
        $tc     = pack("C4", 0xC0 | $bcd($hours), 0x80 | $bcd($minutes), $bcd($seconds), $bcd($frames));
        $output .= sprintf("%02d:%02d:%02d:%02d", $hours, $minutes, $seconds, $frames) . "\t" .
                   compress(packet(1, $frame, $triplets . triplet(1, 0x8080), 25, "", $tc)) . "\n";
    }

    return str_replace("\n", "\r\n", $output);
}


/**
 * Version 2.0 at 59.94 fps drop-frame: field 1 pairs on even frames and field 2 pairs on odd frames, one CEA-608
 * triplet per frame. Each second ends with a packet for field 2 on the same time code, marked ".1".
 */
function v2At5994(): string
{
    $words  = sccWords("rollup_news_ndf.scc");
    $output = fileHeader("2.0", "60DF", "1F2E3D4C-5B6A-4798-8A9B-ACBDCEDFE0F1", "Tuesday, October 13, 2026", "08:02:59");
    for ($frame = 0, $last = 2 * array_key_last($words) + 30; $frame <= $last; $frame++) {
        $triplet = $frame % 2 === 0 ? triplet(0, $words[intdiv($frame, 2)] ?? 0x8080) : triplet(1, 0x8080);
        $code    = vsprintf("%02d:%02d:%02d:%02d", timeCode($frame, 60, true));
        $output .= "$code\t" . compress(packet(7, $frame & 0xFFFF, $triplet, 10)) . "\n";
        if ($frame % 60 === 59) {
            $output .= "$code.1\t" . compress(packet(7, $frame & 0xFFFF, triplet(1, 0x8080), 10)) . "\n";
        }
    }

    return $output;
}


/**
 * Returns the bytes of a CEA-708 command: the code and its parameter bytes.
 */
function command(int $code, int ...$parameters): string
{
    return pack("C*", $code, ...$parameters);
}


/**
 * Returns the commands that load a pop-on caption into hidden window $window. Each line is a list of pen changes and
 * text: a pen change is [italic, color], with color as 6 bits of red, green and blue.
 *
 * @param list<list<string|array{bool, int}>> $lines
 * @return list<string>
 */
function popOn(int $window, int $anchorPoint, int $vertical, int $horizontal, array $lines): array
{
    $commands = [
        command(0x8C, 1 << $window),
        command(0x98 + $window, 0x18, 0x80 | $vertical, $horizontal, $anchorPoint << 4 | (count($lines) - 1), 31, 0x09),
    ];
    foreach ($lines as $row => $parts) {
        $commands[] = command(0x92, $row, 0);
        foreach ($parts as $part) {
            if (is_array($part)) {
                $commands[] = command(0x90, 0x01, $part[0] ? 0x80 : 0x00);
                $commands[] = command(0x91, $part[1], 0x00, 0x00);
                continue;
            }
            foreach (str_split($part) as $byte) {
                $commands[] = $byte;
            }
        }
    }

    return $commands;
}


/**
 * Splits the commands of one service into DTVCC packets of at most 31 bytes of service data, one packet per frame
 * from $frame on. Returns the triplets of each frame.
 *
 * @param array<int, string> $triplets the triplets by frame, which the packets extend
 * @param list<string>       $commands
 */
function dtvcc(array &$triplets, int $frame, int $service, array $commands): void
{
    $block = "";
    $flush = function () use (&$block, &$frame, &$triplets, $service): void {
        $packet = chr($service << 5 | strlen($block)) . $block;
        $packet .= strlen($packet) % 2 === 0 ? "\0" : "";
        $packet  = chr(($frame & 3) << 6 | intdiv(strlen($packet) + 1, 2)) . $packet;
        foreach (str_split($packet, 2) as $index => $pair) {
            $triplets[$frame] = ($triplets[$frame] ?? "") . triplet($index === 0 ? 3 : 2, unpack("n", $pair)[1]);
        }
        $block = "";
        $frame++;
    };
    foreach ($commands as $command) {
        if (strlen($block) + strlen($command) > 31) {
            $flush();
        }
        $block .= $command;
    }
    $flush();
}


/**
 * CaptionMaker shape with CEA-608 CC1 and two CEA-708 services: the pop-on captions of captionmaker_popon_df.scc in
 * field 1, French captions in service 1 and Spanish captions in service 2. Service 1 has an accented letter of G1,
 * an ellipsis of G2, the music note, italics, a yellow pen and a window at the top left.
 */
function captionMaker708(): string
{
    $words    = sccWords("captionmaker_popon_df.scc");
    $triplets = [];
    $yellow   = [false, 0x3C];
    $white    = [false, 0x3F];
    dtvcc($triplets, 50, 1, popOn(0, 7, 90, 50, [["L'horaire d'hiver"], ["d\xE9bute lundi\x10\x25"]]));
    dtvcc($triplets, 69, 1, [command(0x89, 0x01)]);
    dtvcc($triplets, 140, 1, popOn(1, 0, 10, 10, [[[true, 0x3F], "\x7F Annonce \x7F"]]));
    dtvcc($triplets, 164, 1, [command(0x8C, 0x01) . command(0x89, 0x02)]);
    dtvcc($triplets, 210, 1, [command(0x8C, 0x02)]);
    dtvcc($triplets, 225, 1, popOn(0, 7, 90, 50, [[$yellow, "Le train c\xF4tier part"], [$white, "\xE0 6 h 50."]]));
    dtvcc($triplets, 254, 1, [command(0x89, 0x01)]);
    dtvcc($triplets, 330, 1, [command(0x8C, 0x01)]);
    dtvcc($triplets, 55, 2, popOn(0, 7, 90, 50, [["El horario de invierno"], ["empieza el lunes."]]));
    dtvcc($triplets, 69, 2, [command(0x89, 0x01)]);
    dtvcc($triplets, 210, 2, [command(0x8C, 0x01)]);

    $output = fileHeader("2.0", "30DF", "7A2B4C6D-8E9F-4A0B-9C1D-2E3F4A5B6C7D", "Monday, October 12, 2026", "10:20:30");
    for ($frame = 30, $sequence = 0; $frame <= 345; $frame++, $sequence++) {
        $data    = triplet(0, $words[$frame] ?? 0x8080) . triplet(1, 0x8080) . ($triplets[$frame] ?? "");
        $output .= vsprintf("%02d:%02d:%02d:%02d", timeCode($frame, 30, true)) . "\t" . compress(packet(4, $sequence, $data, 20, SERVICE_INFO)) . "\n";
    }

    return $output;
}


$files = [
    "captionmaker_popon_df.mcc" => captionMakerPopOn(),
    "captionmaker_608_708.mcc"  => captionMaker708(),
    "v1_23976_timecode.mcc"     => v1At23976(),
    "v2_5994df_fields.mcc"      => v2At5994(),
];
foreach ($files as $name => $content) {
    file_put_contents(__DIR__ . "/real/$name", $content);
    echo "Wrote $name\n";
}
