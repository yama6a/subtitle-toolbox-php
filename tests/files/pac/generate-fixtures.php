<?php

declare(strict_types=1);

// Writes the self-written PAC fixtures in real/ byte by byte, without the library code. The character bytes come from
// the code tables of Subtitle Edit, src/libse/SubtitleFormats/Pac.cs at commit a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c.
// Run it with: php tests/files/pac/generate-fixtures.php

const FOOTER = "\xFF\0\0\0\0\0\0\0\0\0\0\0\x11\0dummy end of file";

const CENTER = 2;
const LEFT   = 1;
const RIGHT  = 0;

const EN_DASH = "\u{2013}";

// The bytes of each character outside ASCII, and of each ASCII character that a code page moves.
const LATIN = [
    "#" => "80", "[" => "c0", "]" => "c1", "£" => "23", "§" => "7e", "¿" => "a8", "Ä" => "e541", "ß" => "81", "á" => "e261",
    "è" => "e365", "é" => "e265", "ñ" => "e06e", "ó" => "e26f", "ø" => "7d", EN_DASH => "5f", "€" => "9e",
];
const TURKISH = ["Ç" => "e643", "ç" => "e663", "ö" => "e56f", "ğ" => "ea67", "İ" => "e849", "ı" => "7b", "ş" => "e673"];
const GREEK = [
    "Α" => "41", "Η" => "47", "Ο" => "4f", "ά" => "e261", "έ" => "e265", "ή" => "e267", "ί" => "e269", "α" => "61", "β" => "62",
    "ε" => "65", "θ" => "68", "ι" => "69", "κ" => "6a", "μ" => "6c", "ν" => "6d", "ξ" => "6e", "ο" => "6f", "π" => "70",
    "ρ" => "71", "ς" => "72", "σ" => "73", "τ" => "74", "υ" => "75", "φ" => "76", "ύ" => "e275",
];
const CYRILLIC = [
    "," => "5e", "." => "26", ":" => "25", "?" => "24", "Е" => "54", "П" => "47", "С" => "43", "а" => "66", "в" => "64",
    "д" => "6c", "е" => "74", "ж" => "3b", "з" => "70", "и" => "62", "й" => "e062", "к" => "72", "л" => "6b", "м" => "76",
    "н" => "79", "о" => "6a", "п" => "67", "р" => "68", "с" => "63", "т" => "6e", "у" => "65", "ц" => "77", "ч" => "78",
    "щ" => "6f", "ы" => "73", "ь" => "6d", "ю" => "2e", "я" => "7a", "ё" => "b8",
];
const ARABIC = [
    "،" => "2c", "؟" => "3f", "ا" => "81", "ب" => "82", "ة" => "9d", "ت" => "83", "ج" => "85", "ح" => "86", "خ" => "87",
    "ر" => "8a", "ز" => "8b", "س" => "8c", "ط" => "91", "ع" => "92", "ف" => "94", "ق" => "95", "ل" => "97", "م" => "98",
    "ن" => "99", "و" => "9b", "ي" => "9f",
];
const HEBREW = [
    "א" => "a0", "ד" => "a3", "ה" => "a4", "ו" => "a5", "ח" => "a7", "י" => "a9", "ל" => "ac", "מ" => "ae", "נ" => "b0",
    "ס" => "b1", "ע" => "b2", "פ" => "b4", "ק" => "b7", "ר" => "b8", "ש" => "b9", "ת" => "ba",
];
const THAI = [
    "ข" => "a2", "ค" => "a4", "ง" => "a7", "จ" => "a8", "ณ" => "b3", "ด" => "b4", "ต" => "b5", "น" => "b9", "บ" => "ba",
    "ป" => "bb", "ม" => "c1", "ร" => "c3", "ล" => "c5", "ว" => "c7", "ส" => "ca", "ห" => "cb", "อ" => "cd", "ั" => "d1",
    "ิ" => "d4", "ุ" => "d8", "เ" => "80", "แ" => "81", "โ" => "82", "็" => "87", "้" => "89",
];


/**
 * Encodes $text with a character map. Characters outside the map are ASCII.
 */
function single(string $text, array $map): string
{
    $bytes = "";
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        $bytes .= isset($map[$character]) ? hex2bin($map[$character]) : $character;
    }

    return $bytes;
}


/**
 * Encodes a line in a Chinese, Korean or Japanese code page: the W16 code, then 2 bytes per character, 00h before ASCII.
 */
function doubleByte(string $text, string $encoding): string
{
    $bytes = "\x1FW16.";
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        $bytes .= strlen($character) === 1 ? "\0$character" : iconv("UTF-8", $encoding, $character);
    }

    return $bytes;
}


/**
 * Encodes a PAC Unicode line: the UTF-8 marker, the UTF-8 bytes with FFh for each period, and a period at the end.
 */
function unicode(string $text): string
{
    return "\x1F\xEF\xBB\xBF" . str_replace(".", "\xFF", $text) . ".";
}


/**
 * Subtitle Edit stores the Latin letters and digits of Arabic and Hebrew lines in display order.
 */
function rightToLeft(string $text, string $latin): string
{
    return preg_replace_callback("/[$latin]+/", fn (array $run): string => strrev($run[0]), $text);
}


/**
 * Returns the text bytes of the lines, each with its line start code FEh, the alignment and 03h.
 */
function lines(int $alignment, string ...$lines): string
{
    return implode("", array_map(fn (string $line): string => "\xFE" . chr($alignment) . "\x03" . $line, $lines));
}


function timeCode(int $hours, int $minutes, int $seconds, int $frames): string
{
    return pack("v2", $hours * 100 + $minutes, $seconds * 100 + $frames);
}


/**
 * Returns a record: 00h, the number, 60h, the time codes, the text length, the vertical position and the text.
 */
function record(int $number, array $in, array $out, int $verticalPosition, string $text): string
{
    return "\0" . pack("v", $number) . "\x60" . timeCode(...$in) . timeCode(...$out) . pack("v", strlen($text) + 1) . chr($verticalPosition) . $text;
}


function write(string $name, string ...$records): void
{
    file_put_contents(__DIR__ . "/real/$name", "\x01" . str_repeat("\0", 19) . implode("", $records) . FOOTER);
}


// A story record with the programme name, then subtitles from number 1. Italics, the 3 alignments and 3 rows.
write(
    "bakery_latin.pac",
    record(0, [0, 0, 0, 0], [0, 0, 0, 0], 0, lines(CENTER, "STORY:Bakery News", "Lang:ENG")),
    record(1, [0, 0, 1, 0], [0, 0, 3, 12], 10, lines(CENTER, "The ovens are warm.", "Bread is ready at six.")),
    record(2, [0, 0, 4, 0], [0, 0, 6, 0], 11, lines(CENTER, "Fresh rolls are<warm>today.")),
    record(3, [0, 0, 7, 5], [0, 0, 10, 0], 1, lines(LEFT, single("<Café crème £3 or §2", LATIN), single("Äpfel, Straße und Køge " . EN_DASH . " 5 €", LATIN))),
    record(4, [0, 0, 11, 0], [0, 0, 13, 0], 6, lines(RIGHT, single("Señor, ¿dónde está el pan?", LATIN))),
    record(5, [1, 0, 0, 0], [1, 0, 2, 24], 11, lines(CENTER, single("#1 [rye] Good night.", LATIN))),
);

// Frame numbers from 25 to 29 mark 30 fps. Subtitle numbers from 0, as Subtitle Edit writes them.
write(
    "harbour_30fps.pac",
    record(0, [0, 0, 1, 27], [0, 0, 3, 29], 11, lines(CENTER, "Ships leave at dawn.")),
    record(1, [0, 0, 5, 15], [0, 0, 7, 0], 11, lines(CENTER, "The harbour closes at ten.")),
);

write(
    "bazaar_turkish.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, single("Çarşı saat dokuzda açılıyor.", TURKISH))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 10, lines(CENTER, single("Ağaç gölgesinde çay içelim.", TURKISH), single("Iğdır, İzmir ve Muğla", TURKISH))),
);

write(
    "weather_greek.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, single("Αύριο θα βρέξει.", GREEK))),
    record(1, [0, 0, 3, 10], [0, 0, 6, 0], 11, lines(CENTER, single("Ο άνεμος είναι ασθενής.", GREEK))),
    record(2, [0, 0, 6, 10], [0, 0, 9, 0], 10, lines(CENTER, single("Η θερμοκρασία πέφτει", GREEK), single("στους 12 βαθμούς.", GREEK))),
);

write(
    "train_cyrillic.pac",
    record(0, [0, 0, 2, 0], [0, 0, 4, 0], 11, lines(CENTER, single("Поезд отправляется в семь часов.", CYRILLIC))),
    record(1, [0, 0, 5, 0], [0, 0, 8, 0], 10, lines(CENTER, single("Следующая станция:", CYRILLIC), single("Северный вокзал.", CYRILLIC))),
    record(2, [0, 0, 9, 0], [0, 0, 11, 0], 11, lines(CENTER, single("Ещё чай, пожалуйста?", CYRILLIC))),
);

write(
    "market_arabic.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 10, lines(RIGHT, single("السوق مفتوح اليوم.", ARABIC), single("الخبز طازج؟", ARABIC))),
    record(1, [0, 0, 3, 10], [0, 0, 6, 0], 11, lines(RIGHT, single(rightToLeft("نعم، من الفرن 24 ساعة.", "0-9a-zA-Z"), ARABIC))),
);

write(
    "library_hebrew.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 10, lines(RIGHT, single("הספרייה פתוחה עד שמונה.", HEBREW), single("תודה ולהתראות.", HEBREW))),
    record(1, [0, 0, 6, 10], [0, 0, 9, 0], 11, lines(RIGHT, single(rightToLeft("קומה 3, חדר abc", "0-9a-z"), HEBREW))),
);

write(
    "temple_thai.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, single("วัดเปิดตอนเจ็ดโมง", THAI))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 10, lines(CENTER, single("ขอบคุณครับ", THAI), single("ห้องสมุดปิดแล้ว", THAI))),
);

// The Chinese, Korean and Japanese files hold their lines as double-byte text. A line in ASCII stays single-byte.
write(
    "noodle_chinese_simplified.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, doubleByte("面包店七点开门。", "CP936"))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 10, lines(CENTER, doubleByte("面包还是热的。", "CP936"), "OK")),
);

write(
    "noodle_chinese_traditional.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, doubleByte("麵包店七點開門。", "CP950"))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 11, lines(CENTER, doubleByte("麵包還是熱的。", "CP950"))),
);

write(
    "cafe_korean.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, doubleByte("빵집은 일곱 시에 문을 엽니다.", "CP949"))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 11, lines(CENTER, doubleByte("빵이 아직 따뜻해요.", "CP949"))),
);

write(
    "station_japanese.pac",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 11, lines(CENTER, doubleByte("パン屋は七時に開きます。", "CP932"))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 11, lines(CENTER, doubleByte("パンはまだ温かいです。", "CP932"))),
);

// PAC Unicode, as Subtitle Edit writes it: 80h 80h 80h before the text of each record.
write(
    "bakery_unicode.fpc",
    record(0, [0, 0, 1, 0], [0, 0, 3, 0], 10, "\x80\x80\x80" . lines(CENTER, unicode("Grüße aus Køge."), unicode("東京 " . EN_DASH . " Αθήνα"))),
    record(1, [0, 0, 4, 0], [0, 0, 6, 0], 11, "\x80\x80\x80" . lines(LEFT, unicode("<Fresh bread."))),
);
