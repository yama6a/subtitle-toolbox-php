<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StyleRuns;

/**
 * A text table of Cavena 890 files. The value is the "variant" field of the Cavena 890 format data.
 * Latin and Scandinavian text is Windows-1252 with accent codes. Chinese text is UTF-16BE.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Cavena890.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Cavena890.cs), MIT.
 *
 * @internal
 */
enum Cavena890Variant: string
{
    case Latin        = "latin";
    case Scandinavian = "scandinavian";
    case Hebrew       = "hebrew";
    case Russian      = "russian";
    case Greek        = "greek";
    case Arabic       = "arabic";
    case Chinese      = "chinese";

    // Subtitle Edit moves these characters from the start to the end of a Hebrew line, and back.
    private const HEBREW_ENDS = "-\u{2014} !?.\u{2026}\"\u{060C},():;[]+~*/<>&^%\$#\\|'";


    /**
     * Returns the variant of the language codes and the font names of the header, in the order of Subtitle Edit.
     */
    public static function fromHeader(string $header): self
    {
        $language = Cavena890::LANGUAGES[ord($header[Cavena890::LANGUAGE_1])] ?? null;
        $fonts    = [substr($header, Cavena890::FONT_1, 6), substr($header, Cavena890::FONT_2, 6)];
        $font     = fn (string $prefix): bool => str_starts_with($fonts[0], $prefix) || str_starts_with($fonts[1], $prefix);

        return match (true) {
            $language === "hebrew" || $font("HEBNOA")                                                    => self::Hebrew,
            $language === "greek" || $font("GREEK")                                                      => self::Greek,
            ord($header[Cavena890::LANGUAGE_2]) === Cavena890::ARABIC_LANGUAGE || $fonts[0] === "ARABIC" => self::Arabic,
            $language === "russian" || $font("KYRIL")                                                    => self::Russian,
            $language === "chinese" || $font("CCKM44")                                                   => self::Chinese,
            $language === "scandinavian"                                                                 => self::Scandinavian,
            default                                                                                      => self::Latin,
        };
    }


    /**
     * Decodes a text line of 51 bytes to core markup. The filler 7Fh, zero bytes and the color codes give no text.
     */
    public function decode(string $bytes): string
    {
        $runs   = [];
        $text   = "";
        $italic = false;
        $table  = $this->table();
        $codes  = $this === self::Chinese ? self::chineseCodes($bytes) : array_map("ord", str_split($bytes));
        $count  = count($codes);
        for ($index = 0; $index < $count; $index++) {
            $code = $codes[$index];
            if ($this->hasItalics() && ($code === Cavena890::ITALIC_ON || $code === Cavena890::ITALIC_OFF)) {
                $runs[] = [$text, ["i" => $italic]];
                [$text, $italic] = ["", $code === Cavena890::ITALIC_ON];
                continue;
            }

            $pair = ($code << 8) | ($codes[$index + 1] ?? 0);
            if ($code < 0x100 && isset($codes[$index + 1]) && isset($table[$pair])) {
                $text .= $table[$pair];
                $index++;
                continue;
            }

            $text .= match (true) {
                isset($table[$code])                                    => $table[$code],
                $code === Cavena890::DASH                               => "-",
                $code < 0x20 || $code === 0x7F || ($code >= Cavena890::FIRST_COLOR && $code <= Cavena890::LAST_COLOR) => "",
                $this === self::Chinese                                 => mb_chr($code, "UTF-8"),
                default                                                 => (string) @iconv("CP1252", "UTF-8", chr($code)),
            };
        }
        $runs[] = [$text, ["i" => $italic]];

        if ($this === self::Hebrew) {
            $runs = [[self::swapEnds(self::reverseAnsi($text)), []]];
        }

        return rtrim(StyleRuns::toMarkup($runs, true));
    }


    /**
     * Returns the UTF-16 code units of Chinese text. Subtitle Edit drops the zero bytes and fillers at the end first.
     *
     * @return list<int>
     */
    private static function chineseCodes(string $bytes): array
    {
        $bytes = rtrim($bytes, "\0" . Cavena890::FILLER);

        return array_values(unpack("n*", strlen($bytes) % 2 === 1 ? "$bytes\0" : $bytes) ?: []);
    }


    /**
     * Encodes a line of core markup to 51 bytes and fills the rest. It writes "?" for a character outside the table.
     */
    public function encode(string $line, bool $stripTags): string
    {
        $bytes = "";
        foreach (Markup::splitTags($line) as $index => $token) {
            if ($index % 2 === 0) {
                $bytes .= $this->encodeText(Markup::decodeEntities($token));
                continue;
            }

            $tag    = strtolower($token);
            $code   = $tag === "<i>" ? Cavena890::ITALIC_ON : ($tag === "</i>" ? Cavena890::ITALIC_OFF : null);
            $bytes .= $code === null || $stripTags || !$this->hasItalics() ? "" : ($this === self::Chinese ? "\0" : "") . chr($code);
        }

        if (strlen($bytes) > Cavena890::TEXT_SIZE) {
            throw new UnwritableContentException("Cavena 890 holds " . Cavena890::TEXT_SIZE . " bytes per line, got " . strlen($bytes) . " for \"$line\".");
        }

        return str_pad($bytes, Cavena890::TEXT_SIZE, $this === self::Chinese ? "\0" : Cavena890::FILLER);
    }


    private function encodeText(string $text): string
    {
        if ($this === self::Chinese) {
            return (string) @iconv("UTF-8", "UTF-16BE//TRANSLIT", $text);
        }
        if ($this === self::Hebrew) {
            $text = self::reverseAnsi(self::swapEnds($text));
        }

        $table   = $this->table();
        $reverse = array_flip(array_reverse($table, true));
        $bytes   = "";
        $characters = Markup::characters($text);
        for ($index = 0; $index < count($characters); $index++) {
            $pair      = $characters[$index] . ($characters[$index + 1] ?? "");
            $character = $characters[$index];
            $ascii     = preg_match('/\A[\x20-\x7E]\z/', $character) === 1 && !isset($table[ord($character)]);
            $encoded   = match (true) {
                isset($reverse[$pair])      => self::code($reverse[$pair]),
                $ascii                      => $character,
                isset($reverse[$character]) => self::code($reverse[$character]),
                default                     => $this->cp1252($character),
            };
            $index += isset($reverse[$pair]) ? 1 : 0;
            $bytes .= $encoded;
        }

        return $bytes;
    }


    /**
     * Returns the Windows-1252 byte of a character that the variant reads back as the same character, else "?".
     */
    private function cp1252(string $character): string
    {
        $byte = @iconv("UTF-8", "CP1252", $character);

        return $byte !== false && strlen($byte) === 1 && $this->decode($byte) === Markup::escapeText($character) ? $byte : CodePage::REPLACEMENT;
    }


    private function hasItalics(): bool
    {
        return in_array($this, [self::Latin, self::Scandinavian, self::Chinese], true);
    }


    /**
     * @return array<int, string>
     */
    private function table(): array
    {
        return match ($this) {
            self::Latin        => Cavena890::LATIN,
            self::Scandinavian => array_diff_key(Cavena890::LATIN, [0xE5 => true, 0xE6 => true]),
            self::Hebrew       => Cavena890::HEBREW,
            self::Russian      => Cavena890::RUSSIAN,
            self::Greek        => Cavena890::GREEK,
            self::Arabic       => Cavena890::ARABIC,
            self::Chinese      => [],
        };
    }


    private static function code(int $code): string
    {
        return ltrim(pack("n", $code), "\0");
    }


    /**
     * Reverses each run of characters up to U+00FF, as Subtitle Edit stores Hebrew lines. Doing it twice gives the line back.
     */
    private static function reverseAnsi(string $line): string
    {
        return preg_replace_callback('/[\x{0000}-\x{00FF}]+/u', fn (array $run): string => implode("", array_reverse(Markup::characters($run[0]))), $line);
    }


    /**
     * Moves the punctuation at the start of a line to its end and back, with mirrored brackets. Doing it twice gives the line back.
     */
    private static function swapEnds(string $line): string
    {
        $ends = preg_quote(self::HEBREW_ENDS, "/");
        preg_match("/\\A([$ends]*)(.*?)([$ends]*)\\z/us", $line, $parts);
        $mirror = fn (string $text): string => strtr(implode("", array_reverse(Markup::characters($text))), "()[]", ")(][");

        return $mirror($parts[3]) . $parts[2] . $mirror($parts[1]);
    }
}
