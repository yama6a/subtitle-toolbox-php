<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * Upper, lower and title case of plain text. Turkic case maps i to İ and ı to I.
 *
 * @internal
 */
final class TextCase
{
    /**
     * Tells if $language, such as "tr-TR" or "az", needs the Turkic case of i and ı.
     */
    public static function isTurkic(?string $language): bool
    {
        return in_array(StringHelpers::primaryLanguage($language), ["tr", "az"], true);
    }


    public static function upper(string $text, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($text)) {
            return strtoupper($text);
        }

        return mb_strtoupper($turkic ? str_replace("i", "İ", $text) : $text, "UTF-8");
    }


    public static function lower(string $text, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($text)) {
            return strtolower($text);
        }

        $lower = mb_strtolower($turkic ? str_replace(["İ", "I"], ["i", "ı"], $text) : $text, "UTF-8");

        // PHP 8.2 lacks the Unicode Final_Sigma rule, which PHP 8.3 added to mb_strtolower().
        return preg_replace('/(?<=\p{L})σ(?!\p{L})/u', "ς", $lower);
    }


    public static function title(string $char, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($char)) {
            return strtoupper($char);
        }

        return mb_convert_case($turkic && $char === "i" ? "İ" : $char, MB_CASE_TITLE, "UTF-8");
    }
}
