<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

/**
 * Lays out help text in lines of at most WIDTH columns.
 *
 * @internal
 */
final class HelpLayout
{
    public const WIDTH = 80;

    // A word of a usage line that the wrap keeps whole: a [...] group, an --option VALUE pair, or a plain word.
    public const USAGE_WORD = '/\[[^\]]*\]|--\S+ [^\s\[<-]\S*|\S+/';


    /**
     * Returns the rows as text columns, each line indented by $indent spaces.
     * Every column but the last is padded to its widest cell, plus $gap spaces.
     * The last column wraps at WIDTH with a hanging indent.
     *
     * @param list<list<string>> $rows
     */
    public static function table(array $rows, int $indent = 2, int $gap = 2): string
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_slice($row, 0, -1) as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
            }
        }
        $text = "";
        foreach ($rows as $row) {
            $prefix = str_repeat(" ", $indent);
            foreach (array_slice($row, 0, -1) as $column => $cell) {
                $prefix .= str_pad($cell, $widths[$column] + $gap);
            }
            $text .= self::wrapWords(explode(" ", end($row)), $prefix, str_repeat(" ", strlen($prefix)));
        }

        return $text;
    }


    /**
     * Wraps each line of $text at WIDTH. A line that starts with a space is a command or an example and stays as it is.
     */
    public static function wrap(string $text): string
    {
        $wrapped = "";
        foreach (explode("\n", $text) as $line) {
            $wrapped .= $line === "" || $line[0] === " " ? rtrim($line) . "\n" : self::wrapWords(explode(" ", $line), "", "");
        }

        return $wrapped;
    }


    /**
     * Wraps the usage line $line after $prefix, such as "Usage: subtitle-toolbox convert".
     * The next lines start below the first word after $prefix.
     */
    public static function usage(string $prefix, string $line): string
    {
        preg_match_all(self::USAGE_WORD, $line, $words);

        return self::wrapWords([$prefix, ...$words[0]], "", str_repeat(" ", strlen($prefix) + 1));
    }


    /**
     * Joins the words into lines of at most WIDTH columns.
     * The first line starts with $prefix, the others with $nextPrefix. A word wider than a line gets a line of its own.
     *
     * @param list<string> $words
     */
    private static function wrapWords(array $words, string $prefix, string $nextPrefix): string
    {
        $lines = [];
        $line  = null;
        foreach (array_filter($words, fn (string $word): bool => $word !== "") as $word) {
            if ($line === null) {
                $line = $prefix . $word;
            } elseif (strlen("$line $word") > self::WIDTH) {
                $lines[] = $line;
                $line    = $nextPrefix . $word;
            } else {
                $line .= " $word";
            }
        }
        $lines[] = $line ?? $prefix;

        return implode("\n", array_map(rtrim(...), $lines)) . "\n";
    }
}
