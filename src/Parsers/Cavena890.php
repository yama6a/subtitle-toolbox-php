<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;

/**
 * The layout and the character tables of Cavena 890 files. Cavena 890 has no public spec.
 * A file holds a header of 388 bytes, then blocks of 128 bytes. Each block holds the subtitle number, the start and
 * end frame, the alignment and 2 text lines of 51 bytes. The last block lacks its last 4 bytes.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Cavena890.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Cavena890.cs), MIT.
 *
 * @internal
 */
final class Cavena890
{
    public const HEADER_SIZE = 388;
    public const BLOCK_SIZE  = 128;
    public const TEXT_SIZE   = 51;
    public const BLOCK_END   = "\0\0\0\0";

    // Offsets in the header: the language codes of line 1 and line 2, the font names, and the 3 subtitle counts.
    public const LANGUAGE_1 = 146;
    public const LANGUAGE_2 = 147;
    public const FONT_1     = 187;
    public const FONT_2     = 246;
    public const COUNTS     = [179, 208, 210];

    // Offsets in a block.
    public const NUMBER    = 0;
    public const START     = 2;
    public const END       = 5;
    public const ALIGNMENT = 8;
    public const LINE_1    = 16;
    public const LINE_2    = 73;

    public const ALIGN_LEFT   = 0x50;
    public const ALIGN_RIGHT  = 0x52;
    public const ALIGN_CENTER = 0x54;

    public const FILLER     = "\x7F";
    public const DASH       = 0xBE;
    public const ITALIC_ON  = 0x88;
    public const ITALIC_OFF = 0x98;

    // The color codes F1h to F8h. The parser drops them.
    public const FIRST_COLOR = 0xF1;
    public const LAST_COLOR  = 0xF8;

    public const FRAME_RATE = 25;

    // Subtitle Edit numbers the blocks 16, 32, 48 and so on.
    public const NUMBER_STEP = 16;

    public const ENGLISH = 0x09;
    public const ARABIC_LANGUAGE = 0x80;

    // The language codes of line 1 in the header. The Arabic code counts for line 2.
    public const LANGUAGES = [
        0x07 => "scandinavian", 0x1E => "scandinavian", 0x28 => "scandinavian", 0x8F => "hebrew", 0x70 => "greek",
        0x56 => "russian", 0x90 => "chinese", 0x91 => "chinese",
    ];

    // The codes of the Latin variant: 2-byte accent codes and single bytes in place of Windows-1252. 0xE5 and 0xE6 are not "[" and "]" in Scandinavian files.
    public const LATIN = [
        0x5B => "\u{00C6}", 0x5C => "\u{00D8}", 0x5D => "\u{00C5}", 0xE2 => "@", 0xE5 => "[", 0xE6 => "]",
        0x02 => "\u{0110}", 0x1B => "\u{00E6}", 0x1C => "\u{00F8}", 0x1D => "\u{00E5}", 0x1E => "\u{00C6}", 0x1F => "\u{00D8}",
        0x7C => "\u{0142}", 0x7D => "\u{0111}", 0xEB => "\u{266A}", 0x8043 => "C", 0x8141 => "\u{00C0}", 0x8145 => "\u{00C8}",
        0x8149 => "\u{00CC}", 0x814F => "\u{00D2}", 0x8155 => "\u{00D9}", 0x8161 => "\u{00E0}", 0x8165 => "\u{00E8}", 0x8169 => "\u{00EC}",
        0x816F => "\u{00F2}", 0x8175 => "\u{00F9}", 0x8241 => "\u{00C1}", 0x8243 => "\u{0106}", 0x8245 => "\u{00C9}", 0x8249 => "\u{00CD}",
        0x824C => "\u{0139}", 0x824E => "\u{0143}", 0x824F => "\u{00D3}", 0x8252 => "\u{0154}", 0x8253 => "\u{015A}", 0x8255 => "\u{00DA}",
        0x8257 => "\u{1E82}", 0x8259 => "\u{00DD}", 0x825A => "\u{0179}", 0x8261 => "\u{00E1}", 0x8263 => "\u{0107}", 0x8265 => "\u{00E9}",
        0x8269 => "\u{00ED}", 0x826C => "\u{013A}", 0x826E => "\u{0144}", 0x826F => "\u{00F3}", 0x8272 => "\u{0155}", 0x8273 => "\u{015B}",
        0x8275 => "\u{00FA}", 0x8277 => "\u{1E83}", 0x8279 => "\u{00FD}", 0x827A => "\u{017A}", 0x8341 => "\u{00C2}", 0x8343 => "\u{0108}",
        0x8345 => "\u{00CA}", 0x8347 => "\u{011C}", 0x8348 => "\u{0124}", 0x8349 => "\u{00CE}", 0x834A => "\u{0134}", 0x834F => "\u{00D4}",
        0x8353 => "\u{015C}", 0x8355 => "\u{00DB}", 0x8357 => "\u{0174}", 0x8359 => "\u{0176}", 0x8361 => "\u{00E2}", 0x8363 => "\u{0109}",
        0x8365 => "\u{00EA}", 0x8367 => "\u{011D}", 0x8368 => "\u{0125}", 0x8369 => "\u{00EE}", 0x836A => "\u{0135}", 0x836F => "\u{00F4}",
        0x8373 => "\u{015D}", 0x8375 => "\u{00FB}", 0x8377 => "\u{0175}", 0x8379 => "\u{0177}", 0x8441 => "\u{01CD}", 0x8443 => "\u{010C}",
        0x8444 => "\u{010E}", 0x8445 => "\u{011A}", 0x8447 => "\u{01E6}", 0x8449 => "\u{01CF}", 0x844C => "\u{013D}", 0x844E => "\u{0147}",
        0x8452 => "\u{0158}", 0x8453 => "\u{0160}", 0x8454 => "\u{0164}", 0x845A => "\u{017D}", 0x8461 => "\u{01CE}", 0x8463 => "\u{010D}",
        0x8464 => "\u{010F}", 0x8465 => "\u{011B}", 0x8467 => "\u{01E7}", 0x8469 => "\u{01D0}", 0x846C => "\u{013E}", 0x846E => "\u{0148}",
        0x8472 => "\u{0159}", 0x8473 => "\u{0161}", 0x8474 => "\u{0165}", 0x847A => "\u{017E}", 0x8541 => "\u{00C3}", 0x8549 => "\u{0128}",
        0x854E => "\u{00D1}", 0x854F => "\u{00D5}", 0x8555 => "\u{0168}", 0x8561 => "\u{00E3}", 0x8569 => "\u{0129}", 0x856E => "\u{00F1}",
        0x856F => "\u{00F5}", 0x8575 => "\u{0169}", 0x8641 => "\u{00C4}", 0x8645 => "\u{00CB}", 0x8649 => "\u{00CF}", 0x864F => "\u{00D6}",
        0x8655 => "\u{00DC}", 0x8659 => "\u{0178}", 0x8661 => "\u{00E4}", 0x8665 => "\u{00EB}", 0x8669 => "\u{00EF}", 0x866F => "\u{00F6}",
        0x8675 => "\u{00FC}", 0x8679 => "\u{00FF}", 0x8C61 => "\u{00E5}", 0x8C41 => "\u{00C5}", 0x8961 => "\u{0103}", 0x8941 => "\u{0102}",
        0x8773 => "\u{015F}", 0x8753 => "\u{015E}", 0x8774 => "\u{0163}", 0x8754 => "\u{0162}", 0x8E5A => "\u{017B}", 0x8E7A => "\u{017C}",
        0x8F41 => "\u{0104}", 0x8F61 => "\u{0105}", 0x8F65 => "\u{0119}",
    ];

    public const HEBREW = [
        0x40 => "\u{05D0}", 0x41 => "\u{05D1}", 0x42 => "\u{05D2}", 0x43 => "\u{05D3}", 0x44 => "\u{05D4}", 0x45 => "\u{05D5}",
        0x46 => "\u{05D6}", 0x47 => "\u{05D7}", 0x49 => "\u{05D9}", 0x4C => "\u{05DC}", 0x4D => "\u{05DD}", 0x4E => "\u{05DE}",
        0x4F => "\u{05DF}", 0x50 => "\u{05E0}", 0x51 => "\u{05E1}", 0x52 => "\u{05E2}", 0x54 => "\u{05E4}", 0x56 => "\u{05E6}",
        0x57 => "\u{05E7}", 0x58 => "\u{05E8}", 0x59 => "\u{05E9}", 0x5A => "\u{05EA}", 0x4B => "\u{05DB}", 0x4A => "\u{05DA}",
        0x48 => "\u{05D8}", 0x53 => "\u{05E3}", 0x55 => "\u{05E5}", 0xB1 => "a", 0xB2 => "b", 0xB3 => "c",
        0xB4 => "d", 0xB5 => "e", 0xB6 => "f", 0xB7 => "g", 0xB8 => "h", 0xB9 => "i",
        0xBA => "j", 0xBB => "k", 0xBC => "l", 0xBD => "m", 0xBE => "n", 0xBF => "o",
        0xC0 => "p", 0xC1 => "q", 0xC2 => "r", 0xC3 => "s", 0xC4 => "t", 0xC5 => "u",
        0xC6 => "v", 0xC7 => "w", 0xC8 => "x", 0xC9 => "y", 0xCA => "z", 0x91 => "A",
        0xDB => "B", 0x93 => "C", 0xDC => "D", 0x95 => "E", 0x96 => "F", 0x97 => "G",
        0xAB => "H", 0x99 => "I", 0x9A => "J", 0x9B => "K", 0x9C => "L", 0xDD => "M",
        0xDE => "N", 0x9F => "O", 0xA0 => "P", 0xA1 => "Q", 0xA2 => "R", 0xA3 => "S",
        0xA4 => "T", 0xA5 => "U", 0xA6 => "V", 0xA7 => "W", 0xA8 => "X", 0xA9 => "Y",
        0xAA => "Z",
    ];

    public const RUSSIAN = [
        0x42 => "\u{0411}", 0x45 => "\u{0415}", 0x5A => "\u{0417}", 0x56 => "\u{0412}", 0x49 => "\u{0418}", 0x4E => "\u{041D}",
        0x58 => "\u{042B}", 0x51 => "\u{042F}", 0x53 => "\u{0421}", 0x72 => "\u{0440}", 0x69 => "\u{0438}", 0x71 => "\u{044F}",
        0x6E => "\u{043D}", 0x74 => "\u{0442}", 0x5C => "\u{042D}", 0x77 => "\u{044E}", 0x46 => "\u{0424}", 0x5E => "\u{0427}",
        0x44 => "\u{0414}", 0x62 => "\u{0431}", 0x73 => "\u{0441}", 0x75 => "\u{0443}", 0x64 => "\u{0434}", 0x60 => "\u{0436}",
        0x6A => "\u{0439}", 0x6C => "\u{043B}", 0x47 => "\u{0413}", 0x78 => "\u{044B}", 0x7A => "\u{0437}", 0x7E => "\u{0447}",
        0x6D => "\u{043C}", 0x67 => "\u{0433}", 0x79 => "\u{044C}", 0x70 => "\u{043F}", 0x76 => "\u{0432}", 0x55 => "\u{0423}",
        0x7D => "\u{0449}", 0x66 => "\u{0444}", 0x7C => "\u{044D}", 0x7B => "\u{0448}", 0x50 => "\u{041F}", 0x52 => "\u{0420}",
        0x68 => "\u{0445}", 0x41 => "\u{0410}", 0x4B => "\u{041A}", 0x4D => "\u{041C}", 0x4F => "\u{041E}", 0x54 => "\u{0422}",
        0x61 => "\u{0430}", 0x65 => "\u{0435}", 0x6B => "\u{043A}", 0x6F => "\u{043E}",
    ];

    public const GREEK = [
        0x23 => "\u{00A3}", 0x41 => "\u{0391}", 0x42 => "\u{0392}", 0x43 => "\u{0393}", 0x44 => "\u{0394}", 0x45 => "\u{0395}",
        0x46 => "\u{0396}", 0x47 => "\u{0397}", 0x48 => "\u{0398}", 0x49 => "\u{0399}", 0x4A => "\u{039A}", 0x4B => "\u{039B}",
        0x4C => "\u{039C}", 0x4D => "\u{039D}", 0x4E => "\u{039E}", 0x4F => "\u{039F}", 0x50 => "\u{03A0}", 0x51 => "\u{03A1}",
        0x52 => "\u{03A3}", 0x53 => "\u{03A4}", 0x54 => "\u{03A5}", 0x55 => "\u{03A6}", 0x56 => "\u{03A7}", 0x57 => "\u{03A8}",
        0x58 => "\u{03A9}", 0x59 => "\u{03AC}", 0x5A => "\u{03AD}", 0x5B => "\u{03AE}", 0x5C => "\u{03AF}", 0x5D => "\u{03CC}",
        0x5F => "\u{2014}", 0x61 => "\u{03B1}", 0x62 => "\u{03B2}", 0x63 => "\u{03B3}", 0x64 => "\u{03B4}", 0x65 => "\u{03B5}",
        0x66 => "\u{03B6}", 0x67 => "\u{03B7}", 0x68 => "\u{03B8}", 0x69 => "\u{03B9}", 0x6A => "\u{03BA}", 0x6B => "\u{03BB}",
        0x6C => "\u{03BC}", 0x6D => "\u{03BD}", 0x6E => "\u{03BE}", 0x6F => "\u{03BF}", 0x70 => "\u{03C0}", 0x71 => "\u{03C1}",
        0x72 => "\u{03C3}", 0x73 => "\u{03C4}", 0x74 => "\u{03C5}", 0x75 => "\u{03C6}", 0x76 => "\u{03C7}", 0x77 => "\u{03C8}",
        0x78 => "\u{03C9}", 0x79 => "\u{03C2}", 0x7A => "\u{03CA}", 0x7C => "\u{03CD}", 0x7D => "\u{03CE}", 0x7E => "\u{00A7}",
        0x91 => "A", 0x92 => "B", 0x93 => "C", 0x94 => "D", 0x95 => "E", 0x96 => "F",
        0x97 => "G", 0x98 => "H", 0x99 => "I", 0x9A => "J", 0x9B => "K", 0x9C => "L",
        0x9D => "M", 0x9E => "N", 0x9F => "O", 0xA0 => "P", 0xA1 => "V", 0xA2 => "W",
        0xA3 => "X", 0xA4 => "Y", 0xA5 => "Z", 0xA6 => "V", 0xA7 => "W", 0xA8 => "X",
        0xA9 => "Y", 0xAB => "Z", 0xB1 => "a", 0xB2 => "b", 0xB3 => "c", 0xB4 => "d",
        0xB5 => "e", 0xB6 => "f", 0xB7 => "g", 0xB8 => "h", 0xB9 => "i", 0xBA => "j",
        0xBB => "k", 0xBC => "l", 0xBD => "m", 0xBE => "n", 0xBF => "o", 0xC0 => "p",
        0xC1 => "q", 0xC2 => "r", 0xC3 => "s", 0xC4 => "t", 0xC5 => "u", 0xC6 => "v",
        0xC7 => "w", 0xC8 => "x", 0xC9 => "y", 0xCA => "z", 0xD9 => "\u{03B4}\u{03B9}",
    ];

    public const ARABIC = [
        0x58 => "\u{0645}", 0x41 => "\u{0627}", 0x4E => "\u{0635}", 0x42 => "\u{0628}", 0x46 => "\u{062D}", 0x57 => "\u{0644}",
        0x47 => "\u{062E}", 0x5D => "\u{064A}", 0x4A => "\u{0631}", 0x2C => "\u{060C}", 0x59 => "\u{0646}", 0x5A => "\u{0648}",
        0x43 => "\u{062A}", 0x1D => "-", 0x49 => "\u{0630}", 0x45 => "\u{062C}", 0x5B => "\u{0647}", 0x56 => "\u{0643}",
        0x21 => "\u{061F}", 0x4C => "\u{0633}", 0x52 => "\u{0639}", 0x5C => "\u{0629}", 0x5E => "\u{0649}", 0x61 => "\u{0623}",
        0x48 => "\u{062F}", 0x4D => "\u{0634}", 0x60 => "\u{0621}", 0x68 => "\u{FEF7}", 0x54 => "\u{0641}", 0x55 => "\u{0642}",
        0x22 => "!", 0x67 => "\u{FEFB}", 0x66 => "\u{0624}", 0x64 => "\u{0622}", 0x50 => "\u{0637}", 0x6A => "\u{FEF5}",
        0x4F => "\u{0636}", 0x6B => "\u{FE8B}", 0x44 => "\u{062B}", 0x51 => "\u{0638}", 0x53 => "\u{063A}", 0x4B => "\u{0632}",
        0x23 => "\"", 0x6C => "\u{0640}",
    ];


    public static function alignment(int $code): Alignment
    {
        return match ($code) {
            self::ALIGN_LEFT  => Alignment::BottomLeft,
            self::ALIGN_RIGHT => Alignment::BottomRight,
            default           => Alignment::BottomCenter,
        };
    }


    // Subtitle Edit writes this header for a new file, with the language code at 146 and 147 and the font at 187 and 246.
    public static function newHeader(): string
    {
        $font = "HLV23N.V";

        return str_repeat("\0", 134) . "\xA0\x05\x04\x03\x06\x06\x08\x90\0\0\0\0" . chr(self::ENGLISH) . chr(self::ENGLISH) .
               str_repeat("\0", 24) . "\x08\x90\0\0\0\0\0" . "\0\0" . str_pad($font, 14, "\0", STR_PAD_LEFT) . str_repeat("\0", 13) .
               "\0\0\0\0" . str_repeat("\0", 34) . $font . "\x3D\x8D" . "10:00:00:00" . str_repeat("\0", 26) . "BTD" . str_repeat("\0", 92);
    }
}
