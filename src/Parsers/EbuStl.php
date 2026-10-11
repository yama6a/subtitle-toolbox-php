<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Timecode;

/**
 * The block sizes, GSI fields, code tables and control codes of EBU Tech 3264.
 * A file holds one GSI (General Subtitle Information) block, then TTI (Text and Timing Information) blocks.
 *
 * @see https://tech.ebu.ch/docs/tech/tech3264.pdf
 *
 * @internal
 */
final class EbuStl
{
    public const GSI_BLOCK_SIZE = 1024;
    public const TTI_BLOCK_SIZE = 128;
    public const TEXT_FIELD_SIZE = 112;

    // Offset and length of each GSI field by its mnemonic, EBU Tech 3264 table 1. The spare bytes have no mnemonic.
    public const GSI_FIELDS = [
        "CPN" => [0, 3], "DFC" => [3, 8], "DSC" => [11, 1], "CCT" => [12, 2], "LC" => [14, 2],
        "OPT" => [16, 32], "OET" => [48, 32], "TPT" => [80, 32], "TET" => [112, 32], "TN" => [144, 32],
        "TCD" => [176, 32], "SLR" => [208, 16], "CD" => [224, 6], "RD" => [230, 6], "RN" => [236, 2],
        "TNB" => [238, 5], "TNS" => [243, 5], "TNG" => [248, 3], "MNC" => [251, 2], "MNR" => [253, 2],
        "TCS" => [255, 1], "TCP" => [256, 8], "TCF" => [264, 8], "TND" => [272, 1], "DSN" => [273, 1],
        "CO" => [274, 3], "PUB" => [277, 32], "EN" => [309, 32], "ECD" => [341, 32], "spare" => [373, 75],
        "UDA" => [448, 576],
    ];

    // Byte offsets of the TTI block fields, EBU Tech 3264 table 2. The text field follows the 16 header bytes.
    public const TTI_SGN = 0;
    public const TTI_SN  = 1;
    public const TTI_EBN = 3;
    public const TTI_CS  = 4;
    public const TTI_TCI = 5;
    public const TTI_TCO = 9;
    public const TTI_VP  = 13;
    public const TTI_JC  = 14;
    public const TTI_CF  = 15;
    public const TTI_TF  = 16;

    public const SUBTITLE_NUMBER_SIZE = 2;
    public const TIME_CODE_SIZE       = 4;

    public const FRAME_RATES = ["STL25.01" => 25, "STL30.01" => 30];

    // EBU Tech 3264 section 4.2.2 and appendix 1.
    public const GSI_CODE_PAGES = [
        "437" => CodePage::CP_437,
        "850" => CodePage::CP_850,
        "860" => CodePage::CP_860,
        "863" => CodePage::CP_863,
        "865" => CodePage::CP_865,
    ];

    // EBU Tech 3264 section 4.2.2 and appendix 2. Table 00 is ISO 6937.
    public const CHARACTER_CODE_TABLES = [
        "00" => null,
        "01" => CodePage::ISO_8859_5,
        "02" => CodePage::ISO_8859_6,
        "03" => CodePage::ISO_8859_7,
        "04" => CodePage::ISO_8859_8,
    ];

    // The language codes of EBU Tech 3264 appendix 3 as BCP 47 tags. Codes without a tag are left out.
    public const LANGUAGES = [
        "01" => "sq", "02" => "br", "03" => "ca", "04" => "hr", "05" => "cy", "06" => "cs", "07" => "da", "08" => "de",
        "09" => "en", "0A" => "es", "0B" => "eo", "0C" => "et", "0D" => "eu", "0E" => "fo", "0F" => "fr", "10" => "fy",
        "11" => "ga", "12" => "gd", "13" => "gl", "14" => "is", "15" => "it", "16" => "se", "17" => "la", "18" => "lv",
        "19" => "lb", "1A" => "lt", "1B" => "hu", "1C" => "mt", "1D" => "nl", "1E" => "no", "1F" => "oc", "20" => "pl",
        "21" => "pt", "22" => "ro", "23" => "rm", "24" => "sr", "25" => "sk", "26" => "sl", "27" => "fi", "28" => "sv",
        "29" => "tr", "2A" => "nl-BE", "2B" => "wa",
        "45" => "zu", "46" => "vi", "47" => "uz", "48" => "ur", "49" => "uk", "4A" => "th", "4B" => "te", "4C" => "tt",
        "4D" => "ta", "4E" => "tg", "4F" => "sw", "50" => "srn", "51" => "so", "52" => "si", "53" => "sn", "54" => "sh",
        "55" => "rue", "56" => "ru", "57" => "qu", "58" => "ps", "59" => "pa", "5A" => "fa", "5B" => "pap", "5C" => "or",
        "5D" => "ne", "5E" => "nd", "5F" => "mr", "60" => "mo", "61" => "ms", "62" => "mg", "63" => "mk", "64" => "lo",
        "65" => "ko", "66" => "km", "67" => "kk", "68" => "kn", "69" => "ja", "6A" => "id", "6B" => "hi", "6C" => "he",
        "6D" => "ha", "6E" => "gn", "6F" => "gu", "70" => "el", "71" => "ka", "72" => "ff", "73" => "prs", "74" => "cv",
        "75" => "zh", "76" => "my", "77" => "bg", "78" => "bn", "79" => "be", "7A" => "bm", "7B" => "az", "7C" => "as",
        "7D" => "hy", "7E" => "ar", "7F" => "am",
    ];

    // The teletext alpha color codes 00h to 07h, EBU Tech 3264 appendix 2. White is the default of each row.
    public const COLORS = ["#000000", "#ff0000", "#00ff00", "#ffff00", "#0000ff", "#ff00ff", "#00ffff", "#ffffff"];

    public const WHITE = 7;

    public const TELETEXT_MAX_ROW = 23;

    public const JUSTIFY_LEFT  = 1;
    public const JUSTIFY_RIGHT = 3;

    // The bottom, middle and top rows of the numeric keypad layout, as offsets from alignment 1.
    private const KEYPAD_BOTTOM_ROW = 0;
    private const KEYPAD_MIDDLE_ROW = 3;
    private const KEYPAD_TOP_ROW    = 6;

    public const ITALICS_ON      = 0x80;
    public const ITALICS_OFF     = 0x81;
    public const UNDERLINE_ON    = 0x82;
    public const UNDERLINE_OFF   = 0x83;
    public const NEW_LINE        = 0x8A;
    public const UNUSED_SPACE    = 0x8F;
    public const LAST_BLOCK      = 0xFF;
    public const USER_DATA_BLOCK = 0xFE;


    /**
     * Returns the cue alignment for a vertical position and a justification code.
     * The top, middle and bottom thirds of the rows from 0 to $maxRow give the row of the numeric keypad layout.
     */
    public static function alignment(int $verticalPosition, int $justificationCode, int $maxRow): Alignment
    {
        $column = match ($justificationCode) {
            self::JUSTIFY_LEFT  => 0,
            self::JUSTIFY_RIGHT => 2,
            default             => 1,
        };

        $row = match (true) {
            3 * $verticalPosition < $maxRow     => self::KEYPAD_TOP_ROW,
            3 * $verticalPosition < 2 * $maxRow => self::KEYPAD_MIDDLE_ROW,
            default                             => self::KEYPAD_BOTTOM_ROW,
        };

        return Alignment::from(1 + $row + $column);
    }


    /**
     * Returns the highest vertical position: teletext row 23, or the maximum number of displayable rows for open subtitles.
     */
    public static function maxRow(array $gsi): int
    {
        if (in_array($gsi["DSC"] ?? "", ["1", "2"], true)) {
            return self::TELETEXT_MAX_ROW;
        }

        $rows = (int) ($gsi["MNR"] ?? 0);

        return $rows > 0 ? $rows : self::TELETEXT_MAX_ROW;
    }


    /**
     * Returns the GSI fields by mnemonic, decoded with the code page of the CPN field and without trailing spaces.
     *
     * @return array<string, string>
     */
    public static function readGsi(string $block): array
    {
        $codePage = self::GSI_CODE_PAGES[substr($block, 0, 3)] ?? CodePage::CP_850;

        $gsi = [];
        foreach (self::GSI_FIELDS as $field => [$offset, $length]) {
            $gsi[$field] = rtrim(CodePage::decode(substr($block, $offset, $length), $codePage), " ");
        }

        return $gsi;
    }


    /**
     * Returns the seconds of an 8-digit HHMMSSFF time code such as the TCP field, or 0 when it is not 8 digits.
     */
    public static function timeCodeToSeconds(string $timeCode, FrameRate $frameRate): float
    {
        if (!preg_match('/^(\d\d)(\d\d)(\d\d)(\d\d)$/', $timeCode, $matches)) {
            return 0.0;
        }

        return Timecode::toSecondsFromFrames((int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[4], $frameRate);
    }


    /**
     * Returns the 4 time code bytes hours, minutes, seconds and frames as an 8-digit HHMMSSFF string.
     */
    public static function timeCodeDigits(string $bytes): string
    {
        return vsprintf("%02d%02d%02d%02d", array_map("ord", str_split($bytes)));
    }
}
