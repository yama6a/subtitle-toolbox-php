<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

/**
 * Writes the PES data of ARIB STD-B24 caption streams for test fixtures: data groups with caption management data or
 * caption statement data, and their data units. Volume 1 part 3 chapter 9 of STD-B24 gives the structure.
 */
final class AribFixtureWriter
{
    public const CS   = "\x0C";
    public const APR  = "\x0D";
    public const LS0  = "\x0F";
    public const LS1  = "\x0E";
    public const SSZ  = "\x88";
    public const MSZ  = "\x89";
    public const NSZ  = "\x8A";
    public const WHITE  = "\x87";
    public const YELLOW = "\x83";
    public const CYAN   = "\x86";

    // The patterns of DRCS glyphs in hexadecimal. Three 4-level glyphs from the sample data of xqq/libaribcaption, test/sample_data/include/sample_data.h
    // (https://github.com/xqq/libaribcaption/blob/37dde0f32652c32aa98bf92278944d1cfb2e7354/test/sample_data/include/sample_data.h), MIT.
    // Their hashes are in the DRCS table: U+FF5F and U+FF60 at 18 by 36 dots, U+51DC at 36 by 36 dots.
    public const GLYPH_FF5F =
        "0000f00f00000f00f0000f00f00000f00f00000f00f00000f00f0000f00f00000f00f00000f00f00000f00f00000f00f"
            . "00000f00f0000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f0"
            . "0f00000f00f00000f00f000000f00f00000f00f00000f00f00000f00f00000f00f00000f00f000000f00f00000f00f00"
            . "000f00f00000f00f000000f00f00000f00f0";

    public const GLYPH_FF60 =
        "00f00f00000f00f000000f00f00000f00f00000f00f00000f00f000000f00f00000f00f00000f00f00000f00f00000f0"
            . "0f00000f00f000000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f00f00000f00f000"
            . "00f00f00000f00f00000f00f0000f00f00000f00f00000f00f00000f00f00000f00f00000f00f0000f00f00000f00f00"
            . "000f00f00000f00f0000f00f00000f00f000";

    public const GLYPH_51DC =
        "00000000000f00000000000000000f000000000f00fffffffffff0000f00fffffffffff0000f00000000000000000f00"
            . "0000000000000000f00fffffffff000000f00fffffffff000000f00f0000000f000000f00f0000000f000000f00f0fff"
            . "ff0f000000f00f0fffff0f000000f00f0f000f0f000000f00f0f000f0f000000000f0fffff0f000000000f0fffff0f00"
            . "0000000f0000000f000000000f0000000f000000000fffffffff000000000fffffffff0000000000000fff0000000000"
            . "00000fff00000000f0000fff0000000000f0000fff0000000000f0fffffffffff00000f0fffffffffff0000f000000ff"
            . "f00000000f000000fff00000000f00000f0f0f0000000f00000f0f0f0000000f0000f00f00f000000f0000f00f00f000"
            . "00f000ff000f000f0000f000ff000f000f00000000000000000000000000000000000000";

    // A 2-level 16 by 18 glyph written for this repository: a frame of 1 dot. No table holds its hash.
    public const GLYPH_FRAME = "ffff" . "8001800180018001800180018001800180018001800180018001800180018001" . "ffff";

    /**
     * The control functions that set up a 960 by 540 plane, as Japanese broadcasters send them after CS.
     */
    public static function setup(): string
    {
        return self::csi("7", 0x53) . self::csi("170;30", 0x5F) . self::csi("620;480", 0x56) . self::csi("36;36", 0x57)
               . self::csi("4", 0x58) . self::csi("24", 0x59);
    }


    /**
     * A control sequence: CSI, the parameters, 0x20 and the final byte.
     */
    public static function csi(string $parameters, int $final): string
    {
        return "\x9B" . $parameters . " " . chr($final);
    }


    /**
     * APS: the active position at a row and a column of the current character size.
     */
    public static function position(int $row, int $column): string
    {
        return "\x1C" . chr(0x40 + $row) . chr(0x40 + $column);
    }


    /**
     * ACPS: the active position in dots, the left edge and the bottom of the character.
     */
    public static function dots(int $x, int $y): string
    {
        return self::csi("$x;$y", 0x61);
    }


    /**
     * TIME: wait $tenths tenths of a second before the next code.
     */
    public static function wait(int $tenths): string
    {
        return "\x9D\x20" . chr(0x40 + $tenths);
    }


    /**
     * Japanese text in the kanji set, which also holds the kana, invoked in GL. The bytes are JIS X 0208 codes.
     */
    public static function kanji(string $text): string
    {
        $bytes = (string) iconv("UTF-8", "EUC-JP", $text);

        return $bytes & str_repeat("\x7F", strlen($bytes));
    }


    /**
     * Hiragana from the hiragana set G2, invoked in GR.
     */
    public static function hiragana(string $text): string
    {
        return implode("", array_map(fn (string $character): string => chr(0xA1 + mb_ord($character) - 0x3041), mb_str_split($text)));
    }


    /**
     * Text for a Latin caption stream: ASCII in G0, other letters from the Latin extension set G2, invoked in GR.
     */
    public static function latin(string $text): string
    {
        return implode("", array_map(fn (string $character): string => strlen($character) === 1 ? $character : (string) iconv("UTF-8", "ISO-8859-15", $character),
                                     mb_str_split($text)));
    }


    /**
     * The PES data of caption management data with the languages and data units. A language is its ISO 639-2 code.
     * $utf8 sets the TCS of UCS, which ISDB-T in the Philippines uses.
     *
     * @param list<string> $languages
     */
    public static function management(array $languages, string $units = "", int $group = 0, bool $utf8 = false): string
    {
        $data = "\x3F" . chr(count($languages));
        foreach ($languages as $index => $language) {
            // Display mode 0 (automatic), format 0b1000 (960 by 540 horizontal), TCS 0 (8-unit code) or 1 (UCS)
            $data .= chr($index << 5) . $language . chr(0x80 | ($utf8 ? 0x04 : 0));
        }

        return self::dataGroup($group << 5, $data . self::uint24(strlen($units)) . $units);
    }


    /**
     * The PES data of caption statement data of the first language, in the free time control mode.
     */
    public static function statement(string $units, int $group = 0): string
    {
        return self::dataGroup($group << 5 | 1, "\x3F" . self::uint24(strlen($units)) . $units);
    }


    /**
     * A statement body data unit.
     */
    public static function text(string $codes): string
    {
        return self::unit(0x20, $codes);
    }


    /**
     * A DRCS data unit. Each glyph is [character code, depth, width, height, pattern in hexadecimal]. The depth is the number of levels.
     *
     * @param list<array{int, int, int, int, string}> $glyphs
     */
    public static function drcs(array $glyphs, bool $twoByte = false): string
    {
        $data = chr(count($glyphs));
        foreach ($glyphs as [$code, $depth, $width, $height, $pattern]) {
            $data .= pack("nC", $code, 1) . chr(0x01) . chr($depth - 2) . chr($width) . chr($height) . hex2bin($pattern);
        }

        return self::unit($twoByte ? 0x31 : 0x30, $data);
    }


    private static function unit(int $parameter, string $data): string
    {
        return "\x1F" . chr($parameter) . self::uint24(strlen($data)) . $data;
    }


    private static function dataGroup(int $id, string $data): string
    {
        $group = chr($id << 2) . "\x00\x00" . pack("n", strlen($data)) . $data;

        return "\x80\xFF\xF0" . $group . pack("n", self::crc16($group));
    }


    private static function uint24(int $value): string
    {
        return substr(pack("N", $value), 1);
    }


    /**
     * CRC-16 of STD-B24 over the data group: the polynomial x^16 + x^12 + x^5 + 1 with the initial value 0.
     */
    private static function crc16(string $data): int
    {
        $crc = 0;
        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? ($crc << 1 ^ 0x1021) & 0xFFFF : $crc << 1 & 0xFFFF;
            }
        }

        return $crc;
    }
}
