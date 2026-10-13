<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;

/**
 * The frame rates, the hex aliases and the packet layout of MCC (MacCaption) files.
 * Each line holds one SMPTE 291 ancillary data packet, and its user data is a SMPTE 334-2 caption distribution packet (CDP).
 * A CDP holds the cc_data triplets of CEA-708: CEA-608 byte pairs of field 1 and 2, and DTVCC (CEA-708) packet data.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/MacCaption10.cs and src/libse/Cea708/Smpte291M.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/58fad713d9c2b322784bc2fa52148e078dbc8759/src/libse/SubtitleFormats/MacCaption10.cs), MIT.
 *
 * @see https://www.telestream.net/pdfs/user-guides/MacCaption-UserGuide.pdf
 *
 * @internal
 */
final class Mcc
{
    public const VERSIONS = ["1.0", "2.0"];

    // Frame rate => frame labels per second, frames per second as a fraction, CDP frame rate code and cc_data triplets per CDP.
    // The codes come from SMPTE 334-2 and the triplet counts from CEA-708 table 3.
    public const FRAME_RATES = [
        "23.976" => [24, 24000, 1001, 1, 25],
        "24"     => [24, 24, 1, 2, 25],
        "25"     => [25, 25, 1, 3, 24],
        "29.97"  => [30, 30000, 1001, 4, 20],
        "30"     => [30, 30, 1, 5, 20],
        "50"     => [50, 50, 1, 6, 12],
        "59.94"  => [60, 60000, 1001, 7, 10],
        "60"     => [60, 60, 1, 8, 10],
    ];

    public const FRAME_RATE_NAMES = ["23.976", "24", "25", "29.97", "30", "50", "59.94", "60"];

    // The cc_type of a cc_data triplet.
    public const NTSC_FIELD_1 = 0;
    public const NTSC_FIELD_2 = 1;
    public const DTVCC_DATA   = 2;

    // The ANC packet of a CDP has the DID 0x61 and the SDID 0x01.
    private const CDP_DATA_ID = "\x61\x01";
    private const CDP_IDENTIFIER = 0x9669;
    private const CDP_HEADER_SIZE = 7;
    private const TIME_CODE_SECTION = 0x71;
    private const TIME_CODE_SECTION_SIZE = 5;
    private const CC_DATA_SECTION = 0x72;
    private const FOOTER_SECTION = 0x74;

    // Flags of a CDP with cc_data and an active caption service. The low bit is reserved and 1.
    private const CDP_FLAGS = 0x43;

    // The marker bits of a cc_data section count byte and of a triplet, and the cc_valid bit.
    private const CC_COUNT_MARKER = 0xE0;
    private const TRIPLET_MARKER  = 0xF8;
    private const CC_VALID        = 0x04;

    private const HEX_DIGITS = "0123456789ABCDEFabcdef";

    // Letters that stand for byte runs. G to O repeat the empty DTVCC triplet FA 00 00 one to nine times.
    private const ALIASES = [
        "G" => "\xFA\0\0",
        "H" => "\xFA\0\0\xFA\0\0",
        "I" => "\xFA\0\0\xFA\0\0\xFA\0\0",
        "J" => "\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0",
        "K" => "\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0",
        "L" => "\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0",
        "M" => "\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0",
        "N" => "\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0",
        "O" => "\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0\xFA\0\0",
        "P" => "\xFB\x80\x80",
        "Q" => "\xFC\x80\x80",
        "R" => "\xFD\x80\x80",
        "S" => "\x96\x69",
        "T" => "\x61\x01",
        "U" => "\xE1\0\0\0",
        "Z" => "\0",
    ];

    // The header text that the MCC format asks every file to hold, with the version and the time code rates of the version.
    public const HEADER = <<<'MCC'
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
        //     Time Code Rate=[%s]
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


    /**
     * Returns the key of FRAME_RATES within 0.01 of $frameRate, or null.
     */
    public static function frameRateKey(float $frameRate): ?string
    {
        foreach (array_keys(self::FRAME_RATES) as $key) {
            if (abs($frameRate - (float) $key) < 0.01) {
                return (string) $key;
            }
        }

        return null;
    }


    /**
     * Returns the time code rates that the header of a version lists. Version 2.0 adds 60DF.
     */
    public static function headerRates(string $version): string
    {
        return $version === "1.0" ? "24, 25, 30, 30DF, 50, 60" : "24, 25, 30, 30DF, 50, 60, 60DF";
    }


    /**
     * Decodes the hex digits and the alias letters of a line.
     */
    public static function decodeHex(string $text, int $lineNumber): string
    {
        $bytes = "";
        $digit = null;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $character = $text[$index];
            if (str_contains(self::HEX_DIGITS, $character)) {
                if ($digit === null) {
                    $digit = $character;
                } else {
                    $bytes .= chr((int) hexdec($digit . $character));
                    $digit = null;
                }
                continue;
            }
            $alias = self::ALIASES[$character] ?? null;
            if ($alias === null || $digit !== null) {
                throw new ParsingException("The MCC data has the invalid character \"$character\" at position " . ($index + 1) . ".", $lineNumber);
            }
            $bytes .= $alias;
        }
        if ($digit !== null) {
            throw new ParsingException("The MCC data ends with half a byte.", $lineNumber);
        }

        return $bytes;
    }


    /**
     * Writes bytes as hex digits and alias letters. U stays unused, as files disagree on its length.
     */
    public static function encodeHex(string $bytes): string
    {
        $text   = "";
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $empty = 0;
            while ($empty < 9 && substr($bytes, $offset + 3 * $empty, 3) === "\xFA\0\0") {
                $empty++;
            }
            if ($empty > 0) {
                $text   .= chr(ord("F") + $empty);
                $offset += 3 * $empty;
                continue;
            }
            foreach (["P", "Q", "R", "S", "T", "Z"] as $letter) {
                if (substr_compare($bytes, self::ALIASES[$letter], $offset, strlen(self::ALIASES[$letter])) === 0) {
                    $text   .= $letter;
                    $offset += strlen(self::ALIASES[$letter]);
                    continue 2;
                }
            }
            $text .= strtoupper(bin2hex($bytes[$offset++]));
        }

        return $text;
    }


    /**
     * Reads the CDP of an ANC packet: its frame rate code and its cc_data triplets.
     * Returns null for an ANC packet of other data. The reader ignores both checksums, as some writers leave them wrong.
     *
     * @return array{int, list<array{int, bool, int}>}|null the frame rate code, and the type, the valid flag and the 16-bit data of each triplet
     */
    public static function readPacket(string $anc, int $lineNumber): ?array
    {
        if (strlen($anc) < 3 || substr($anc, 0, 2) !== self::CDP_DATA_ID) {
            return null;
        }

        $cdp = substr($anc, 3, ord($anc[2]));
        if (strlen($cdp) < self::CDP_HEADER_SIZE || unpack("n", $cdp)[1] !== self::CDP_IDENTIFIER) {
            throw new ParsingException("The MCC packet holds no caption distribution packet.", $lineNumber);
        }

        $offset = self::CDP_HEADER_SIZE;
        if (ord($cdp[$offset]) === self::TIME_CODE_SECTION) {
            $offset += self::TIME_CODE_SECTION_SIZE;
        }
        $triplets = [];
        if ($offset + 1 < strlen($cdp) && ord($cdp[$offset]) === self::CC_DATA_SECTION) {
            $count = ord($cdp[$offset + 1]) & 0x1F;
            if ($offset + 2 + 3 * $count > strlen($cdp)) {
                throw new ParsingException("The caption distribution packet is shorter than its $count cc_data triplets.", $lineNumber);
            }
            for ($index = 0; $index < $count; $index++) {
                ["flags" => $flags, "data" => $data] = unpack("Cflags/ndata", $cdp, $offset + 2 + 3 * $index);
                $triplets[] = [$flags & 0x03, ($flags & self::CC_VALID) !== 0, $data];
            }
        }

        return [ord($cdp[3]) >> 4, $triplets];
    }


    /**
     * Writes an ANC packet with a CDP that holds the cc_data triplets.
     *
     * @param list<array{int, bool, int}> $triplets the type, the valid flag and the 16-bit data of each triplet
     */
    public static function writePacket(int $frameRateCode, int $sequence, array $triplets): string
    {
        $data = "";
        foreach ($triplets as [$type, $valid, $word]) {
            $data .= pack("Cn", self::TRIPLET_MARKER | ($valid ? self::CC_VALID : 0) | $type, $word);
        }
        $body   = chr(self::CC_DATA_SECTION) . chr(self::CC_COUNT_MARKER | count($triplets)) . $data . chr(self::FOOTER_SECTION) . pack("n", $sequence);
        $length = self::CDP_HEADER_SIZE + strlen($body) + 1;
        $cdp    = pack("nCCCn", self::CDP_IDENTIFIER, $length, $frameRateCode << 4 | 0x0F, self::CDP_FLAGS, $sequence) . $body;
        $cdp   .= chr(-array_sum(unpack("C*", $cdp)) & 0xFF);
        $anc    = self::CDP_DATA_ID . chr($length) . $cdp;

        return $anc . chr(array_sum(unpack("C*", $anc)) & 0xFF);
    }
}
