<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use DOMElement;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\XmlLoader;

/**
 * Adapted from Subtitle Edit, src/libuilogic/Ocr/FixEngine/OcrFixReplaceList2.cs, MIT, at commit e1b8546:
 * https://github.com/SubtitleEdit/subtitleedit/blob/e1b854665b40bf6e04271c2ec64084947060e632/src/libuilogic/Ocr/FixEngine/OcrFixReplaceList2.cs
 */
final class OcrReplaceList
{
    private const SECTIONS = [
        "WholeWords"         => "wholeWords",
        "PartialWordsAlways" => "partialWordsAlways",
        "WholeLines"         => "wholeLines",
        "BeginLines"         => "beginLines",
        "EndLines"           => "endLines",
        "PartialLines"       => "partialLines",
        "PartialLinesAlways" => "partialLinesAlways",
    ];

    private const DELIMITERS = ["/", "~", "#", "%", "@", "!", "\x01"];


    /**
     * Creates a list of replacements, each an array of search text => replacement text.
     *
     * @param array<string, string> $wholeWords         a word between spaces, without the punctuation around it
     * @param array<string, string> $partialWordsAlways a part of any word
     * @param array<string, string> $wholeLines         the whole visible text of a line
     * @param array<string, string> $beginLines         the start of a line or of a sentence after ". ", "! " or "? "
     * @param array<string, string> $endLines           the end of the last line of a cue
     * @param array<string, string> $partialLines       a text that starts and ends at a word boundary
     * @param array<string, string> $partialLinesAlways any part of a line
     * @param array<string, string> $regularExpressions a PCRE pattern with delimiters => a preg_replace() replacement,
     *                                                  which may also hold the .NET substitutions $`, $', $+, $_ and ${name}
     */
    public function __construct(
        public readonly array $wholeWords = [],
        public readonly array $partialWordsAlways = [],
        public readonly array $wholeLines = [],
        public readonly array $beginLines = [],
        public readonly array $endLines = [],
        public readonly array $partialLines = [],
        public readonly array $partialLinesAlways = [],
        public readonly array $regularExpressions = [],
    ) {
        foreach (array_keys($regularExpressions) as $pattern) {
            if (@preg_match((string)$pattern, "") === false) {
                throw new InvalidArgumentException("The regular expression \"$pattern\" is not valid: " . preg_last_error_msg() . ".");
            }
        }
    }


    /**
     * Reads a Subtitle Edit OCR replace list such as eng_OCRFixReplaceList_User.xml.
     * It skips the sections that need a spell checker and the regular expressions that PCRE cannot run.
     * A replacement keeps $`, $', $+, $_ and ${name} of an existing group. ${name} without such a group is literal text.
     * Group numbers follow .NET, where the named groups come after the unnamed groups.
     */
    public static function fromSubtitleEditXml(string $xml): self
    {
        $document = XmlLoader::xml($xml, $error);
        if ($document === null) {
            throw new ParsingException("The OCR replace list is not valid XML" .
                                       ($error !== null ? ": " . trim($error->message) : "."),
                                       $error !== null && $error->line > 0 ? $error->line : null);
        }

        $lists = array_fill_keys(array_values(self::SECTIONS), []);
        $regex = [];
        foreach ($document->documentElement->childNodes as $section) {
            if (!$section instanceof DOMElement) {
                continue;
            }
            foreach ($section->childNodes as $entry) {
                if (!$entry instanceof DOMElement) {
                    continue;
                }
                self::readEntry($section->tagName, $entry, $lists, $regex);
            }
        }

        return new self(...$lists, regularExpressions: $regex);
    }


    /**
     * Adds one entry of a section to $lists, or to $regex for the RegularExpressions section.
     * The first entry for a search text wins.
     *
     * @param array<string, array<string, string>> $lists
     * @param array<string, string>                $regex
     */
    private static function readEntry(string $section, DOMElement $entry, array &$lists, array &$regex): void
    {
        if ($section === "RegularExpressions" && $entry->hasAttribute("replaceWith")) {
            $converted = self::convertRegex($entry->getAttribute("find"), $entry->getAttribute("replaceWith"));
            if ($converted !== null) {
                $regex[$converted[0]] ??= $converted[1];
            }
        } elseif (isset(self::SECTIONS[$section]) && $entry->hasAttribute("to")) {
            $from = $entry->getAttribute("from");
            $to   = $entry->getAttribute("to");
            if ($from !== "" && $from !== $to) {
                $lists[self::SECTIONS[$section]][$from] ??= $to;
            }
        }
    }


    /**
     * Turns a .NET pattern and replacement into a PCRE pattern with delimiters and a preg_replace() replacement.
     * It gives each numbered reference to a group the PCRE number of that group.
     *
     * @return array{string, string}|null null when PCRE rejects the pattern
     */
    private static function convertRegex(string $find, string $replaceWith): ?array
    {
        $delimiters = array_filter(self::DELIMITERS, fn (string $candidate): bool => !str_contains($find, $candidate));
        if ($find === "" || $delimiters === []) {
            return null;
        }
        $delimiter = reset($delimiters);
        $pattern   = $delimiter . $find . $delimiter . "u";
        // The empty first alternative matches every subject, so $groups gets every group of the pattern.
        if (@preg_match($pattern, "") === false
            || @preg_match($delimiter . "|" . $find . $delimiter . "u", "", $groups, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        $numbers = self::pcreGroupNumbers($groups);
        $pattern = $delimiter . self::renumberBackreferences($find, $numbers) . $delimiter . "u";

        $replacement = preg_replace_callback('/\\\\|\$(?:\$|&|\d+|\{\d+\}|\{\w*\}|[`\'+_])?/',
            function (array $match) use ($groups, $numbers): string {
                if ($match[0] === "\\") {
                    return "\\\\";
                }
                if ($match[0] === "$" || $match[0] === "$$") {
                    return "\\$";
                }
                if ($match[0] === "$&") {
                    return "\$0";
                }
                if ($match[0] === "$+") {
                    $last = count($numbers) - 1;

                    return $numbers[$last] !== $last ? "\${" . $numbers[$last] . "}" : $match[0];
                }
                if (preg_match('/^\$\{?(\d+)\}?$/', $match[0], $number) === 1) {
                    $pcre = $numbers[(int)$number[1]] ?? null;

                    return $pcre !== null && $pcre !== (int)$number[1] ? "\${" . $pcre . "}" : $match[0];
                }
                if (preg_match('/^\$\{(\w*)\}$/', $match[0], $name) === 1 && !array_key_exists($name[1], $groups)) {
                    return "\\" . $match[0];
                }

                return $match[0];
            },
            $replaceWith);

        return $replacement !== null ? [$pattern, $replacement] : null;
    }


    /**
     * Lists the PCRE group numbers in the order of the .NET group numbers, so the .NET number is the list index.
     * .NET numbers the unnamed groups from left to right and then the named groups. PCRE numbers all groups from
     * left to right. See "Grouping constructs in regular expressions" in the .NET documentation.
     *
     * @param array<int|string, string|null> $groups a match with every group, where a name key comes before its number key
     * @return list<int>
     */
    private static function pcreGroupNumbers(array $groups): array
    {
        $unnamed = [];
        $named   = [];
        $name    = null;
        foreach (array_keys($groups) as $key) {
            if (is_string($key)) {
                $name = $key;
            } elseif ($name !== null) {
                $named[] = $key;
                $name    = null;
            } else {
                $unnamed[] = $key;
            }
        }

        return [...$unnamed, ...$named];
    }


    /**
     * Gives the numbered backreferences \n and the conditions (?(n) in $find their PCRE numbers.
     * Other escapes, character classes and comments stay as they are.
     *
     * @param list<int> $numbers
     */
    private static function renumberBackreferences(string $find, array $numbers): string
    {
        return preg_replace_callback('/\\\\([1-9]\d*)|\\\\.|\[\^?\]?(?:\\\\.|[^\]\\\\])*\]|\(\?#[^)]*\)|\(\?\((\d+)\)/su',
            function (array $token) use ($numbers): string {
                $backreference = ($token[1] ?? "") !== "";
                $number        = (int)($backreference ? $token[1] : ($token[2] ?? 0));
                $pcre          = $numbers[$number] ?? $number;
                if ($pcre === $number) {
                    return $token[0];
                }

                return $backreference ? "\\g{" . $pcre . "}" : "(?(" . $pcre . ")";
            },
            $find) ?? $find;
    }
}
