<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

/**
 * Picks the single-byte code page of text that is not UTF-8 and has no BOM.
 * It decodes the bytes from 0x80 with each candidate and scores the result. Letters of a language that uses the
 * code page score, other letters, symbols, mixed scripts and odd letter case cost. The alphabets list the letters of
 * the standard spelling of each language. They are not frequency tables.
 *
 * @internal
 */
final class CodePageDetector
{
    // The order breaks ties. Windows-1252 decodes ISO-8859-1 text the same, so ISO-8859-1 never wins a tie.
    private const CANDIDATES = [
        "Windows-1252" => ["fr", "de", "es", "pt", "it", "nl", "da", "sv", "fi", "is", "ca", "sq"],
        "Windows-1250" => ["cs", "sk", "pl", "hu", "sl", "hr", "ro", "sq", "de"],
        "Windows-1251" => ["ru", "uk", "be", "bg", "sr", "mk"],
        "Windows-1254" => ["tr"],
        "Windows-1253" => ["el"],
        "Windows-1256" => ["ar"],
        "Windows-1255" => ["he"],
        "Windows-1257" => ["lt", "lv", "et"],
        "Windows-1258" => ["vi"],
        "KOI8-R"       => ["ru", "bg"],
        "KOI8-U"       => ["uk", "ru", "be"],
        "ISO-8859-1"   => ["fr", "de", "es", "pt", "it", "nl", "da", "sv", "fi", "is", "ca", "sq"],
        "ISO-8859-2"   => ["cs", "sk", "pl", "hu", "sl", "hr", "ro", "sq", "de"],
        "ISO-8859-5"   => ["ru", "uk", "be", "bg", "sr", "mk"],
        "ISO-8859-7"   => ["el"],
        "ISO-8859-9"   => ["tr"],
    ];

    private const ALPHABETS = [
        "fr" => "àâæçéèêëîïôœùûüÿÀÂÆÇÉÈÊËÎÏÔŒÙÛÜŸ",
        "de" => "äöüßÄÖÜ",
        "es" => "áéíñóúüÁÉÍÑÓÚÜ",
        "pt" => "áâãàçéêíóôõúÁÂÃÀÇÉÊÍÓÔÕÚ",
        "it" => "àèéìíîòóùúÀÈÉÌÍÎÒÓÙÚ",
        "nl" => "áéèëíïóöúüÁÉÈËÍÏÓÖÚÜ",
        "da" => "æøåéÆØÅÉ",
        "sv" => "åäöéÅÄÖÉ",
        "fi" => "äöåšžÄÖÅŠŽ",
        "is" => "áðéíóúýþæöÁÐÉÍÓÚÝÞÆÖ",
        "ca" => "àçéèíïòóúüÀÇÉÈÍÏÒÓÚÜ",
        "sq" => "çëÇË",
        "cs" => "áčďéěíňóřšťúůýžÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ",
        "sk" => "áäčďéíĺľňóôŕšťúýžÁÄČĎÉÍĹĽŇÓÔŔŠŤÚÝŽ",
        "pl" => "ąćęłńóśźżĄĆĘŁŃÓŚŹŻ",
        "hu" => "áéíóöőúüűÁÉÍÓÖŐÚÜŰ",
        "sl" => "čšžČŠŽ",
        "hr" => "čćđšžČĆĐŠŽ",
        "ro" => "ăâîșşțţĂÂÎȘŞȚŢ",
        "tr" => "çğıöşüâîûÇĞÖŞÜÂÎÛİ",
        "lt" => "ąčęėįšųūžĄČĘĖĮŠŲŪŽ",
        "lv" => "āčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽ",
        "et" => "äõöüšžÄÕÖÜŠŽ",
        "vi" => "àáâãèéêìíòóôõùúýăđĩũơưạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹÀÁÂÃÈÉÊÌÍÒÓÔÕÙÚÝĂĐĨŨƠƯẠẢẤẦẨẪẬẮẰẲẴẶẸẺẼẾỀỂỄỆỈỊỌỎỐỒỔỖỘỚỜỞỠỢỤỦỨỪỬỮỰỲỴỶỸ",
        "el" => "αβγδεζηθικλμνξοπρσςτυφχψωάέήίόύώϊϋΐΰΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩΆΈΉΊΌΎΏΪΫ",
        "ru" => "абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ",
        "uk" => "абвгґдеєжзиіїйклмнопрстуфхцчшщьюяАБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ",
        "be" => "абвгдеёжзійклмнопрстуўфхцчшыьэюяАБВГДЕЁЖЗІЙКЛМНОПРСТУЎФХЦЧШЫЬЭЮЯ",
        "bg" => "абвгдежзийклмнопрстуфхцчшщъьюяАБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЬЮЯ",
        "sr" => "абвгдђежзијклљмнњопрстћуфхцчџшАБВГДЂЕЖЗИЈКЛЉМНЊОПРСТЋУФХЦЧЏШ",
        "mk" => "абвгдѓежзѕијклљмнњопрстќуфхцчџшАБВГДЃЕЖЗЅИЈКЛЉМНЊОПРСТЌУФХЦЧЏШ",
    ];

    /** Unicode ranges of the Arabic and Hebrew letters and vowel marks. */
    private const RANGES = [
        "ar" => [[0x0621, 0x063A], [0x0640, 0x0652], [0x0679, 0x0679], [0x067E, 0x067E], [0x0686, 0x0686], [0x0688, 0x0688],
                 [0x0691, 0x0691], [0x0698, 0x0698], [0x06A9, 0x06A9], [0x06AF, 0x06AF], [0x06BA, 0x06BA], [0x06BE, 0x06BE],
                 [0x06C1, 0x06C1], [0x06CC, 0x06CC], [0x06D2, 0x06D2]],
        "he" => [[0x05B0, 0x05C7], [0x05D0, 0x05EA]],
    ];

    private const PUNCTUATION = "\u{00A0}\u{2018}\u{2019}\u{201A}\u{201C}\u{201D}\u{201E}\u{2013}\u{2014}\u{2026}\u{00AB}\u{00BB}" .
                                "\u{2039}\u{203A}\u{00BF}\u{00A1}\u{00B0}\u{00B7}\u{20AC}\u{00A3}\u{00A7}\u{00A9}\u{00AE}\u{2122}" .
                                "\u{2022}\u{00B4}\u{00D7}\u{00B1}\u{00BD}\u{00BC}\u{00BE}\u{2116}\u{00A2}\u{00A5}\u{00B9}\u{00B2}" .
                                "\u{00B3}\u{00B6}\u{266A}";

    private const NON_LATIN = '[\p{Greek}\p{Cyrillic}\p{Arabic}\p{Hebrew}]';

    private const SAMPLE_BYTES = 65536;

    // sample() looks for lines with a byte from 0x80 in this many bytes from the start.
    private const SCAN_BYTES = 16 * self::SAMPLE_BYTES;

    // Each non-ASCII letter or symbol adds this score when the language or the punctuation list has it, and the other score when not.
    private const KNOWN_CHARACTER_SCORE   = 1;
    private const UNKNOWN_CHARACTER_SCORE = -2;

    // Each case change inside a word, lowercase line start and change between Latin and another script costs this.
    private const CASE_PENALTY = 2;

    private const SYMBOL_IN_WORD_PENALTY = 3;

    private const MIN_SCORE_PER_CHARACTER = 0.5;

    /** @var array<string, array<string, true>>|null */
    private static ?array $letters = null;

    /** @var array<string, true>|null */
    private static ?array $punctuation = null;


    /**
     * Returns the code page and the UTF-8 text of $str, or null when no candidate gives plausible text.
     *
     * @return array{string, string}|null
     */
    public static function detect(string $str): ?array
    {
        $sample = self::sample($str);
        if ($sample === "") {
            return null;
        }

        $scores = [];
        foreach (array_keys(self::CANDIDATES) as $encoding) {
            $score = self::score($sample, $encoding);
            if ($score !== null) {
                $scores[$encoding] = $score;
            }
        }
        // arsort() keeps the order of equal scores, so the candidate order breaks ties.
        arsort($scores);

        foreach ($scores as $encoding => $score) {
            if ($score <= 0) {
                break;
            }
            $converted = @iconv($encoding, "UTF-8", $str);
            if ($converted !== false) {
                return [$encoding, $converted];
            }
        }

        return null;
    }


    /** Returns the lines with a byte from 0x80, up to SAMPLE_BYTES. */
    private static function sample(string $str): string
    {
        preg_match_all('/^[^\n]*[\x80-\xFF][^\n]*$/m', substr($str, 0, self::SCAN_BYTES), $lines);

        return substr(implode("\n", $lines[0]), 0, self::SAMPLE_BYTES);
    }


    /**
     * Returns the score of $sample decoded as $encoding, or null when a byte is undefined or decodes to a C1 control.
     * A score of at most MIN_SCORE_PER_CHARACTER per non-ASCII character marks implausible text and returns 0.
     */
    private static function score(string $sample, string $encoding): ?float
    {
        $text = @iconv($encoding, "UTF-8", $sample);
        if ($text === false || preg_match('/[\x{80}-\x{9F}]/u', $text) === 1) {
            return null;
        }

        preg_match_all('/[^\x00-\x7F]/u', $text, $matches);
        $counts  = array_count_values($matches[0]);
        $letters = self::letterCounts($counts);
        $score   = self::letterScore($letters, $encoding) + self::symbolScore(array_diff_key($counts, $letters)) - self::penalty($text);

        return $score > self::MIN_SCORE_PER_CHARACTER * count($matches[0]) ? $score : 0.0;
    }


    /**
     * @param array<string, int> $counts the count of each non-ASCII character
     * @return array<string, int> the counts of the letters and marks
     */
    private static function letterCounts(array $counts): array
    {
        return array_filter($counts, fn (string $char): bool => preg_match('/[\p{L}\p{M}]/u', $char) === 1, ARRAY_FILTER_USE_KEY);
    }


    /**
     * Returns the score of the language of $encoding that fits $letters best.
     *
     * @param array<string, int> $letters
     */
    private static function letterScore(array $letters, string $encoding): float
    {
        $best = $letters === [] ? 0.0 : -INF;
        foreach (self::CANDIDATES[$encoding] as $code) {
            $alphabet = self::letters()[$code];
            $score    = 0.0;
            foreach ($letters as $char => $count) {
                $score += (isset($alphabet[$char]) ? self::KNOWN_CHARACTER_SCORE : self::UNKNOWN_CHARACTER_SCORE) * $count;
            }
            $best = max($best, $score);
        }

        return $best;
    }


    /**
     * @param array<string, int> $symbols the count of each non-ASCII character that is no letter
     */
    private static function symbolScore(array $symbols): float
    {
        $score = 0.0;
        foreach ($symbols as $char => $count) {
            $score += (isset(self::punctuation()[$char]) ? self::KNOWN_CHARACTER_SCORE : self::UNKNOWN_CHARACTER_SCORE) * $count;
        }

        return $score;
    }


    /**
     * Returns the cost of odd letter case, mixed scripts and symbols inside words.
     */
    private static function penalty(string $text): float
    {
        // Lines in capitals are common in subtitles. Other lines with more capitals than small letters are not.
        $extraCapitals = 0;
        foreach (explode("\n", $text) as $line) {
            $lower          = preg_match_all('/(?=[^\x00-\x7F])\p{Ll}/u', $line);
            $extraCapitals += $lower === 0 ? 0 : max(0, preg_match_all('/(?=[^\x00-\x7F])\p{Lu}/u', $line) - $lower);
        }
        $caseChanges = 0;
        preg_match_all('/\p{Ll}\p{Lu}/u', $text, $pairs);
        foreach ($pairs[0] as $pair) {
            $caseChanges += strlen($pair) > 2 ? 1 : 0;
        }
        preg_match_all('/^[^\p{L}\n]*([^\x00-\x7F])/mu', $text, $starts);
        $lowercaseStarts = preg_match_all('/\p{Ll}/u', implode("", $starts[1]));
        $mixedScripts    = preg_match_all('/\p{Latin}(?=' . self::NON_LATIN . ')|' . self::NON_LATIN . '(?=\p{Latin})/u', $text);
        $symbolsInWords  = preg_match_all('/(?<=\p{L})[^\p{L}\p{M}\s\x00-\x7F\x{2019}\x{00B7}](?=\p{L})/u', $text);

        return $extraCapitals + self::CASE_PENALTY * ($caseChanges + $lowercaseStarts + $mixedScripts) + self::SYMBOL_IN_WORD_PENALTY * $symbolsInWords;
    }


    /** @return array<string, array<string, true>> */
    private static function letters(): array
    {
        if (self::$letters === null) {
            foreach (self::ALPHABETS as $code => $alphabet) {
                self::$letters[$code] = array_fill_keys(preg_split('//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY), true);
            }
            foreach (self::RANGES as $code => $ranges) {
                foreach ($ranges as [$first, $last]) {
                    for ($codePoint = $first; $codePoint <= $last; $codePoint++) {
                        self::$letters[$code][self::utf8($codePoint)] = true;
                    }
                }
            }
        }

        return self::$letters;
    }


    /** @return array<string, true> */
    private static function punctuation(): array
    {
        return self::$punctuation ??= array_fill_keys(preg_split('//u', self::PUNCTUATION, -1, PREG_SPLIT_NO_EMPTY), true);
    }


    private static function utf8(int $codePoint): string
    {
        return chr(0xC0 | $codePoint >> 6) . chr(0x80 | $codePoint & 0x3F);
    }
}
