<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\StyleRuns;

/**
 * The text descriptors of QuickTime text that the parser and the formatter share. A style descriptor such as
 * {italic} applies to the rest of the sample and to all later samples, until {plain} resets it.
 *
 * @see https://developer.apple.com/library/archive/documentation/QuickTime/Conceptual/QTScripting_HTML/QTScripting_HTML_Document/ScriptingHTML.html
 *
 * @internal
 */
final class QuickTimeText
{
    public const TIMESTAMP_REGEX = '/^\[(\d+):([0-5]\d):([0-5]\d)(?:\.(\d+))?\]/';

    public const DESCRIPTOR_REGEX = '/(\{[A-Za-z-]+(?::[^}]*)?\})/';

    public const PLAIN = ["b" => false, "i" => false, "u" => false];

    public const FLAGS = ["b" => "{bold}", "i" => "{italic}", "u" => "{underline}"];


    /**
     * Returns the value of the descriptor $name in $lines, such as "30" for {timeScale:30}, or null.
     *
     * @param list<string> $lines
     */
    public static function findDescriptor(array $lines, string $name): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/\{' . preg_quote($name, "/") . '\s*:\s*([^}]*?)\s*\}/i', $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }


    /**
     * Applies the style descriptors of $lines to $style, and returns the text of the lines as core markup.
     * Lines without text drop out. Other descriptors, such as {font:Tahoma}, give no markup.
     *
     * @param list<string>                   $lines
     * @param array{b: bool, i: bool, u: bool} $style
     *
     * @return list<string>
     */
    public static function readLines(array $lines, array &$style): array
    {
        $markup = [];
        foreach ($lines as $line) {
            $runs = [];
            foreach (preg_split(self::DESCRIPTOR_REGEX, $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $token) {
                if ($index % 2 === 0) {
                    if ($token !== "") {
                        $runs[] = [$token, $style];
                    }
                    continue;
                }
                $name = strtolower(trim($token, "{}"));
                if ($name === "plain") {
                    $style = self::PLAIN;
                } elseif (($flag = array_search("{" . $name . "}", self::FLAGS, true)) !== false) {
                    $style[$flag] = true;
                }
            }
            $text = trim(StyleRuns::toMarkup($runs, true));
            if ($text !== "") {
                $markup[] = $text;
            }
        }

        return $markup;
    }
}
