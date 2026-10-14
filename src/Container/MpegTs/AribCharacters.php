<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

/**
 * The graphic sets of the 8-unit code of ARIB STD-B24 volume 1 part 2 chapter 7, and the Latin sets of ABNT NBR 15606-1.
 * Kanji come from iconv as EUC-JP bytes. The tables hold what iconv lacks: the additional kanji and symbols of rows 85 to 94.
 *
 * Adapted from xqq/libaribcaption, src/decoder/b24_conv_tables.hpp, b24_gaiji_table.hpp, b24_macros.hpp and b24_colors.cpp
 * (https://github.com/xqq/libaribcaption/blob/37dde0f32652c32aa98bf92278944d1cfb2e7354/src/decoder/), MIT.
 *
 * @internal
 */
final class AribCharacters
{
    // The final bytes F of the graphic sets. A DRCS set is 0x100 plus its final byte.
    public const KANJI                    = 0x42;
    public const ALPHANUMERIC             = 0x4A;
    public const LATIN_EXTENSION          = 0x4B;
    public const LATIN_SPECIAL            = 0x4C;
    public const HIRAGANA                 = 0x30;
    public const KATAKANA                 = 0x31;
    public const PROPORTIONAL_ALPHANUMERIC = 0x36;
    public const PROPORTIONAL_HIRAGANA    = 0x37;
    public const PROPORTIONAL_KATAKANA    = 0x38;
    public const JIS_X0201_KATAKANA       = 0x49;
    public const JIS_X0213_PLANE_1        = 0x39;
    public const JIS_X0213_PLANE_2        = 0x3A;
    public const ADDITIONAL_SYMBOLS       = 0x3B;
    public const DRCS                     = 0x100;
    public const DRCS_0                   = 0x140;
    public const MACRO                    = 0x170;

    private const TWO_BYTE_SETS = [self::KANJI, self::JIS_X0213_PLANE_1, self::JIS_X0213_PLANE_2, self::ADDITIONAL_SYMBOLS, self::DRCS_0];

    private const KANA_SYMBOLS         = "ー\u{3002}\u{300C}\u{300D}\u{3001}\u{30FB}";
    private const JIS_X0201_FULL_WIDTH = "\u{3002}\u{300C}\u{300D}\u{3001}\u{30FB}ヲァィゥェォャュョッーアイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワン\u{309B}\u{309C}";

    // Middle size characters take half the width. These Japanese symbols have a half width form, as libaribcaption writes them.
    private const HALF_WIDTH = [
        "ー" => "ｰ", "\u{3002}" => "\u{FF61}", "\u{300C}" => "\u{FF62}", "\u{300D}" => "\u{FF63}", "\u{3001}" => "\u{FF64}",
        "\u{30FB}" => "\u{FF65}", "\u{309B}" => "\u{FF9E}", "\u{309C}" => "\u{FF9F}", "\u{FFE3}" => "\u{00AF}", "\u{301C}" => "~",
        "\u{FFE5}" => "\u{00A5}", "\u{25CB}" => "\u{FFEE}", "\u{25A0}" => "\u{FFED}", "\u{2192}" => "\u{FFEB}", "\u{2190}" => "\u{FFE9}",
        "\u{2191}" => "\u{FFEA}", "\u{2193}" => "\u{FFEC}",
    ];

    private const LATIN_SPECIAL_CHARACTERS = [
        0x21 => 0x266A, 0x30 => 0x00A4, 0x31 => 0x00A6, 0x32 => 0x00A8, 0x33 => 0x00B4, 0x34 => 0x00B8, 0x35 => 0x00BC, 0x36 => 0x00BD,
        0x37 => 0x00BE, 0x40 => 0x2026, 0x41 => 0x2588, 0x42 => 0x2018, 0x43 => 0x2019, 0x44 => 0x201C, 0x45 => 0x201D, 0x46 => 0x2022,
        0x47 => 0x2122, 0x48 => 0x215B, 0x49 => 0x215C, 0x4A => 0x215D, 0x4B => 0x215E,
    ];

    // The 16 default macros of STD-B24 table 7-20, called by the codes 0x60 to 0x6F of the macro set.
    public const MACROS = [
        0x60 => "\x1B\x24\x42\x1B\x29\x4A\x1B\x2A\x30\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x61 => "\x1B\x24\x42\x1B\x29\x31\x1B\x2A\x30\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x62 => "\x1B\x24\x42\x1B\x29\x20\x41\x1B\x2A\x30\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x63 => "\x1B\x28\x32\x1B\x29\x34\x1B\x2A\x35\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x64 => "\x1B\x28\x32\x1B\x29\x33\x1B\x2A\x35\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x65 => "\x1B\x28\x32\x1B\x29\x20\x41\x1B\x2A\x35\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x66 => "\x1B\x28\x20\x41\x1B\x29\x20\x42\x1B\x2A\x20\x43\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x67 => "\x1B\x28\x20\x44\x1B\x29\x20\x45\x1B\x2A\x20\x46\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x68 => "\x1B\x28\x20\x47\x1B\x29\x20\x48\x1B\x2A\x20\x49\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x69 => "\x1B\x28\x20\x4A\x1B\x29\x20\x4B\x1B\x2A\x20\x4C\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x6A => "\x1B\x28\x20\x4D\x1B\x29\x20\x4E\x1B\x2A\x20\x4F\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x6B => "\x1B\x24\x42\x1B\x29\x20\x42\x1B\x2A\x30\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x6C => "\x1B\x24\x42\x1B\x29\x20\x43\x1B\x2A\x30\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x6D => "\x1B\x24\x42\x1B\x29\x20\x44\x1B\x2A\x30\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x6E => "\x1B\x28\x31\x1B\x29\x30\x1B\x2A\x4A\x1B\x2B\x20\x70\x0F\x1B\x7D",
        0x6F => "\x1B\x28\x4A\x1B\x29\x32\x1B\x2A\x20\x41\x1B\x2B\x20\x70\x0F\x1B\x7D",
    ];

    // The additional kanji of rows 85 and 86 and the additional symbols of rows 90 to 94, from column 1. 0 marks an empty cell.
    private const ADDITIONAL = [
        85 => [
            0x3402, 0x20158, 0x4EFD, 0x4EFF, 0x4F9A, 0x4FC9, 0x509C, 0x511E, 0x51BC, 0x351F,
            0x5307, 0x5361, 0x536C, 0x8A79, 0x20BB7, 0x544D, 0x5496, 0x549C, 0x54A9, 0x550E,
            0x554A, 0x5672, 0x56E4, 0x5733, 0x5734, 0xFA10, 0x5880, 0x59E4, 0x5A23, 0x5A55,
            0x5BEC, 0xFA11, 0x37E2, 0x5EAC, 0x5F34, 0x5F45, 0x5FB7, 0x6017, 0xFA6B, 0x6130,
            0x6624, 0x66C8, 0x66D9, 0x66FA, 0x66FB, 0x6852, 0x9FC4, 0x6911, 0x693B, 0x6A45,
            0x6A91, 0x6ADB, 0x233CC, 0x233FE, 0x235C4, 0x6BF1, 0x6CE0, 0x6D2E, 0xFA45, 0x6DBF,
            0x6DCA, 0x6DF8, 0xFA46, 0x6F5E, 0x6FF9, 0x7064, 0xFA6C, 0x242EE, 0x7147, 0x71C1,
            0x7200, 0x739F, 0x73A8, 0x73C9, 0x73D6, 0x741B, 0x7421, 0xFA4A, 0x7426, 0x742A,
            0x742C, 0x7439, 0x744B, 0x3EDA, 0x7575, 0x7581, 0x7772, 0x4093, 0x78C8, 0x78E0,
            0x7947, 0x79AE, 0x9FC6, 0x4103,
        ],
        86 => [
            0x9FC5, 0x79DA, 0x7A1E, 0x7B7F, 0x7C31, 0x4264, 0x7D8B, 0x7FA1, 0x8118, 0x813A,
            0xFA6D, 0x82AE, 0x845B, 0x84DC, 0x84EC, 0x8559, 0x85CE, 0x8755, 0x87EC, 0x880B,
            0x88F5, 0x89D2, 0x8AF6, 0x8DCE, 0x8FBB, 0x8FF6, 0x90DD, 0x9127, 0x912D, 0x91B2,
            0x9233, 0x9288, 0x9321, 0x9348, 0x9592, 0x96DE, 0x9903, 0x9940, 0x9AD9, 0x9BD6,
            0x9DD7, 0x9EB4, 0x9EB5, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0,
        ],
        87 => [
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0,
        ],
        88 => [
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0,
        ],
        89 => [
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0,
        ],
        90 => [
            0x26CC, 0x26CD, 0x2757, 0x26CF, 0x26D0, 0x26D1, 0, 0x26D2, 0x26D5, 0x26D3,
            0x26D4, 0, 0, 0, 0, 0x1F17F, 0x1F18A, 0, 0, 0x26D6,
            0x26D7, 0x26D8, 0x26D9, 0x26DA, 0x26DB, 0x26DC, 0x26DD, 0x26DE, 0x26DF, 0x26E0,
            0x26E1, 0x2B55, 0x3248, 0x3249, 0x324A, 0x324B, 0x324C, 0x324D, 0x324E, 0x324F,
            0, 0, 0, 0, 0x2491, 0x2492, 0x2493, 0x1F14A, 0x1F14C, 0x1F13F,
            0x1F146, 0x1F14B, 0x1F210, 0x1F211, 0x1F212, 0x1F213, 0x1F142, 0x1F214, 0x1F215, 0x1F216,
            0x1F14D, 0x1F131, 0x1F13D, 0x2B1B, 0x2B24, 0x1F217, 0x1F218, 0x1F219, 0x1F21A, 0x1F21B,
            0x26BF, 0x1F21C, 0x1F21D, 0x1F21E, 0x1F21F, 0x1F220, 0x1F221, 0x1F222, 0x1F223, 0x1F224,
            0x1F225, 0x1F14E, 0x3299, 0x1F200, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0,
        ],
        91 => [
            0x26E3, 0x2B56, 0x2B57, 0x2B58, 0x2B59, 0x2613, 0x328B, 0x3012, 0x26E8, 0x3246,
            0x3245, 0x26E9, 0x0FD6, 0x26EA, 0x26EB, 0x26EC, 0x2668, 0x26ED, 0x26EE, 0x26EF,
            0x2693, 0x2708, 0x26F0, 0x26F1, 0x26F2, 0x26F3, 0x26F4, 0x26F5, 0x1F157, 0x24B9,
            0x24C8, 0x26F6, 0x1F15F, 0x1F18B, 0x1F18D, 0x1F18C, 0x1F179, 0x26F7, 0x26F8, 0x26F9,
            0x26FA, 0x1F17B, 0x260E, 0x26FB, 0x26FC, 0x26FD, 0x26FE, 0x1F17C, 0x26FF, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0,
        ],
        92 => [
            0x27A1, 0x2B05, 0x2B06, 0x2B07, 0x2B2F, 0x2B2E, 0x5E74, 0x6708, 0x65E5, 0x5186,
            0x33A1, 0x33A5, 0x339D, 0x33A0, 0x33A4, 0x1F100, 0x2488, 0x2489, 0x248A, 0x248B,
            0x248C, 0x248D, 0x248E, 0x248F, 0x2490, 0x6C0F, 0x526F, 0x5143, 0x6545, 0x524D,
            0x65B0, 0x1F101, 0x1F102, 0x1F103, 0x1F104, 0x1F105, 0x1F106, 0x1F107, 0x1F108, 0x1F109,
            0x1F10A, 0x3233, 0x3236, 0x3232, 0x3231, 0x3239, 0x3244, 0x25B6, 0x25C0, 0x3016,
            0x3017, 0x27D0, 0x00B2, 0x00B3, 0x1F12D, 0xE2A5, 0xE2A6, 0xE2A7, 0xE2A8, 0xE2A9,
            0xE2AA, 0xE2AB, 0xE2AC, 0xE2AD, 0xE2AE, 0xE2AF, 0xE2B0, 0xE2B1, 0xE2B2, 0xE2B3,
            0xE2B4, 0xE2B5, 0xE2B6, 0xE2B7, 0xE2B8, 0xE2B9, 0xE2BA, 0xE2BB, 0xE2BC, 0xE2BD,
            0xE2BE, 0xE2BF, 0xE2C0, 0xE2C1, 0xE2C2, 0x1F12C, 0x1F12B, 0x3247, 0x1F190, 0x1F226,
            0x213B, 0, 0, 0,
        ],
        93 => [
            0x322A, 0x322B, 0x322C, 0x322D, 0x322E, 0x322F, 0x3230, 0x3237, 0x337E, 0x337D,
            0x337C, 0x337B, 0x2116, 0x2121, 0x3036, 0x26BE, 0x1F240, 0x1F241, 0x1F242, 0x1F243,
            0x1F244, 0x1F245, 0x1F246, 0x1F247, 0x1F248, 0x1F12A, 0x1F227, 0x1F228, 0x1F229, 0x1F214,
            0x1F22A, 0x1F22B, 0x1F22C, 0x1F22D, 0x1F22E, 0x1F22F, 0x1F230, 0x1F231, 0x2113, 0x338F,
            0x3390, 0x33CA, 0x339E, 0x33A2, 0x3371, 0, 0, 0x00BD, 0x2189, 0x2153,
            0x2154, 0x00BC, 0x00BE, 0x2155, 0x2156, 0x2157, 0x2158, 0x2159, 0x215A, 0x2150,
            0x215B, 0x2151, 0x2152, 0x2600, 0x2601, 0x2602, 0x26C4, 0x2616, 0x2617, 0x26C9,
            0x26CA, 0x2666, 0x2665, 0x2663, 0x2660, 0x26CB, 0x2A00, 0x203C, 0x2049, 0x26C5,
            0x2614, 0x26C6, 0x2603, 0x26C7, 0x26A1, 0x26C8, 0, 0x269E, 0x269F, 0x266C,
            0x260E, 0, 0, 0,
        ],
        94 => [
            0x2160, 0x2161, 0x2162, 0x2163, 0x2164, 0x2165, 0x2166, 0x2167, 0x2168, 0x2169,
            0x216A, 0x216B, 0x2470, 0x2471, 0x2472, 0x2473, 0x2474, 0x2475, 0x2476, 0x2477,
            0x2478, 0x2479, 0x247A, 0x247B, 0x247C, 0x247D, 0x247E, 0x247F, 0x3251, 0x3252,
            0x3253, 0x3254, 0x1F110, 0x1F111, 0x1F112, 0x1F113, 0x1F114, 0x1F115, 0x1F116, 0x1F117,
            0x1F118, 0x1F119, 0x1F11A, 0x1F11B, 0x1F11C, 0x1F11D, 0x1F11E, 0x1F11F, 0x1F120, 0x1F121,
            0x1F122, 0x1F123, 0x1F124, 0x1F125, 0x1F126, 0x1F127, 0x1F128, 0x1F129, 0x3255, 0x3256,
            0x3257, 0x3258, 0x3259, 0x325A, 0x2460, 0x2461, 0x2462, 0x2463, 0x2464, 0x2465,
            0x2466, 0x2467, 0x2468, 0x2469, 0x246A, 0x246B, 0x246C, 0x246D, 0x246E, 0x246F,
            0x2776, 0x2777, 0x2778, 0x2779, 0x277A, 0x277B, 0x277C, 0x277D, 0x277E, 0x277F,
            0x24EB, 0x24EC, 0x325B, 0,
        ],
    ];

    /** @var list<?string> */
    private static array $colors = [];


    public static function bytes(int $set): int
    {
        return in_array($set, self::TWO_BYTE_SETS, true) ? 2 : 1;
    }


    /**
     * Returns the character of the codes $first and $second, from 0x21 to 0x7E, in $set. $halfWidth is true for middle size text.
     * A mosaic set gives an empty string.
     */
    public static function character(int $set, int $first, int $second, bool $halfWidth, bool $latin): string
    {
        $character = match ($set) {
            self::KANJI, self::JIS_X0213_PLANE_1, self::ADDITIONAL_SYMBOLS => self::kanji($first, $second, ""),
            self::JIS_X0213_PLANE_2                                         => self::kanji($first, $second, "\x8F"),
            self::ALPHANUMERIC, self::PROPORTIONAL_ALPHANUMERIC             => self::alphanumeric($first, $halfWidth || $latin),
            self::HIRAGANA, self::PROPORTIONAL_HIRAGANA                     => self::kana($first, 0x3041, 0x73, "ゝゞ"),
            self::KATAKANA, self::PROPORTIONAL_KATAKANA                     => self::kana($first, 0x30A1, 0x76, "ヽヾ"),
            self::JIS_X0201_KATAKANA                                        => $first > 0x5F ? "\u{FFFD}"
                : ($halfWidth ? mb_chr(0xFF61 + $first - 0x21) : mb_substr(self::JIS_X0201_FULL_WIDTH, $first - 0x21, 1)),
            self::LATIN_EXTENSION                                           => $first === 0x2D ? "ÿ" : (string) iconv("ISO-8859-15", "UTF-8", chr($first | 0x80)),
            self::LATIN_SPECIAL                                             => mb_chr(self::LATIN_SPECIAL_CHARACTERS[$first] ?? 0xFFFD),
            default                                                         => "",
        };
        if (!$halfWidth || $latin) {
            return $character;
        }

        $code = mb_ord($character === "" ? " " : $character);

        return match (true) {
            $code >= 0xFF01 && $code <= 0xFF5E => chr($code - 0xFEE0),
            $code === 0x3000                    => " ",
            default                             => self::HALF_WIDTH[$character] ?? $character,
        };
    }


    /**
     * Returns the text color of $index in $palette, from the 128 colors of STD-B24 table 9-1, or null for transparent.
     */
    public static function color(int $palette, int $index): ?string
    {
        if (self::$colors === []) {
            self::$colors = self::buildColors();
        }

        return self::$colors[$palette * 16 + $index] ?? null;
    }


    private static function kanji(int $first, int $second, string $prefix): string
    {
        $row = $first - 0x20;
        if ($row >= 85) {
            $code = self::ADDITIONAL[$row][$second - 0x21] ?? 0;

            return mb_chr($code === 0 ? 0xFFFD : $code);
        }

        $bytes     = $prefix . chr($first | 0x80) . chr($second | 0x80);
        $character = $prefix === "" ? @iconv("EUC-JP", "UTF-8", $bytes) : false;
        // JIS X 0213 cells outside JIS X 0208 need an iconv with EUC-JISX0213, such as glibc and GNU libiconv.
        $character = $character === false || $character === "" ? @iconv("EUC-JISX0213", "UTF-8", $bytes) : $character;

        return $character === false || $character === "" ? "\u{FFFD}" : $character;
    }


    private static function alphanumeric(int $code, bool $halfWidth): string
    {
        return match (true) {
            $code === 0x5C => $halfWidth ? "\u{00A5}" : "\u{FFE5}",
            $code === 0x7E => $halfWidth ? "\u{203E}" : "\u{FFE3}",
            default        => $halfWidth ? chr($code) : mb_chr(0xFF01 + $code - 0x21),
        };
    }


    private static function kana(int $code, int $start, int $last, string $iterationMarks): string
    {
        return match (true) {
            $code <= $last => mb_chr($start + $code - 0x21),
            $code <= 0x76  => "\u{3000}",
            $code <= 0x78  => mb_substr($iterationMarks, $code - 0x77, 1),
            default        => mb_substr(self::KANA_SYMBOLS, $code - 0x79, 1),
        };
    }


    /**
     * The 8 full and 7 half intensity colors with transparent between them, then the other mixes of the levels 0, 85, 170 and 255.
     * Indexes 65 to 127 repeat the colors without transparent at half opacity, which a <font> tag cannot show.
     *
     * @return list<?string>
     */
    private static function buildColors(): array
    {
        $levels = [];
        foreach ([255, 170] as $level) {
            for ($bits = 0; $bits < 8; $bits++) {
                $levels[] = [$bits & 1 ? $level : 0, $bits & 2 ? $level : 0, $bits & 4 ? $level : 0];
            }
        }
        $levels[8] = null;
        foreach ([0, 85, 170, 255] as $red) {
            foreach ([0, 85, 170, 255] as $green) {
                foreach ([0, 85, 170, 255] as $blue) {
                    if (!in_array([$red, $green, $blue], $levels, true)) {
                        $levels[] = [$red, $green, $blue];
                    }
                }
            }
        }
        $levels = array_slice([...$levels, ...array_filter($levels)], 0, 128);

        return array_map(fn (?array $rgb): ?string => $rgb === null ? null : vsprintf("#%02x%02x%02x", $rgb), $levels);
    }
}
