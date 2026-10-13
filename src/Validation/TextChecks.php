<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

use SubtitleToolbox\DialogueDash;
use SubtitleToolbox\Markup;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * @internal
 */
final class TextChecks
{
    /**
     * Returns one violation per text rule that the cue breaks.
     *
     * @return list<ValidationViolation>
     */
    public static function check(int $cueIndex, SubtitleCue $cue, float $duration, ValidationRules $rules): array
    {
        $lines   = array_map(fn (string $line): string => Markup::plainText($line), $cue->getLines());
        $visible = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ""));
        $words   = count(Markup::words(implode("\n", $lines)));

        $violations = [];
        foreach (self::rules($cue, $visible, $words, $duration, $rules) as [$rule, $enabled, $check]) {
            [$value, $limit] = $enabled ? $check() : [0, null];
            // A count rule gives the int 0 for a cue without problems. A limit rule gives it for a cue within its limit.
            if ($value !== 0) {
                $violations[] = new ValidationViolation($cueIndex, $rule, $value, $limit);
            }
        }

        return $violations;
    }


    /**
     * Returns each text rule in the order of the violations, with a flag that tells if it is on.
     * The check of each rule returns its value and its limit.
     *
     * @param list<string> $visible
     * @return list<array{ValidationRule, bool, callable(): array{int|float, int|float|null}}>
     */
    private static function rules(SubtitleCue $cue, array $visible, int $words, float $duration, ValidationRules $rules): array
    {
        return [
            [ValidationRule::NoDoubleSpaces, $rules->noDoubleSpaces, fn (): array => [self::sumPerLine(
                $visible, fn (string $line): int => self::count('/(?<=\S)\h{2,}(?=\S)/u', '/(?<=\S)[ \t]{2,}(?=\S)/', $line)
            ), null]],
            [ValidationRule::NoLeadingOrTrailingSpaces, $rules->noLeadingOrTrailingSpaces, fn (): array => [self::countLines(
                $visible, fn (string $line): bool => self::count('/^\h|\h$/u', '/^[ \t]|[ \t]$/', $line) > 0
            ), null]],
            [ValidationRule::NoUnbalancedTags, $rules->noUnbalancedTags,
             fn (): array => [self::unbalancedTags(implode("\n", $cue->getLines())), null]],
            [ValidationRule::DialogueDashStyle, $rules->dialogueDashStyle !== null,
             fn (): array => [self::wrongDialogueDashes($visible, (string)$rules->dialogueDashStyle?->value), null]],
            [ValidationRule::MaxSpeakersPerCue, $rules->maxSpeakersPerCue !== null,
             fn (): array => self::overLimit(self::speakers($cue->getLines(), $visible), (int)$rules->maxSpeakersPerCue)],
            [ValidationRule::MaxWordsPerMinute, $rules->maxWordsPerMinute !== null && $words > 0,
             fn (): array => self::overLimit($duration > 0 ? $words / $duration * 60 : INF, (float)$rules->maxWordsPerMinute)],
            // Cue times have millisecond precision, so compare the duration with the needed time in milliseconds.
            [ValidationRule::MinSecondsPerWord,
             $rules->minSecondsPerWord !== null && $words > 0 && $duration < Timecode::roundToMilliseconds($rules->minSecondsPerWord * $words),
             fn (): array => [$duration / $words, $rules->minSecondsPerWord]],
            [ValidationRule::AllowedCharacters, $rules->allowedCharacters !== null, fn (): array => [self::sumPerLine(
                $visible, fn (string $line): int => self::disallowedCharacters($line, (string)$rules->allowedCharacters)
            ), null]],
            [ValidationRule::NoAllCapsLines, $rules->noAllCapsLines, fn (): array => [self::countLines($visible, self::isAllCaps(...)), null]],
        ];
    }


    /**
     * @return array{int|float, int|float|null}
     */
    private static function overLimit(int|float $value, int|float $limit): array
    {
        return $value > $limit ? [$value, $limit] : [0, null];
    }


    /**
     * @param list<string>          $lines
     * @param callable(string): int $count
     */
    private static function sumPerLine(array $lines, callable $count): int
    {
        return array_sum(array_map($count, $lines));
    }


    /**
     * @param list<string>           $lines
     * @param callable(string): bool $matches
     */
    private static function countLines(array $lines, callable $matches): int
    {
        return count(array_filter($lines, $matches));
    }


    /**
     * @param list<string> $visible
     */
    private static function wrongDialogueDashes(array $visible, string $dash): int
    {
        $style = '/^' . preg_quote($dash, "/") . '(?=\S)/u';

        return self::countLines(
            $visible,
            fn (string $line): bool => self::startsWithDialogueDash($line) && preg_match($style, self::lineStart($line)) !== 1
        );
    }


    /**
     * Tells if $characters is a regular expression character class such as "[A-Za-z0-9 .,!?]".
     */
    public static function isCharacterClass(string $characters): bool
    {
        return strlen($characters) >= 2 && $characters[0] === "[" && str_ends_with($characters, "]");
    }


    /**
     * Returns the pattern without modifiers that matches one character of the class $characters.
     */
    public static function characterClassPattern(string $characters): string
    {
        $escaped = preg_replace_callback(
            '/\\\\.|\//s',
            fn (array $match): string => $match[0] === "/" ? "\\/" : $match[0],
            $characters
        );

        return '/^(?:' . $escaped . ')$/';
    }


    private static function startsWithDialogueDash(string $line): bool
    {
        return preg_match(DialogueDash::REGEX, self::lineStart($line)) === 1;
    }


    /**
     * Returns the line without the spaces and bidi controls before its first character.
     */
    private static function lineStart(string $line): string
    {
        return ltrim(DialogueDash::splitLeadingBidiControls(ltrim($line))[1]);
    }


    /**
     * @param list<string> $markupLines
     * @param list<string> $visible
     */
    private static function speakers(array $markupLines, array $visible): int
    {
        $dashLines = count(array_filter($visible, fn (string $line): bool => self::startsWithDialogueDash($line)));

        preg_match_all('/' . Markup::VOICE_TAG . '/', implode("\n", $markupLines), $matches);
        $names = array_unique(array_map("trim", $matches[2]));

        return max($dashLines, count($names), $visible === [] ? 0 : 1);
    }


    /**
     * Counts closing tags without an opening tag, opening tags without a closing tag and <rt> tags outside <ruby>.
     * An open <v> needs no </v>. </ruby> and the next <rt> close an open <rt>, as WebVTT allows.
     */
    private static function unbalancedTags(string $text): int
    {
        $tagNames = [...Markup::CORE_TAGS, "c", "lang", "ruby", "rt"];
        preg_match_all(Markup::namedTagRegex($tagNames), $text, $tags, PREG_SET_ORDER);

        $open  = [];
        $count = 0;
        foreach ($tags as [, $slash, $name]) {
            $name = strtolower($name);
            if ($slash === "") {
                $count += $name === "rt" ? self::openRt($open) : 0;
                $open[] = $name;
                continue;
            }

            $match = array_search($name, array_reverse($open, true), true);
            if ($match === false) {
                $count++;
                continue;
            }

            $inner = array_slice($open, $match + 1);
            if ($name === "ruby" && ($inner[0] ?? null) === "rt") {
                array_shift($inner);
            }
            $count += self::unclosedTags($inner);
            $open   = array_slice($open, 0, $match);
        }

        return $count + self::unclosedTags($open);
    }


    /**
     * Closes an open <rt> before the next <rt> opens. Returns 1 when the new <rt> is outside <ruby>, else 0.
     *
     * @param list<string> $open
     */
    private static function openRt(array &$open): int
    {
        if (end($open) === "rt") {
            array_pop($open);
        }

        return in_array("ruby", $open, true) ? 0 : 1;
    }


    /**
     * @param list<string> $tagNames
     */
    private static function unclosedTags(array $tagNames): int
    {
        return count(array_filter($tagNames, fn (string $tag): bool => $tag !== "v"));
    }


    private static function disallowedCharacters(string $line, string $allowed): int
    {
        $isUtf8     = preg_match('//u', $line) === 1;
        $characters = Markup::characters($line);

        if (self::isCharacterClass($allowed)) {
            $pattern = self::characterClassPattern($allowed) . ($isUtf8 ? "u" : "");

            return count(array_filter($characters, fn (string $character): bool =>
                $character !== " " && preg_match($pattern, $character) !== 1));
        }

        $set = array_flip(Markup::characters($allowed));

        return count(array_filter($characters, fn (string $character): bool =>
            $character !== " " && !isset($set[$character])));
    }


    /**
     * Tells if a line has two or more upper case letters and no lower case letter. Text in brackets does not count.
     */
    private static function isAllCaps(string $line): bool
    {
        $line = preg_replace('/\[[^\]]*\]|\([^)]*\)/', "", $line);

        return self::count('/\p{Lu}/u', '/[A-Z]/', $line) >= 2 && self::count('/\p{Ll}/u', '/[a-z]/', $line) === 0;
    }


    /**
     * Counts the matches of $utf8Pattern, or of $bytePattern for invalid UTF-8 such as Latin-1 bytes.
     */
    private static function count(string $utf8Pattern, string $bytePattern, string $text): int
    {
        $count = preg_match_all($utf8Pattern, $text);

        return $count === false ? (int)preg_match_all($bytePattern, $text) : $count;
    }
}
