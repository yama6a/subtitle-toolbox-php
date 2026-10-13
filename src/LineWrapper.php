<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * @internal
 */
final class LineWrapper
{
    // Han, Hiragana, Katakana, CJK punctuation, and the full-width and half-width forms without half-width Hangul.
    private const CJK = '\p{Han}\p{Hiragana}\p{Katakana}\x{3000}-\x{303F}\x{30FB}\x{30FC}\x{FF01}-\x{FF9F}\x{FFE0}-\x{FFEF}';

    // The line start rule of JIS X 4051: closing brackets and quotes, stops, commas, middle dots, small kana, the long vowel mark
    // and iteration marks. UAX #14 adds the ideographic space.
    private const NO_LINE_START = '\x{3000}\x{3001}\x{3002}\x{3005}\x{3009}\x{300B}\x{300D}\x{300F}\x{3011}\x{3015}\x{3017}\x{3019}\x{301B}'
        . '\x{301C}\x{301E}\x{301F}\x{303B}\x{3041}\x{3043}\x{3045}\x{3047}\x{3049}\x{3063}\x{3083}\x{3085}\x{3087}\x{308E}'
        . '\x{3095}\x{3096}\x{309B}-\x{309E}\x{30A0}\x{30A1}\x{30A3}\x{30A5}\x{30A7}\x{30A9}\x{30C3}\x{30E3}\x{30E5}\x{30E7}'
        . '\x{30EE}\x{30F5}\x{30F6}\x{30FB}-\x{30FE}\x{31F0}-\x{31FF}\x{FF01}\x{FF09}\x{FF0C}\x{FF0E}\x{FF1A}\x{FF1B}\x{FF1F}'
        . '\x{FF3D}\x{FF5D}\x{FF60}\x{FF61}\x{FF63}-\x{FF65}\x{FF67}-\x{FF70}\x{FF9E}\x{FF9F}';

    // The line end rule of JIS X 4051: opening brackets and quotes.
    private const NO_LINE_END = '\x{3008}\x{300A}\x{300C}\x{300E}\x{3010}\x{3014}\x{3016}\x{3018}\x{301A}\x{301D}\x{FF08}\x{FF3B}\x{FF5B}\x{FF5F}\x{FF62}';


    /**
     * Wraps the lines into at most $maxLinesPerCue lines of at most $maxCharactersPerLine visible characters where the words allow it.
     * A line breaks at a space, or between 2 CJK characters where JIS X 4051 allows it.
     * With $keepDialogueLines, each line that starts with a dialogue dash stays a line of its own.
     * With 2 or more dialogue lines, no line wraps. A single dialogue line wraps like other text.
     * Each line gets its own right-to-left embedding when an input line holds the embedding of fixRightToLeft().
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function wrap(array $lines, int $maxCharactersPerLine, int $maxLinesPerCue, bool $keepDialogueLines = false): array
    {
        return self::keepRightToLeftEmbedding($lines, fn (array $lines): array => self::wrapPlain($lines, $maxCharactersPerLine, $maxLinesPerCue, $keepDialogueLines));
    }


    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function wrapPlain(array $lines, int $maxCharactersPerLine, int $maxLinesPerCue, bool $keepDialogueLines): array
    {
        $segments = self::segments($lines, $keepDialogueLines);
        if (count($segments) > 1) {
            return array_map(fn (string $segment): string => implode(" ", array_column(self::measuredWords($segment), "text")), $segments);
        }

        $parts = self::breakableParts($segments[0] ?? "");

        return self::joinLines($parts, self::findBreaks($parts, $maxCharactersPerLine, $maxLinesPerCue));
    }


    /**
     * Wraps each dialogue turn on lines of its own, with at most $maxLinesPerCue lines per turn.
     * A turn starts at a line with a dialogue dash, or at a dialogue dash after a sentence end within a line.
     * With fewer than 2 turns, it wraps as wrap() does.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function wrapTurns(array $lines, int $maxCharactersPerLine, int $maxLinesPerCue): array
    {
        return self::keepRightToLeftEmbedding($lines, fn (array $lines): array => self::wrapTurnsPlain($lines, $maxCharactersPerLine, $maxLinesPerCue));
    }


    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function wrapTurnsPlain(array $lines, int $maxCharactersPerLine, int $maxLinesPerCue): array
    {
        $turns = [];
        foreach ($lines as $line) {
            $lineWords = self::measuredWords($line);
            foreach ($lineWords as $index => $word) {
                $startsTurn = $index === 0
                    ? $turns === [] || preg_match(DialogueDash::REGEX, Markup::visibleText($line)) === 1
                    : self::startsInlineTurn($lineWords[$index - 1], $word, $lineWords[$index + 1] ?? null);
                if ($startsTurn) {
                    $turns[] = $word["text"];
                } else {
                    $turn                     = $turns[count($turns) - 1];
                    $turns[count($turns) - 1] = $index === 0 ? self::joinLine($turn, $word["text"]) : "$turn {$word["text"]}";
                }
            }
        }

        if (count($turns) < 2) {
            return self::wrapPlain($lines, $maxCharactersPerLine, $maxLinesPerCue, false);
        }

        $parts      = [];
        $lineStarts = [];
        foreach ($turns as $turn) {
            $turnParts = self::breakableParts($turn);
            foreach (self::findBreaks($turnParts, $maxCharactersPerLine, $maxLinesPerCue) as $break) {
                $lineStarts[] = count($parts) + $break;
            }
            $parts = [...$parts, ...$turnParts];
        }

        return self::joinLines($parts, $lineStarts);
    }


    /**
     * Joins the lines of each dialogue turn with a space, or without one between 2 CJK characters. A turn starts at the first line and at each line with a dialogue dash.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function unwrapTurns(array $lines): array
    {
        return self::keepRightToLeftEmbedding($lines, self::unwrapTurnsPlain(...));
    }


    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function unwrapTurnsPlain(array $lines): array
    {
        $turns = [];
        foreach ($lines as $line) {
            if ($turns !== [] && preg_match(DialogueDash::REGEX, Markup::visibleText($line)) !== 1) {
                $turns[count($turns) - 1] = self::joinLine($turns[count($turns) - 1], $line);
            } else {
                $turns[] = $line;
            }
        }

        return $turns;
    }


    /**
     * Returns wrap() with $keepDialogueLines, or null when the result breaks a limit.
     *
     * @param list<string> $lines
     *
     * @return ?list<string>
     */
    public static function wrapToFit(array $lines, int $maxCharactersPerLine, int $maxLinesPerCue): ?array
    {
        $wrapped = self::wrap($lines, $maxCharactersPerLine, $maxLinesPerCue, true);

        return self::fits($wrapped, $maxCharactersPerLine, $maxLinesPerCue) ? $wrapped : null;
    }


    /**
     * Returns count(wrap($lines, $maxCharactersPerLine, PHP_INT_MAX)) in linear time.
     * The fewest lines that fit come from filling each line with as many parts as fit.
     * When a part is longer than a line, no lines fit, and wrap() puts each part on a line of its own.
     *
     * @param list<string> $lines
     */
    public static function fewestLines(array $lines, int $maxCharactersPerLine): int
    {
        $parts     = self::breakableParts(self::segments($lines, false)[0] ?? "");
        $lineCount = 0;
        $length    = 0;
        foreach ($parts as $part) {
            if ($part["length"] > $maxCharactersPerLine) {
                return count($parts);
            }

            $joined = $length + (int) $part["spaceBefore"] + $part["length"];
            if ($lineCount > 0 && $joined <= $maxCharactersPerLine) {
                $length = $joined;
            } else {
                $lineCount++;
                $length = $part["length"];
            }
        }

        return $lineCount;
    }


    /**
     * Returns true when the visible characters of the lines per second over $duration stay at or below $maxCharactersPerSecond.
     * Also returns true when $maxCharactersPerSecond is null or the lines have no visible character.
     *
     * @param array<string> $lines
     */
    public static function fitsCharactersPerSecond(array $lines, float $duration, ?float $maxCharactersPerSecond): bool
    {
        if ($maxCharactersPerSecond === null) {
            return true;
        }
        $characters = self::visibleCharacters($lines);

        return $characters === 0 || self::charactersPerSecond($characters, $duration) <= $maxCharactersPerSecond;
    }


    /**
     * @param array<string> $lines
     */
    public static function fits(array $lines, int $maxCharactersPerLine, int $maxLinesPerCue): bool
    {
        if (count($lines) > $maxLinesPerCue) {
            return false;
        }
        foreach ($lines as $line) {
            if (self::length(self::measuredWords($line)) > $maxCharactersPerLine) {
                return false;
            }
        }

        return true;
    }


    /**
     * Returns the visible characters of the lines. Tags count 0 characters.
     *
     * @param array<string> $lines
     */
    public static function visibleCharacters(array $lines): int
    {
        return array_sum(array_map(fn (string $line): int => Markup::visibleLength($line), $lines));
    }


    /**
     * Returns the visible characters of each line that has at least 1 visible character. Tags count 0 characters.
     *
     * @param array<string> $lines
     *
     * @return list<int>
     */
    public static function visibleLineLengths(array $lines): array
    {
        return array_values(array_filter(array_map(fn (string $line): int => Markup::visibleLength($line), $lines)));
    }


    /**
     * Returns $characters divided by $duration, or INF when $duration is 0 or less.
     */
    public static function charactersPerSecond(int $characters, float $duration): float
    {
        return $duration > 0 ? $characters / $duration : INF;
    }


    /**
     * Splits at spaces outside tags. Tags count 0 characters, and an entity such as &amp; counts 1.
     *
     * @return list<array{text: string, length: int}>
     */
    public static function measuredWords(string $text): array
    {
        $tag    = Markup::TAG;
        $entity = Markup::ENTITY;
        $tokens = preg_split("/($tag|$entity| )/", $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $words = [];
        $word  = ["text" => "", "length" => 0];
        foreach ($tokens as $token) {
            if ($token === " ") {
                if ($word["text"] !== "") {
                    $words[] = $word;
                }
                $word = ["text" => "", "length" => 0];
                continue;
            }

            $word["text"]   .= $token;
            $word["length"] += match (true) {
                preg_match("/^$tag\$/", $token) === 1    => 0,
                preg_match("/^$entity\$/", $token) === 1 => 1,
                default                                   => Markup::countCharacters($token),
            };
        }
        if ($word["text"] !== "") {
            $words[] = $word;
        }

        return $words;
    }


    /**
     * Returns the length of the words joined with one space.
     *
     * @param list<array{text: string, length: int}> $words
     */
    public static function length(array $words): int
    {
        return array_sum(array_column($words, "length")) + max(0, count($words) - 1);
    }


    /**
     * Calls $wrap without the embedding marks of fixRightToLeft() when a line holds them, and gives each new line its own embedding.
     * A line break ends every embedding in the Unicode bidi algorithm.
     *
     * @param list<string>                                $lines
     * @param callable(list<string> $lines): list<string> $wrap
     *
     * @return list<string>
     */
    private static function keepRightToLeftEmbedding(array $lines, callable $wrap): array
    {
        if (!Markup::holdsRightToLeftEmbedding($lines)) {
            return $wrap($lines);
        }

        return array_map(Markup::embedRightToLeft(...), $wrap(str_replace(["\u{202B}", "\u{202C}"], "", $lines)));
    }


    /**
     * @param array{text: string, length: int}  $previous
     * @param array{text: string, length: int}  $word
     * @param ?array{text: string, length: int} $next
     */
    private static function startsInlineTurn(array $previous, array $word, ?array $next): bool
    {
        return preg_match('/[.?!\x{2026}]$/u', Markup::visibleText($previous["text"])) === 1
            && preg_match(DialogueDash::REGEX, Markup::visibleText($word["text"] . " " . ($next["text"] ?? ""))) === 1;
    }


    /**
     * Uses the fewest lines up to $maxLinesPerCue that fit, then the fewest breaks inside a phrase,
     * and among those the breaks with the most equal line lengths.
     *
     * @param list<array{text: string, length: int, spaceBefore: bool, midPhrase: bool}> $parts
     *
     * @return list<int> the index of the first part of each line
     */
    private static function findBreaks(array $parts, int $maxCharactersPerLine, int $maxLinesPerCue): array
    {
        $partCount = count($parts);
        if ($partCount === 0) {
            return [];
        }

        // On one long line, part $i starts at column $starts[$i], and part $i - 1 ends at column $ends[$i].
        $starts = [0];
        $ends   = [0];
        foreach ($parts as $index => $part) {
            $ends[$index + 1]   = $starts[$index] + $part["length"];
            $starts[$index + 1] = $ends[$index + 1] + (int) ($parts[$index + 1]["spaceBefore"] ?? false);
        }

        // $best[$lineCount][$end] holds [overflow, breaks inside a phrase, sum of squared lengths, line starts] for parts 0 to $end - 1.
        $best = [0 => [0 => [0, 0, 0, []]]];
        for ($lineCount = 1; $lineCount <= min($maxLinesPerCue, $partCount); $lineCount++) {
            for ($end = $lineCount; $end <= $partCount; $end++) {
                $ending = self::bestLastLine($best[$lineCount - 1], $parts, $starts, $ends[$end], $lineCount - 1, $end, $maxCharactersPerLine);
                if ($ending !== null) {
                    $best[$lineCount][$end] = $ending;
                }
            }

            if ($best[$lineCount][$partCount][0] === 0) {
                return $best[$lineCount][$partCount][3];
            }
        }

        return $best[min($maxLinesPerCue, $partCount)][$partCount][3];
    }


    /**
     * Returns the best breaks for the parts before $end when the last line starts at $firstStart or later.
     * Returns null when no earlier breaks reach such a start. The first start wins a tie.
     *
     * @param array<int, array{int, int, int, list<int>}>                                  $previous the best breaks with one line fewer, by their end
     * @param list<array{text: string, length: int, spaceBefore: bool, midPhrase: bool}> $parts
     * @param list<int>                                                                   $starts   the column where each part starts on one long line
     * @return array{int, int, int, list<int>}|null
     */
    private static function bestLastLine(array $previous, array $parts, array $starts, int $endColumn, int $firstStart, int $end, int $maxCharactersPerLine): ?array
    {
        $best = null;
        for ($start = $firstStart; $start < $end; $start++) {
            if (!isset($previous[$start])) {
                continue;
            }

            $length    = $endColumn - $starts[$start];
            $overflow  = $previous[$start][0] + max(0, $length - $maxCharactersPerLine);
            $midPhrase = $previous[$start][1] + (int) $parts[$start]["midPhrase"];
            $squares   = $previous[$start][2] + $length ** 2;
            if ($best === null || $overflow < $best[0]
                || ($overflow === $best[0] && ($midPhrase < $best[1] || ($midPhrase === $best[1] && $squares < $best[2])))) {
                $best = [$overflow, $midPhrase, $squares, $start];
            }
        }

        return $best === null ? null : [$best[0], $best[1], $best[2], [...$previous[$best[3]][3], $best[3]]];
    }


    /**
     * Closes the core markup tags that are open at the end of a line and opens them again on the next line.
     *
     * @param list<array{text: string, length: int, spaceBefore: bool, midPhrase: bool}> $parts
     * @param list<int>                                                                  $lineStarts
     *
     * @return list<string>
     */
    private static function joinLines(array $parts, array $lineStarts): array
    {
        $lines    = [];
        $openTags = [];
        foreach ($lineStarts as $lineIndex => $start) {
            $end  = $lineStarts[$lineIndex + 1] ?? count($parts);
            $line = implode("", array_column($openTags, "tag"));
            foreach (array_slice($parts, $start, $end - $start) as $index => $part) {
                $line .= ($index > 0 && $part["spaceBefore"] ? " " : "") . $part["text"];
            }

            $openTags = Markup::openCoreTags($line);
            if (isset($lineStarts[$lineIndex + 1])) {
                $line .= Markup::closeCoreTags($openTags);
            }
            $lines[] = $line;
        }

        return $lines;
    }


    /**
     * Splits the text at spaces and between 2 CJK characters where a line may break.
     * A break between 2 CJK characters is inside a phrase unless CJK punctuation or an ideographic space comes before it.
     *
     * @return list<array{text: string, length: int, spaceBefore: bool, midPhrase: bool}>
     */
    private static function breakableParts(string $text): array
    {
        $hasCjk = preg_match('/[' . self::CJK . ']/u', $text) !== 0;
        $parts  = [];
        foreach (self::measuredWords($text) as $word) {
            $pieces = $hasCjk ? self::splitBetweenCjkCharacters($word["text"]) : [$word["text"]];
            foreach ($pieces as $index => $piece) {
                $parts[] = [
                    "text"        => $piece,
                    "length"      => count($pieces) === 1 ? $word["length"] : self::length(self::measuredWords($piece)),
                    "spaceBefore" => $index === 0,
                    "midPhrase"   => $index > 0 && preg_match('/[\p{P}\x{3000}]$/u', Markup::visibleText($pieces[$index - 1])) !== 1,
                ];
            }
        }

        return $parts;
    }


    /**
     * Splits a word without spaces where a line may break between 2 CJK characters.
     * A closing tag stays with the character before the break. Other tags go with the character after it.
     *
     * @return list<string>
     */
    private static function splitBetweenCjkCharacters(string $word): array
    {
        $cjk = '[' . self::CJK . ']';
        if (preg_match("/$cjk/u", $word) !== 1
            || preg_match_all('/' . Markup::TAG . '|' . Markup::ENTITY . '|\X/u', $word, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [$word];
        }

        $pieces     = [];
        $pieceStart = 0;
        $breakAt    = 0;
        $canEndLine = false;
        foreach ($matches[0] as [$token, $offset]) {
            if (preg_match('/^' . Markup::TAG . '$/', $token) === 1) {
                if ($breakAt === $offset && str_starts_with($token, "</")) {
                    $breakAt += strlen($token);
                }
                continue;
            }

            if ($canEndLine && preg_match('/^(?![' . self::NO_LINE_START . "])$cjk/u", $token) === 1) {
                $pieces[]   = substr($word, $pieceStart, $breakAt - $pieceStart);
                $pieceStart = $breakAt;
            }
            $canEndLine = preg_match('/^(?![' . self::NO_LINE_END . "])$cjk/u", $token) === 1;
            $breakAt    = $offset + strlen($token);
        }
        $pieces[] = substr($word, $pieceStart);

        return $pieces;
    }


    /**
     * Joins the lines that have words into one segment. With $keepDialogueLines, each line that starts with a dialogue dash starts a new segment.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function segments(array $lines, bool $keepDialogueLines): array
    {
        $segments = [];
        foreach ($lines as $line) {
            if (trim($line, " ") === "") {
                continue;
            }

            $startsWithDash = preg_match('/^(?:\s|' . Markup::TAG . ')*[' . DialogueDash::CHARACTERS . ']/u', $line) === 1;
            if ($segments === [] || ($keepDialogueLines && $startsWithDash)) {
                $segments[] = $line;
            } else {
                $segments[count($segments) - 1] = self::joinLine($segments[count($segments) - 1], $line);
            }
        }

        return $segments;
    }


    /**
     * Joins 2 lines with a space, or without one when a CJK character ends the first line and starts the second.
     */
    private static function joinLine(string $text, string $line): string
    {
        $cjk               = '[' . self::CJK . ']';
        $joinsWithoutSpace = preg_match("/$cjk\$/u", Markup::visibleText($text)) === 1 && preg_match("/^$cjk/u", Markup::visibleText($line)) === 1;

        return $text . ($joinsWithoutSpace ? "" : " ") . $line;
    }
}
