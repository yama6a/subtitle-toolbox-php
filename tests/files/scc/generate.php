<?php

declare(strict_types=1);

// Writes the SCC fixtures in real/. Run: php tests/files/scc/generate.php
// The encoder here is separate from SccFormatter, so that the tests do not read back what the library writes.

const COMMANDS = [
    "RCL" => 0x20, "BS" => 0x21, "DER" => 0x24, "RU2" => 0x25, "RU3" => 0x26, "RU4" => 0x27,
    "RDC" => 0x29, "EDM" => 0x2C, "CR" => 0x2D, "ENM" => 0x2E, "EOC" => 0x2F,
];

const STYLES = ["white" => 0, "green" => 1, "blue" => 2, "cyan" => 3, "red" => 4, "yellow" => 5, "magenta" => 6, "italic" => 7];

// Characters of the standard set that differ from ASCII.
const STANDARD = ["Ñ" => 0x7D, "é" => 0x5C];

// Characters outside the standard set: [first byte, second byte, fallback character or null].
const TWO_BYTE = [
    "\u{266A}" => [0x11, 0x37, null],
    "\u{AE}"   => [0x11, 0x30, null],
    "É"        => [0x12, 0x21, "E"],
    "Ü"        => [0x12, 0x24, "U"],
    "\u{A1}"   => [0x12, 0x27, "!"],
    "\u{2014}" => [0x12, 0x2A, "-"],
    "\u{201C}" => [0x12, 0x2E, "\""],
    "\u{201D}" => [0x12, 0x2F, "\""],
    "ö"        => [0x13, 0x33, "o"],
];


function parity(int $value): int
{
    $bits = substr_count(decbin($value & 0x7F), "1");

    return $bits % 2 === 1 ? $value & 0x7F : ($value & 0x7F) | 0x80;
}


function word(int $first, int $second): string
{
    return sprintf("%02x%02x", parity($first), parity($second));
}


function twice(string $word): array
{
    return [$word, $word];
}


function cmd(string $name): array
{
    return twice(word(0x14, COMMANDS[$name]));
}


function pac(int $row, int $column = 0, string $style = "white", bool $underline = false): array
{
    $rows  = [1 => [0x11, 0], 2 => [0x11, 1], 3 => [0x12, 0], 4 => [0x12, 1], 5 => [0x15, 0], 6 => [0x15, 1], 7 => [0x16, 0],
              8 => [0x16, 1], 9 => [0x17, 0], 10 => [0x17, 1], 11 => [0x10, 0], 12 => [0x13, 0], 13 => [0x13, 1], 14 => [0x14, 0],
              15 => [0x14, 1]];
    $low   = $column > 0 ? 0x10 | (intdiv($column, 4) << 1) : STYLES[$style] << 1;

    return twice(word($rows[$row][0], 0x40 | ($rows[$row][1] << 5) | $low | ($underline ? 1 : 0)));
}


function tab(int $columns): array
{
    return twice(word(0x17, 0x20 + $columns));
}


function mid(string $style, bool $underline = false): array
{
    return twice(word(0x11, 0x20 | (STYLES[$style] << 1) | ($underline ? 1 : 0)));
}


function filler(int $count): array
{
    return array_fill(0, $count, "8080");
}


/**
 * Packs standard characters two per word and pads an odd one with 80. Special and extended characters go twice.
 */
function text(string $text): array
{
    $words   = [];
    $pending = null;
    foreach (preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        $code  = TWO_BYTE[$character] ?? null;
        $bytes = $code === null ? [STANDARD[$character] ?? ord($character)] : ($code[2] === null ? [] : [ord($code[2])]);
        foreach ($bytes as $byte) {
            if ($pending === null) {
                $pending = $byte;
            } else {
                $words[] = word($pending, $byte);
                $pending = null;
            }
        }
        if ($code !== null) {
            if ($pending !== null) {
                $words[] = word($pending, 0x00);
                $pending = null;
            }
            array_push($words, ...twice(word($code[0], $code[1])));
        }
    }
    if ($pending !== null) {
        $words[] = word($pending, 0x00);
    }

    return $words;
}


function line(string $timecode, array ...$parts): string
{
    return $timecode . "\t" . implode(" ", array_merge(...$parts));
}


function write(string $file, array $lines, string $eol = "\n", string $separator = "\t"): void
{
    $lines = array_map(fn (string $line): string => str_replace("\t", $separator, $line), $lines);
    file_put_contents(__DIR__ . "/real/" . $file, "Scenarist_SCC V1.0" . $eol . $eol . implode($eol . $eol, $lines) . $eol);
}


function popOn(string $timecode, array $rows, bool $eraseBeforeShow = true): string
{
    $parts = [cmd("ENM"), cmd("RCL")];
    foreach ($rows as $row) {
        $parts[] = $row;
    }
    if ($eraseBeforeShow) {
        array_push($parts, cmd("EDM"), filler(2));
    }
    $parts[] = cmd("EOC");

    return line($timecode, ...$parts);
}


// Pop-on captions of a broadcast file: drop-frame time codes across 00:59:00 and 01:00:00, EDM and two filler words before
// each EOC, PAC styles, mid-row codes, special and extended characters, and captions at the top of the screen.
write("popon_broadcast_df.scc", [
    popOn("00:58:20;00", [pac(14, 4), tab(1), text("THE NEXT TRAIN TO LAKESIDE"), pac(15, 8), text("LEAVES AT NINE.")]),
    line("00:58:23;10", cmd("EDM")),
    popOn("00:58:24;00", [pac(15, 0, "italic"), text("Doors are closing.")]),
    line("00:58:26;15", cmd("EDM")),
    popOn("00:58:27;00", [pac(14, 4), text("THE BAKERY ON MAIN STREET"), pac(15, 8), tab(2), text("OPENS AT SIX.")]),
    popOn("00:58:30;00", [pac(15, 8), text("\u{266A} SOFT PIANO MUSIC \u{266A}")]),
    line("00:58:33;00", cmd("EDM")),
    popOn("00:58:57;00", [pac(1, 8), tab(1), text("[ STATION BELL RINGS ]")]),
    line("00:58:59;20", cmd("EDM")),
    popOn("00:59:00;02", [pac(15, 4), text("WE HAVE"), mid("italic"), text("FRESH"), mid("white"), text("BREAD TODAY.")], false),
    line("00:59:04;00", cmd("EDM")),
    popOn("00:59:05;00", [pac(14, 0, "yellow"), text("RAIN IS LIKELY TONIGHT."), pac(15, 0), text("TAKE AN UMBRELLA.")]),
    line("00:59:08;00", cmd("EDM")),
    popOn("00:59:56;00", [pac(15, 4), text("\u{201C}CAFÉ NORD\u{201D} \u{2014} OPEN DAILY.")]),
    line("00:59:59;10", cmd("EDM")),
    popOn("01:00:00;00", [pac(15, 4), text("PLATFORM"), mid("white", true), text("TWO"), mid("white"), text("NOT THREE.")], false),
    line("01:00:03;00", cmd("EDM")),
    popOn("01:00:04;00", [pac(2, 8), text("\u{A1}HASTA MAÑANA, ZÜRICH!")]),
    line("01:00:07;00", cmd("EDM")),
]);

// Roll-up captions of a live news file: three rows, non-drop time codes, CR LF line endings, and words that arrive over
// several lines of the file.
write("rollup_news_ndf.scc", [
    line("00:00:00:00", cmd("RU3"), cmd("CR"), pac(15), text(">> GOOD MORNING.")),
    line("00:00:01:10", text(" HERE IS THE")),
    line("00:00:02:00", cmd("RU3"), cmd("CR"), pac(15), text("WEATHER FOR TODAY.")),
    line("00:00:03:15", cmd("RU3"), cmd("CR"), pac(15), text("CLOUDS IN THE MORNING,")),
    line("00:00:05:00", cmd("RU3"), cmd("CR"), pac(15), text("SUN IN THE AFTERNOON.")),
    line("00:00:06:20", cmd("RU3"), cmd("CR"), pac(15), text(">> NOW THE TRAFFIC.")),
    line("00:00:08:00", cmd("RU2"), cmd("CR"), pac(15), text("THE BRIDGE IS OPEN.")),
    line("00:00:10:00", cmd("EDM")),
], "\r\n");

// Paint-on captions: RDC, a backspace that corrects a letter, a delete to the end of the row, and a space after the
// time code instead of a tab.
write("painton_corrections.scc", [
    line("00:00:10:00", cmd("RDC"), pac(14, 4), text("BAKERY NEWS")),
    line("00:00:11:00", cmd("RDC"), pac(15, 4), text("BREAF"), cmd("BS"), text("D IS READY")),
    line("00:00:12:00", cmd("RDC"), pac(15, 16), tab(3), text("AT NOON"), pac(15, 16), tab(3), cmd("DER"), text("SOON.")),
    line("00:00:14:00", cmd("EDM")),
    line("00:00:15:00", cmd("RDC"), pac(15, 0, "cyan"), text("SEE YOU TOMORROW.")),
    line("00:00:17:00", cmd("EDM")),
], "\n", " ");

// The shape of https://github.com/pbs/pycaption/issues/194: the doubled PAC and tab offset come as PAC, tab, PAC, tab.
write("interleaved_pac_tab.scc", [
    line("00:00:29:04", cmd("RCL"), [pac(13)[0]], [tab(1)[0]], [pac(13)[0]], [tab(1)[0]], mid("italic"), text("[Station announcer]"),
         [pac(14)[0]], [tab(1)[0]], [pac(14)[0]], [tab(1)[0]], mid("italic"), text("The ferry to the island"),
         [pac(15)[0]], [tab(1)[0]], [pac(15)[0]], [tab(1)[0]], mid("italic"), text("leaves from pier four,"),
         cmd("EDM"), cmd("EOC")),
    line("00:00:32:00", cmd("RCL"), [pac(14)[0]], [tab(2)[0]], [pac(14)[0]], [tab(2)[0]], mid("italic"), text("not from pier two"),
         [pac(15)[0]], [tab(2)[0]], [pac(15)[0]], [tab(2)[0]], mid("italic"), text("as printed in the guide."),
         cmd("EDM"), cmd("EOC")),
    line("00:00:35:00", cmd("EDM")),
]);

// The shape of https://github.com/pbs/pycaption/issues/352: the writer put the load of a long caption before the line of
// the short caption that comes first.
write("out_of_order_lines.scc", [
    line("00:00:02:05", cmd("ENM"), cmd("RCL"), pac(15), text("so,"), cmd("EDM"), cmd("EOC")),
    line("00:00:01:15", cmd("ENM"), cmd("RCL"), pac(15), text("the bus was late again today."), cmd("EDM"), cmd("EOC")),
    line("00:00:03:22", cmd("EDM")),
    line("00:00:04:04", cmd("ENM"), cmd("RCL"), pac(15), text("And then,"), cmd("EDM"), cmd("EOC")),
    line("00:00:04:25", cmd("ENM"), cmd("RCL"), pac(15), text("oh"), cmd("EDM"), cmd("EOC")),
    line("00:00:05:15", cmd("EDM")),
]);

// The shape of https://github.com/SubtitleEdit/subtitleedit/issues/11341: a row of 80 random byte pairs without a PAC
// between two valid captions. A fixed seed keeps the file the same on each run.
mt_srand(608);
$noise = [];
for ($idx = 0; $idx < 80; $idx++) {
    $noise[] = sprintf("%02x%02x", mt_rand(0x20, 0xFF), mt_rand(0x20, 0xFF));
}
write("raw_data_row.scc", [
    line("00:00:00:00", cmd("ENM"), cmd("RCL"), pac(15), text("OK"), cmd("EOC")),
    line("00:00:04:00", cmd("EDM")),
    line("00:00:25:00", cmd("ENM"), cmd("RCL"), $noise, cmd("EOC")),
    line("00:00:30:00", cmd("EDM")),
    line("00:00:31:00", cmd("ENM"), cmd("RCL"), pac(15, 4), text("THE NEXT CAPTION IS FINE."), cmd("EOC")),
    line("00:00:34:00", cmd("EDM")),
]);

// The shape of a CaptionMaker or MacCaption pop-on export: ENM and RCL before each caption, every control code doubled,
// EOC at the end of the line, and EDM on a line of its own to clear.
write("captionmaker_popon_df.scc", [
    line("00:00:01;12", cmd("ENM"), cmd("RCL"), pac(14, 8), text("THE WINTER TIMETABLE"), pac(15, 8), text("STARTS ON MONDAY."), cmd("EOC")),
    line("00:00:04;20", cmd("EDM")),
    line("00:00:05;02", cmd("ENM"), cmd("RCL"), pac(15, 0, "italic"), text("[ANNOUNCER]"), cmd("EOC")),
    line("00:00:07;00", cmd("EDM")),
    line("00:00:07;10", cmd("ENM"), cmd("RCL"), pac(14, 4), text("THE COAST TRAIN LEAVES AT"), pac(15, 4), text("6:50, TEN MINUTES EARLIER."), cmd("EOC")),
    line("00:00:11;00", cmd("EDM")),
]);

// The shape of an Adobe Premiere Pro SCC export: one caption per line with RCL first, EOC at the end of the line, and
// drop-frame time codes.
write("premiere_popon_df.scc", [
    line("00:00:00;00", cmd("RCL"), cmd("ENM"), pac(15, 0), text("Welcome to the station office."), cmd("EOC")),
    line("00:00:02;29", cmd("RCL"), cmd("ENM"), pac(14, 0), text("Tickets for the coast line"), pac(15, 0), text("are sold at window two."), cmd("EOC")),
    line("00:00:06;03", cmd("RCL"), cmd("ENM"), pac(15, 0, "yellow"), text("The last train leaves at 11:15."), cmd("EOC")),
    line("00:00:09;15", cmd("EDM")),
]);

// The shape of a live roll-up encoder: RU2, a carriage return before each line, all caps, and a time code every 1 to 2
// seconds.
write("live_rollup_ru2.scc", [
    line("00:10:00:00", cmd("RU2"), cmd("CR"), pac(15), text(">> THE COUNCIL MET")),
    line("00:10:01:10", text(" TONIGHT ABOUT")),
    line("00:10:02:20", cmd("RU2"), cmd("CR"), pac(15), text("THE NEW BUS LINE.")),
    line("00:10:04:00", cmd("RU2"), cmd("CR"), pac(15), text("IT RUNS FROM THE HARBOUR")),
    line("00:10:05:15", cmd("RU2"), cmd("CR"), pac(15), text("TO THE STATION.")),
    line("00:10:06:25", cmd("RU2"), cmd("CR"), pac(15), text(">> SERVICE STARTS")),
    line("00:10:08:00", text(" IN MARCH.")),
    line("00:10:09:10", cmd("EDM")),
]);
