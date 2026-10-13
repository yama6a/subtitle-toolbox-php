<?php

declare(strict_types=1);

// Writes the self-written Cavena 890 fixtures in real/ byte by byte, without the library code. The character bytes come
// from the tables of Subtitle Edit, src/libse/SubtitleFormats/Cavena890.cs at commit a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c.
// Run it with: php tests/files/cavena890/generate-fixtures.php

const LEFT   = 0x50;
const RIGHT  = 0x52;
const CENTER = 0x54;

const ITALIC_ON  = "\x88";
const ITALIC_OFF = "\x98";

// The bytes of each character outside ASCII, and of each ASCII character that a table moves.
const LATIN        = ["[" => "e5", "]" => "e6", "ß" => "df", "è" => "8165", "é" => "8265", "ñ" => "856e", "ö" => "866f", "ü" => "8675"];
const SCANDINAVIAN = ["Å" => "5d", "Ø" => "5c", "å" => "1d", "æ" => "1b", "ø" => "1c"];
const HEBREW       = [
    "א" => "40", "ד" => "43", "ה" => "44", "ו" => "45", "ח" => "47", "י" => "49", "ל" => "4c", "מ" => "4e", "נ" => "50",
    "ס" => "51", "ע" => "52", "פ" => "54", "ק" => "57", "ר" => "58", "ש" => "59", "ת" => "5a",
];
const RUSSIAN = [
    "П" => "50", "С" => "53", "а" => "61", "в" => "76", "д" => "64", "е" => "65", "з" => "7a", "и" => "69", "й" => "6a",
    "к" => "6b", "л" => "6c", "м" => "6d", "н" => "6e", "о" => "6f", "п" => "70", "р" => "72", "с" => "73", "т" => "74",
    "у" => "75", "ч" => "7e", "щ" => "7d", "ы" => "78", "ь" => "79", "ю" => "77", "я" => "71",
];
const GREEK = [
    "Α" => "41", "Ο" => "4f", "ά" => "59", "έ" => "5a", "ή" => "5b", "ί" => "5c", "α" => "61", "β" => "62", "ε" => "65",
    "θ" => "68", "ι" => "69", "μ" => "6c", "ν" => "6d", "ξ" => "6e", "ο" => "6f", "ρ" => "71", "ς" => "79", "σ" => "72", "ύ" => "7c",
];
const ARABIC = [
    "؟" => "21", "ا" => "41", "ب" => "42", "ت" => "43", "ج" => "45", "ح" => "46", "خ" => "47", "ز" => "4b", "س" => "4c",
    "ط" => "50", "ف" => "54", "ق" => "55", "ل" => "57", "م" => "58", "و" => "5a", "ي" => "5d",
];


/**
 * Encodes $text with a character map and fills it to 51 bytes with 7Fh. Characters outside the map are ASCII. <i> and </i> are the italics codes.
 */
function line(string $text, array $map = []): string
{
    $bytes = "";
    foreach (preg_split('/(<\/?i>)|/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) as $character) {
        $bytes .= match (true) {
            $character === "<i>"     => ITALIC_ON,
            $character === "</i>"    => ITALIC_OFF,
            isset($map[$character])  => hex2bin($map[$character]),
            default                  => $character,
        };
    }

    return str_pad($bytes, 51, "\x7F");
}


/**
 * Encodes Chinese text as UTF-16BE and fills it to 51 bytes with zero bytes.
 */
function chinese(string $text): string
{
    $bytes = "";
    foreach (preg_split('/(<\/?i>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        $bytes .= match ($part) {
            "<i>"   => "\0" . ITALIC_ON,
            "</i>"  => "\0" . ITALIC_OFF,
            default => iconv("UTF-8", "UTF-16BE", $part),
        };
    }

    return str_pad($bytes, 51, "\0");
}


/**
 * Subtitle Edit stores Hebrew lines with the punctuation at the start and end swapped and each run of other characters reversed.
 */
function hebrew(string $text): string
{
    preg_match('/\A([- !?.,():;]*)(.*?)([- !?.,():;]*)\z/u', $text, $parts);
    $mirror = fn (string $part): string => strtr(strrev($part), "()", ")(");
    $text   = $mirror($parts[3]) . $parts[2] . $mirror($parts[1]);

    return preg_replace_callback('/[\x{0000}-\x{00FF}]+/u', fn (array $run): string => strrev($run[0]), $text);
}


/**
 * Returns a block: the number, the start and end frame at 25 fps, the alignment, 7 zero bytes, line 1, 6 zero bytes,
 * line 2 and 4 zero bytes. A cue of 1 line uses line 2.
 */
function block(int $number, int $start, int $end, int $alignment, string $line1, string $line2): string
{
    return pack("n", $number) . substr(pack("N", $start), 1) . substr(pack("N", $end), 1) . chr($alignment) . str_repeat("\0", 7) .
           $line1 . str_repeat("\0", 6) . $line2 . "\0\0\0\0";
}


/**
 * Returns the header as Subtitle Edit writes it, with the language code of both lines and the font name.
 */
function fileHeader(int $language, string $font, int $blocks, string $title = ""): string
{
    $count = pack("v", $blocks);

    return str_repeat("\0", 40) . str_pad($title, 28, "\0") . str_repeat("\0", 66) . "\xA0\x05\x04\x03\x06\x06\x08\x90\0\0\0\0" .
           chr($language) . chr($language) . str_repeat("\0", 24) . "\x08\x90\0\0\0\0\0" . $count . str_pad($font, 14, "\0", STR_PAD_LEFT) .
           str_repeat("\0", 13) . $count . $count . str_repeat("\0", 34) . $font . "\x3D\x8D" . "10:00:00:00" . str_repeat("\0", 26) .
           "BTD" . str_repeat("\0", 92);
}


/**
 * Writes the file. The last block lacks its last 4 bytes, as Subtitle Edit writes it.
 */
function write(string $name, int $language, string $font, string ...$blocks): void
{
    file_put_contents(__DIR__ . "/real/$name", substr(fileHeader($language, $font, count($blocks), "Fixture") . implode("", $blocks), 0, -4));
}


$empty = str_repeat("\x7F", 51);

// English: accent codes, italics, the 3 alignments and a cue of 3 lines in 2 blocks with the same number.
write(
    "harbour_english.890", 0x09, "HLV23N.V",
    block(16, 25, 100, CENTER, line("The ferry leaves at nine."), line("Tickets are at the north gate.")),
    block(32, 125, 200, LEFT, $empty, line("<i>Café crème, Grüße aus Köln.</i>", LATIN)),
    block(48, 250, 300, RIGHT, $empty, line("Mind the step, <i>señor</i>.", LATIN)),
    block(64, 325, 400, CENTER, line("Hold on, it is cold."), line("Wait for the signal.")),
    block(64, 325, 400, CENTER, $empty, line("Ready?")),
);

write(
    "ferry_danish.890", 0x07, "VFONTL.V",
    block(16, 25, 100, CENTER, $empty, line("Færgen sejler klokken ni.", SCANDINAVIAN)),
    block(32, 125, 200, CENTER, line("Øl og smørrebrød på dækket.", SCANDINAVIAN), line("Åen er stille i aften.", SCANDINAVIAN)),
);

write(
    "library_hebrew.890", 0x8F, "HEBNOA.V",
    block(16, 25, 100, CENTER, line(hebrew("הספרייה פתוחה עד שמונה."), HEBREW), line(hebrew("תודה ולהתראות!"), HEBREW)),
    block(32, 125, 200, CENTER, $empty, line(hebrew("קומה 3, חדר 12"), HEBREW)),
);

write(
    "train_russian.890", 0x56, "KYRIL4.V",
    block(16, 25, 100, CENTER, $empty, line("Поезд отправляется в семь.", RUSSIAN)),
    block(32, 125, 200, CENTER, $empty, line("Следующая остановка: Северный вокзал.", RUSSIAN)),
);

write(
    "weather_greek.890", 0x70, "GREEK4.V",
    block(16, 25, 100, CENTER, $empty, line("Αύριο θα βρέξει.", GREEK)),
    block(32, 125, 200, CENTER, $empty, line("Ο άνεμος είναι ασθενής.", GREEK)),
);

write(
    "market_arabic.890", 0x80, "ARA19N.V",
    block(16, 25, 100, CENTER, line("السوق مفتوح اليوم.", ARABIC), line("الخبز طازج؟", ARABIC)),
);

write(
    "noodle_chinese.890", 0x91, "CCKM44.V",
    block(16, 25, 100, CENTER, str_repeat("\0", 51), chinese("面包店七点开门。")),
    block(32, 125, 200, CENTER, chinese("<i>面包</i>还是热的。"), chinese("好吃。")),
);
