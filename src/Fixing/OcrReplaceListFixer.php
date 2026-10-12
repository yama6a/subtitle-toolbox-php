<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use SubtitleToolbox\DialogueDash;
use SubtitleToolbox\Markup;

/**
 * Runs the replacements of an OcrReplaceList on the lines of a cue.
 * Ports GetReplaceWord(), StripAffixes(), ReplaceWord() and SkipAddLineEnding() of
 * OcrFixReplaceList2.cs of Subtitle Edit, MIT license, at commit e1b8546:
 * https://github.com/SubtitleEdit/subtitleedit/blob/e1b854665b40bf6e04271c2ec64084947060e632/src/libuilogic/Ocr/FixEngine/OcrFixReplaceList2.cs
 *
 * @internal
 */
final class OcrReplaceListFixer
{
    private const BOUNDARY = '/^[\p{P}\s<>`\x{00B4}\x{266A}]$/u';


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    public static function replaceList(array $lines, OcrReplaceList $list, bool $continues): array
    {
        $lastLine = count($lines) - 1;
        foreach ($lines as $lineIndex => $line) {
            $lines[$lineIndex] = Markup::mapTextRuns([$line], function (string $text, bool $first, bool $last)
                use ($list, $continues, $lineIndex, $lastLine): string {
                if ($first && $last && isset($list->wholeLines[$text])) {
                    return $list->wholeLines[$text];
                }

                $text = implode(" ", array_map(fn (string $word): string => self::replaceWord($word, $list), explode(" ", $text)));
                if ($first) {
                    $text = self::replaceBeginLines($text, $list->beginLines);
                }
                if ($last && $lineIndex === $lastLine) {
                    $text = self::replaceEndLines($text, $list->endLines, $continues);
                }

                return self::replacePartial($text, $list);
            })[0];
        }

        return $lines;
    }


    /**
     * Replaces the end of the last line. When the cue continues, a replacement does not add a full stop.
     *
     * @param array<string, string> $endLines
     */
    private static function replaceEndLines(string $text, array $endLines, bool $continues): string
    {
        foreach ($endLines as $from => $to) {
            $from = (string)$from;
            if (str_ends_with($text, $from) && !($continues && str_ends_with($to, ".") && !str_ends_with($from, "."))) {
                $text = substr($text, 0, -strlen($from)) . $to;
            }
        }

        return $text;
    }


    /**
     * Runs the partial line and the regular expression replacements of $list.
     */
    private static function replacePartial(string $text, OcrReplaceList $list): string
    {
        foreach ($list->partialLines as $from => $to) {
            $text = self::replaceBetweenBoundaries($text, (string)$from, $to);
        }
        foreach ($list->partialLinesAlways as $from => $to) {
            $text = str_replace((string)$from, $to, $text);
        }
        foreach ($list->regularExpressions as $pattern => $replacement) {
            $text = self::replace((string)$pattern, $replacement, $text);
        }

        return $text;
    }


    private static function replaceWord(string $word, OcrReplaceList $list): string
    {
        $result = self::lookUpWord($word, $list->wholeWords);
        if ($result !== null) {
            return $result;
        }

        foreach ($list->partialWordsAlways as $from => $to) {
            $word = str_replace((string)$from, $to, $word);
        }

        return self::lookUpWord($word, $list->wholeWords) ?? $word;
    }


    /**
     * Looks up the word with and without the punctuation around it, as GetReplaceWord() of Subtitle Edit does.
     *
     * @param array<string, string> $wholeWords
     */
    private static function lookUpWord(string $word, array $wholeWords): ?string
    {
        preg_match('/^((?:-(?=.))*(?:\.(?=.))*(?:"(?=.))*(?:\((?=.))?)(.*?)((?<=.)["\.,!?)\]:;]*)$/s', $word, $parts);
        [, $pre, $core, $post] = $parts + ["", "", $word, ""];
        if ($core === "") {
            return null;
        }

        return $wholeWords[$pre . $core . $post] ?? (isset($wholeWords[$pre . $core]) ? $wholeWords[$pre . $core] . $post : null)
            ?? (isset($wholeWords[$core . $post]) ? $pre . $wholeWords[$core . $post] : null)
            ?? (isset($wholeWords[$core]) ? $pre . $wholeWords[$core] . $post : null);
    }


    /**
     * @param array<string, string> $beginLines
     */
    private static function replaceBeginLines(string $text, array $beginLines): string
    {
        preg_match('/^[ "\'\[(\x{00B6}' . DialogueDash::CHARACTERS . ']*/u', $text, $prefix);
        $prefix = $prefix[0] ?? "";
        $rest   = substr($text, strlen($prefix));
        foreach ($beginLines as $from => $to) {
            $from = (string)$from;
            if (str_starts_with($rest, $from)) {
                $rest = $to . substr($rest, strlen($from));
            }
            $rest = str_replace([". $from", "! $from", "? $from"], [". $to", "! $to", "? $to"], $rest);
        }

        return $prefix . $rest;
    }


    /**
     * Replaces $from where it starts and ends at punctuation, whitespace or the text edge, as ReplaceWord() of Subtitle Edit does.
     */
    private static function replaceBetweenBoundaries(string $text, string $from, string $to): string
    {
        $result = "";
        $offset = 0;
        while ($from !== "" && ($position = strpos($text, $from, $offset)) !== false) {
            $end     = $position + strlen($from);
            $startOk = $position === 0 || str_starts_with($from, " ")
                       || preg_match(self::BOUNDARY, self::lastCharacter(substr($text, 0, $position))) === 1;
            $endOk   = $end === strlen($text) || str_ends_with($to, " ")
                       || preg_match(self::BOUNDARY, self::firstCharacter(substr($text, $end))) === 1;
            if ($startOk && $endOk) {
                $result .= substr($text, $offset, $position - $offset) . $to;
                $offset  = $end;
            } else {
                $result .= substr($text, $offset, $position - $offset + 1);
                $offset  = $position + 1;
            }
        }

        return $result . substr($text, $offset);
    }


    private static function lastCharacter(string $text): string
    {
        return preg_match('/.$/su', $text, $match) === 1 ? $match[0] : substr($text, -1);
    }


    private static function firstCharacter(string $text): string
    {
        return preg_match('/^./su', $text, $match) === 1 ? $match[0] : substr($text, 0, 1);
    }


    /**
     * Returns $text unchanged when it is not valid UTF-8.
     */
    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? $text;
    }
}
