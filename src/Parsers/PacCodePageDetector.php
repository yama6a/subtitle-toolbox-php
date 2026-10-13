<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\CodePageDetector;

/**
 * Picks the code page of PAC text, which the file does not store. It decodes the text with each code page and checks
 * that the result looks like the script of the code page. Greek and Cyrillic store their letters on the ASCII letter
 * bytes, so each script also needs signs that a wrong code page rarely gives: Greek accents, Cyrillic punctuation.
 * A code page with such a sign beats one without. Of two Latin code pages, the CodePageDetector score decides.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Pac.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Pac.cs), MIT.
 *
 * @internal
 */
final class PacCodePageDetector
{
    private const SINGLE_BYTE = [
        PacCodePage::Latin, PacCodePage::LatinCzech, PacCodePage::LatinTurkish, PacCodePage::Greek,
        PacCodePage::Cyrillic, PacCodePage::Hebrew, PacCodePage::Arabic, PacCodePage::Thai,
    ];
    private const DOUBLE_BYTE = [PacCodePage::Korean, PacCodePage::Japanese, PacCodePage::ChineseSimplified, PacCodePage::ChineseTraditional];

    // The alphabets of CodePageDetector that each Latin code page holds.
    private const LANGUAGES = [
        "latin"         => ["fr", "de", "es", "pt", "it", "nl", "da", "sv", "fi", "is", "ca", "sq", "pl", "hu", "cs", "sk", "sl", "hr", "ro", "lt", "lv", "et"],
        "latin-czech"   => ["cs", "sk", "pl", "hu", "sl", "hr", "ro", "de"],
        "latin-turkish" => ["tr"],
    ];

    private const NO_FIT     = 0;
    private const WEAK_FIT   = 1;
    private const STRONG_FIT = 2;

    // The share of the letters that must be in the script of the code page.
    private const MIN_SCRIPT_SHARE = 0.7;
    private const MIN_RTL_SHARE    = 0.6;

    private const MIN_THAI_MARK_SHARE = 0.1;

    // The vowel shares of text in Latin and Cyrillic letters.
    private const LATIN_VOWELS    = [0.2, 0.75];
    private const CYRILLIC_VOWELS = [0.25, 0.65];

    // Greek read as Latin turns rho into "q" without "u". Latin text has few such letters.
    private const MAX_Q_SHARE = 0.015;

    // Latin read as Cyrillic turns "m" and "s" at a word start into a soft sign or "ы", which start no Russian word.
    private const MAX_BAD_START_SHARE = 0.02;

    // Russian text has about one period or comma in 25 letters.
    private const MIN_PUNCTUATION_SHARE = 0.025;

    // Latin read as Greek puts final sigma inside words and medial sigma at word ends.
    private const MAX_BAD_SIGMA_SHARE = 0.05;

    private const MAX_UNDEFINED_SHARE = 0.01;

    private const MIN_SCORE_MARGIN = 0.5;

    // Korean in CP949 uses the KS X 1001 bytes from B0A1h. Other double-byte text read as CP949 does not.
    private const MIN_KOREAN_BYTE_SHARE = 0.9;
    private const MIN_HANGUL_SHARE      = 0.8;
    private const MIN_KANA_SHARE        = 0.2;

    // Unicode ranges, as \p{Han} and \p{Hangul} also match the CJK punctuation.
    private const HAN    = '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}';
    private const HANGUL = '\x{AC00}-\x{D7A3}';
    private const KANA   = '\x{3040}-\x{30FF}';


    /**
     * Returns the code page of the text lines, and a reason when no code page or more than one fits.
     *
     * @param list<list<array{string, string}>> $lines the runs of each line, from Pac::lines()
     * @return array{PacCodePage, ?string}
     */
    public static function detect(array $lines): array
    {
        $runs = array_merge(...$lines ?: [[]]);
        if (in_array("unicode", array_column($runs, 0), true)) {
            return [PacCodePage::Unicode, null];
        }

        // The double-byte text of a Chinese, Korean or Japanese file decides. Its single-byte text is Latin.
        $doubleBytes = implode("", array_column(array_filter($runs, fn (array $run): bool => $run[0] === "double"), 1));
        $candidates  = $doubleBytes === "" ? self::SINGLE_BYTE : self::DOUBLE_BYTE;
        if ($doubleBytes !== "") {
            $lines = array_map(fn (array $line): array => array_values(array_filter($line, fn (array $run): bool => $run[0] === "double")), $lines);
        }

        $fits      = [];
        $decodings = [];
        foreach ($candidates as $codePage) {
            $decoded   = array_map($codePage->decodeLine(...), $lines);
            $text      = implode("\n", array_column($decoded, 0));
            $undefined = array_sum(array_column($decoded, 1));
            if (!in_array($text, $decodings, true)) {
                $decodings[$codePage->value] = $text;
                $fits[$codePage->value]      = $undefined > self::MAX_UNDEFINED_SHARE * mb_strlen($text) ? self::NO_FIT : self::fit($codePage, $text, $doubleBytes);
            }
        }

        $best = max($fits);
        $top  = array_keys(array_filter($fits, fn (int $fit): bool => $fit === $best));
        if ($best === self::NO_FIT) {
            return [$candidates[0], "No code page gives text in its script."];
        }

        // Korean and Japanese read as Chinese give Han characters, but Chinese read as them rarely gives kana or Hangul.
        $kanaOrHangul = array_intersect($top, [PacCodePage::Korean->value, PacCodePage::Japanese->value]);

        return match (true) {
            count($kanaOrHangul) === 1 => [PacCodePage::from(reset($kanaOrHangul)), null],
            count($top) === 1          => [PacCodePage::from($top[0]), null],
            default                    => self::byScore($top, $decodings),
        };
    }


    /**
     * Picks one of several Latin code pages by the CodePageDetector score. Other code pages stay unclear.
     *
     * @param list<string>          $top
     * @param array<string, string> $decodings
     * @return array{PacCodePage, ?string}
     */
    private static function byScore(array $top, array $decodings): array
    {
        $unclear = [PacCodePage::from($top[0]), "These code pages fit: " . implode(", ", $top) . "."];
        if (array_diff($top, array_keys(self::LANGUAGES)) !== []) {
            return $unclear;
        }

        $scores = [];
        foreach ($top as $value) {
            $scores[$value] = CodePageDetector::scoreText($decodings[$value], self::LANGUAGES[$value]);
        }
        arsort($scores);
        [$first, $second] = array_values($scores);

        return $first - $second >= self::MIN_SCORE_MARGIN ? [PacCodePage::from((string) array_key_first($scores)), null] : $unclear;
    }


    private static function fit(PacCodePage $codePage, string $text, string $doubleBytes): int
    {
        preg_match_all('/\p{L}/u', $text, $matches);
        $letters = $matches[0];
        if ($letters === []) {
            return self::WEAK_FIT;
        }

        $share = fn (string $pattern): float => count(preg_grep($pattern, $letters)) / count($letters);

        return match ($codePage) {
            PacCodePage::Greek    => self::greekFit($text, $share('/\p{Greek}/u')),
            PacCodePage::Cyrillic => self::cyrillicFit($text, $share('/\p{Cyrillic}/u'), $share('/[аеёиоуыэюяАЕЁИОУЫЭЮЯіїєІЇЄ]/u')),
            PacCodePage::Hebrew   => self::atLeast($share('/\p{Hebrew}/u'), self::MIN_RTL_SHARE),
            PacCodePage::Arabic   => self::atLeast($share('/\p{Arabic}/u'), self::MIN_RTL_SHARE),
            PacCodePage::Thai     => self::thaiFit($text, $share('/\p{Thai}/u')),
            PacCodePage::Korean   => self::koreanFit($text, $doubleBytes),
            PacCodePage::Japanese => self::japaneseFit($text),
            PacCodePage::ChineseSimplified, PacCodePage::ChineseTraditional => self::chineseFit($codePage, $text, $doubleBytes),
            default               => self::latinFit($text, $letters),
        };
    }


    /**
     * @param list<string> $letters
     */
    private static function latinFit(string $text, array $letters): int
    {
        $ascii = array_values(preg_grep('/[A-Za-z]/', $letters));
        if ($ascii === [] || count($ascii) < self::MIN_SCRIPT_SHARE * count($letters)) {
            return self::NO_FIT;
        }

        $vowels   = count(preg_grep('/[aeiouyAEIOUY]/', $ascii)) / count($ascii);
        $qWithout = preg_match_all('/q(?!u)/i', $text) / count($letters);

        return $vowels >= self::LATIN_VOWELS[0] && $vowels <= self::LATIN_VOWELS[1] && $qWithout <= self::MAX_Q_SHARE ? self::STRONG_FIT : self::NO_FIT;
    }


    private static function greekFit(string $text, float $greekShare): int
    {
        $sigmas    = preg_match_all('/[σς]/u', $text);
        $badSigmas = preg_match_all('/ς(?=\p{L})|σ(?!\p{L})/u', $text);
        if ($greekShare < self::MIN_SCRIPT_SHARE || $badSigmas > self::MAX_BAD_SIGMA_SHARE * $sigmas) {
            return self::NO_FIT;
        }

        return preg_match('/[ΆΈΉΊΌΎΏΐάέήίΰϊϋόύώ]/u', $text) === 1 ? self::STRONG_FIT : self::WEAK_FIT;
    }


    private static function cyrillicFit(string $text, float $cyrillicShare, float $vowels): int
    {
        $words     = preg_match_all('/\p{L}+/u', $text);
        $badStarts = preg_match_all('/(?<!\p{L})[ьъыЬЪЫ]/u', $text);
        if ($cyrillicShare < self::MIN_SCRIPT_SHARE || $vowels < self::CYRILLIC_VOWELS[0] || $vowels > self::CYRILLIC_VOWELS[1] ||
            $badStarts > self::MAX_BAD_START_SHARE * $words) {
            return self::NO_FIT;
        }

        // The Cyrillic code page stores the period and the comma on the bytes of "&" and "^", which Latin text rarely has.
        return preg_match_all('/[.,]/', $text) >= self::MIN_PUNCTUATION_SHARE * preg_match_all('/\p{L}/u', $text) ? self::STRONG_FIT : self::WEAK_FIT;
    }


    private static function koreanFit(string $text, string $doubleBytes): int
    {
        $hangul = preg_match_all('/[' . self::HANGUL . ']/u', $text);
        $han    = preg_match_all('/[' . self::HAN . ']/u', $text);
        $pairs  = preg_match_all('/[\x81-\xFE][\x00-\xFF]/s', $doubleBytes);
        $common = preg_match_all('/[\xB0-\xFE][\xA1-\xFE]/', $doubleBytes);

        if ($hangul === 0 || $hangul < self::MIN_HANGUL_SHARE * ($hangul + $han) || $common < self::MIN_KOREAN_BYTE_SHARE * $pairs) {
            return self::NO_FIT;
        }

        // Korean puts spaces between words. Chinese read as Korean gives no spaces.
        return preg_match('/[' . self::HANGUL . '] [' . self::HANGUL . ']/u', $text) === 1 ? self::STRONG_FIT : self::WEAK_FIT;
    }


    private static function japaneseFit(string $text): int
    {
        $kana = preg_match_all('/[' . self::KANA . ']/u', $text);
        $han  = preg_match_all('/[' . self::HAN . ']/u', $text);

        return $kana > 0 && $kana >= self::MIN_KANA_SHARE * ($kana + $han) ? self::STRONG_FIT : self::NO_FIT;
    }


    /**
     * GB2312 text uses second bytes from A1h only. Big5 text uses second bytes from 40h to 7Eh too.
     */
    private static function chineseFit(PacCodePage $codePage, string $text, string $doubleBytes): int
    {
        $han = preg_match_all('/[' . self::HAN . ']/u', $text);
        if ($han === 0 || preg_match('/[' . self::HANGUL . self::KANA . ']/u', $text) === 1) {
            return self::NO_FIT;
        }

        $lowSecondByte = preg_match('/[\x81-\xFE][\x40-\x7E]/', $doubleBytes) === 1;

        return $lowSecondByte === ($codePage === PacCodePage::ChineseTraditional) ? self::STRONG_FIT : self::WEAK_FIT;
    }


    /**
     * Thai text has vowels and tone marks. Hebrew read as Thai gives consonants only.
     */
    private static function thaiFit(string $text, float $thaiShare): int
    {
        $marks = preg_match_all('/[\x{0E30}-\x{0E4E}]/u', $text);

        return $thaiShare >= self::MIN_SCRIPT_SHARE && $marks >= self::MIN_THAI_MARK_SHARE * preg_match_all('/[\x{0E01}-\x{0E4E}]/u', $text)
            ? self::STRONG_FIT : self::NO_FIT;
    }


    private static function atLeast(float $share, float $minimum): int
    {
        return $share >= $minimum ? self::STRONG_FIT : self::NO_FIT;
    }
}
