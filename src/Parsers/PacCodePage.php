<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Markup;

/**
 * A code page of PAC text. The value is the "codePage" field of the PAC format data.
 * Unicode is PAC Unicode (.fpc), which stores each line as UTF-8. The Chinese, Korean and Japanese code pages store
 * their lines as double-byte text after a W16 code. Single-byte text in a file with these code pages is Latin.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Pac.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Pac.cs), MIT.
 *
 * @internal
 */
enum PacCodePage: string
{
    case Latin              = "latin";
    case LatinCzech         = "latin-czech";
    case LatinTurkish       = "latin-turkish";
    case Greek              = "greek";
    case Cyrillic           = "cyrillic";
    case Arabic             = "arabic";
    case Hebrew             = "hebrew";
    case Thai               = "thai";
    case ChineseTraditional = "chinese-traditional";
    case ChineseSimplified  = "chinese-simplified";
    case Korean             = "korean";
    case Japanese           = "japanese";
    case Unicode            = "unicode";

    // The iconv names that ReadOptions::$encoding can give for each code page, in upper case.
    private const ENCODING_NAMES = [
        "latin"               => ["ISO-8859-1", "ISO8859-1", "LATIN1", "WINDOWS-1252", "CP1252"],
        "latin-czech"         => ["ISO-8859-2", "ISO8859-2", "LATIN2", "WINDOWS-1250", "CP1250"],
        "latin-turkish"       => ["ISO-8859-9", "ISO8859-9", "LATIN5", "WINDOWS-1254", "CP1254"],
        "greek"               => ["ISO-8859-7", "ISO8859-7", "WINDOWS-1253", "CP1253"],
        "cyrillic"            => ["ISO-8859-5", "ISO8859-5", "WINDOWS-1251", "CP1251"],
        "arabic"              => ["ISO-8859-6", "ISO8859-6", "WINDOWS-1256", "CP1256"],
        "hebrew"              => ["ISO-8859-8", "ISO8859-8", "WINDOWS-1255", "CP1255"],
        "thai"                => ["WINDOWS-874", "CP874", "TIS-620", "ISO-8859-11", "ISO8859-11"],
        "chinese-traditional" => ["BIG5", "BIG-5", "CP950"],
        "chinese-simplified"  => ["GBK", "GB2312", "GB18030", "CP936"],
        "korean"              => ["CP949", "EUC-KR", "UHC"],
        "japanese"            => ["SHIFT_JIS", "SJIS", "CP932"],
        "unicode"             => ["UTF-8", "UTF8"],
    ];

    public static function fromEncodingName(string $name): ?self
    {
        foreach (self::ENCODING_NAMES as $value => $names) {
            if (in_array(strtoupper($name), $names, true)) {
                return self::from($value);
            }
        }

        return null;
    }


    /**
     * Returns the code page of the single-byte text: Latin for the double-byte code pages and for Unicode.
     */
    public function singleByte(): self
    {
        return $this->doubleByteEncoding() === null && $this !== self::Unicode ? $this : self::Latin;
    }


    public function isLatin(): bool
    {
        return in_array($this->singleByte(), [self::Latin, self::LatinCzech, self::LatinTurkish], true);
    }


    public function isRightToLeft(): bool
    {
        return $this === self::Arabic || $this === self::Hebrew;
    }


    /**
     * Returns the iconv name of the double-byte text, or null for a single-byte code page.
     */
    public function doubleByteEncoding(): ?string
    {
        return match ($this) {
            self::ChineseTraditional => "CP950",
            self::ChineseSimplified  => "CP936",
            self::Korean             => "CP949",
            self::Japanese           => "CP932",
            default                  => null,
        };
    }


    /**
     * Decodes the runs of a text line from Pac::lines(). Returns the UTF-8 text and the number of bytes that the code
     * page lacks.
     *
     * @param list<array{string, string}> $runs
     * @return array{string, int}
     */
    public function decodeLine(array $runs): array
    {
        $text      = "";
        $undefined = 0;
        foreach ($runs as [$mode, $bytes]) {
            [$part, $missing] = match ($mode) {
                "unicode" => [mb_scrub(str_replace(Pac::UNICODE_DOT, Pac::UNICODE_END, $bytes), "UTF-8"), 0],
                "double"  => $this->decodeDoubleByte($bytes),
                default   => $this->singleByte()->decode($bytes),
            };
            $text      .= $part;
            $undefined += $missing;
        }

        return [$text, $undefined];
    }


    /**
     * Encodes a text line: as UTF-8 for Unicode, as double-byte text for a line with a character outside ASCII in a
     * double-byte code page, else as single-byte text.
     */
    public function encodeLine(string $text): string
    {
        $encoding = $this->doubleByteEncoding();
        if ($this === self::Unicode) {
            return Pac::UNICODE . str_replace(Pac::UNICODE_END, Pac::UNICODE_DOT, $text) . Pac::UNICODE_END;
        }
        if ($encoding === null || preg_match('/\A[\x20-\x7E]*\z/', $text) === 1) {
            return $this->singleByte()->encode($text);
        }

        $bytes = Pac::DOUBLE_BYTE;
        foreach (Markup::characters($text) as $character) {
            $pair   = strlen($character) === 1 ? false : @iconv("UTF-8", $encoding, $character);
            $bytes .= $pair !== false && strlen($pair) === 2 ? $pair : "\0" . (strlen($character) === 1 ? $character : CodePage::REPLACEMENT);
        }

        return $bytes;
    }


    /**
     * Reads pairs of bytes. 00h before a byte and a byte below 80h are single ASCII characters.
     *
     * @return array{string, int}
     */
    private function decodeDoubleByte(string $bytes): array
    {
        $encoding  = $this->doubleByteEncoding() ?? self::ChineseTraditional->doubleByteEncoding();
        $text      = "";
        $undefined = 0;
        $length    = strlen($bytes);
        for ($index = 0; $index < $length; $index++) {
            $byte = ord($bytes[$index]);
            if ($byte < 0x80) {
                $character = $byte === 0 ? ($bytes[++$index] ?? "") : chr($byte);
                $text     .= preg_match('/\A[\x20-\x7E]\z/', $character) === 1 ? $character : "";
                continue;
            }

            $character  = @iconv($encoding, "UTF-8", substr($bytes, $index++, 2));
            $text      .= $character === false ? "" : $character;
            $undefined += $character === false ? 1 : 0;
        }

        return [$text, $undefined];
    }


    /**
     * Decodes single-byte text. Returns the UTF-8 text and the number of bytes from 0x80 that the code page lacks.
     * Control codes, such as the colors 01h to 07h, give no text. FFh is a space.
     *
     * @return array{string, int}
     */
    public function decode(string $bytes): array
    {
        [$table] = $this->table();
        $text      = "";
        $undefined = 0;
        $length    = strlen($bytes);
        for ($index = 0; $index < $length; $index++) {
            $byte = ord($bytes[$index]);
            [$character, $size] = $this->lookup($table, $bytes, $index);
            if ($character !== null && $this === self::Arabic && in_array($byte, PacCodeTables::ARABIC_MARKS, true) && $size === 1 &&
                $index + 1 < $length && ord($bytes[$index + 1]) >= 0x80) {
                [$letter, $letterSize] = $this->lookup($table, $bytes, $index + 1);
                if ($letter !== null) {
                    [$character, $size] = [$letter . $character, $letterSize + 1];
                }
            }

            $text .= match (true) {
                $character !== null               => $character,
                $byte === 0xFF                    => " ",
                $byte >= 0x20 && $byte < 0x7F     => chr($byte),
                $byte >= 0xA0 && $this->isLatin() => self::latinByte($byte, $this),
                default                           => "",
            };
            $undefined += $character === null && $byte >= 0x80 && $byte !== 0xFF && !$this->isLatin() ? 1 : 0;
            $index     += $size - 1;
        }

        return [$text, $undefined];
    }


    /**
     * Encodes single-byte text and writes "?" for each character outside the code page.
     * An Arabic vowel mark goes before its letter.
     */
    public function encode(string $text): string
    {
        [$table, $reverse] = $this->table();
        $characters = Markup::characters($text);
        $bytes      = "";
        $count      = count($characters);
        for ($index = 0; $index < $count; $index++) {
            $encoded = null;
            foreach ([3, 2, 1] as $size) {
                $slice = array_slice($characters, $index, $size);
                $key   = implode("", $slice);
                if (count($slice) === $size && isset($reverse[$key])) {
                    $encoded = self::bytes($reverse[$key]);
                    $index  += $size - 1;
                    break;
                }
            }

            $character = $characters[$index];
            if ($encoded === null && strlen($character) === 1 && ord($character) >= 0x20 && ord($character) < 0x7F && !isset($table[ord($character)])) {
                $encoded = $character;
            }
            $encoded ??= $this->isLatin() ? self::latinFallback($character, $this) : CodePage::REPLACEMENT;

            $mark = $characters[$index + 1] ?? "";
            if ($this === self::Arabic && isset($reverse[$mark]) && in_array($reverse[$mark], PacCodeTables::ARABIC_MARKS, true)) {
                $encoded = self::bytes($reverse[$mark]) . $encoded;
                $index++;
            }
            $bytes .= $encoded;
        }

        return $bytes;
    }


    /**
     * Returns the character of the code at $index and its size in bytes, or null and 1.
     * A Latin code of 2 bytes loses to a code that starts at its second byte, so "L" before an accented letter stays "L".
     *
     * @param array<int, string> $table
     * @return array{?string, int}
     */
    private function lookup(array $table, string $bytes, int $index): array
    {
        $code = static fn (int $size): ?int => $index + $size <= strlen($bytes) ? (int) hexdec(bin2hex(substr($bytes, $index, $size))) : null;
        if ($this->isLatin()) {
            $pair = $code(2);
            $next = $index + 3 <= strlen($bytes) ? (int) hexdec(bin2hex(substr($bytes, $index + 1, 2))) : null;
            if ($pair !== null && isset($table[$pair]) && ($next === null || !isset($table[$next]))) {
                return [$table[$pair], 2];
            }

            return [$table[$code(1)] ?? null, 1];
        }

        foreach ([3, 2, 1] as $size) {
            $value = $code($size);
            if ($value !== null && isset($table[$value])) {
                return [$table[$value], $size];
            }
        }

        return [null, 1];
    }


    /**
     * @return array{array<int, string>, array<string, int>}
     */
    private function table(): array
    {
        /** @var array<string, array{array<int, string>, array<string, int>}> $tables */
        static $tables = [];
        if (!isset($tables[$this->value])) {
            $table = match ($this->singleByte()) {
                self::LatinTurkish => PacCodeTables::TURKISH + PacCodeTables::LATIN,
                self::Greek        => PacCodeTables::GREEK,
                self::Cyrillic     => PacCodeTables::CYRILLIC,
                self::Arabic       => PacCodeTables::ARABIC,
                self::Hebrew       => PacCodeTables::HEBREW,
                self::Thai         => PacCodeTables::THAI,
                default            => PacCodeTables::LATIN,
            };
            // The first code of a character wins, as in the writer of Subtitle Edit.
            $reverse = [];
            foreach ($table as $code => $character) {
                $reverse[$character] ??= $code;
            }
            $tables[$this->value] = [$table, $reverse];
        }

        return $tables[$this->value];
    }


    private static function bytes(int $code): string
    {
        return ltrim(pack("N", $code), "\0");
    }


    private static function latinByte(int $byte, self $codePage): string
    {
        return (string) @iconv(self::latinEncoding($codePage), "UTF-8", chr($byte));
    }


    private static function latinFallback(string $character, self $codePage): string
    {
        $byte = @iconv("UTF-8", self::latinEncoding($codePage), $character);

        return $byte !== false && strlen($byte) === 1 && ord($byte) >= 0xA0 ? $byte : CodePage::REPLACEMENT;
    }


    private static function latinEncoding(self $codePage): string
    {
        return match ($codePage) {
            self::LatinCzech   => "ISO-8859-2",
            self::LatinTurkish => "ISO-8859-9",
            default            => "ISO-8859-1",
        };
    }
}
