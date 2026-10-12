<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class Markup
{
    /**
     * The core markup tags that style text. <v> names a speaker instead.
     *
     * @internal
     */
    public const STYLE_TAGS = ["b", "i", "u", "s", "font"];

    /**
     * Word timestamps such as <00:01:02.500> are core markup too, but no tag names. keepTags() removes them.
     *
     * @internal
     */
    public const CORE_TAGS = [...self::STYLE_TAGS, "v"];

    /**
     * Matches a tag such as <b> or </font>. A tag has no white space after its "<", so "< b>" is text.
     * A tag ends at the first ">", even after a lone quote as in <v O'Neil>.
     * strip_tags() would read that quote as the start of an attribute value.
     *
     * @internal
     */
    public const TAG = '<(?![ \t\n\r\f\v])[^<>]*>';

    /**
     * Matches a <v> tag with a name, such as <v.loud Anna>. Group 1 holds the classes and group 2 the name.
     *
     * @internal
     */
    public const VOICE_TAG = self::VOICE_TAG_START . '\s+([^>]*)>';

    /**
     * Matches the start of a <v> tag up to the name. Group 1 holds the classes, such as ".loud".
     *
     * @internal
     */
    public const VOICE_TAG_START = '<v(\.[^\s>]*)?';

    /**
     * Matches the body of a core word timestamp, such as 00:01:02.500.
     *
     * @internal
     */
    public const WORD_TIMESTAMP = '\d{2,}:[0-5]\d:[0-5]\d\.\d{3}';

    /**
     * Matches a core word timestamp such as <00:01:02.500> and captures it as group 1.
     *
     * @internal
     */
    public const WORD_TIMESTAMP_REGEX = '/(<' . self::WORD_TIMESTAMP . '>)/';

    /**
     * Matches an entity such as &amp;, &#233; or &#xE9;.
     *
     * @internal
     */
    public const ENTITY = '&(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);';

    private const TAG_REGEX = '/' . self::TAG . '/';


    /**
     * Returns the tag name of a tag body such as "font color=\"yellow\"", in lower case: "font".
     *
     * @internal
     */
    public static function tagName(string $style): string
    {
        return strtolower(preg_split("/\s+/", trim($style))[0]);
    }


    /**
     * Writes ruby as "base (annotation)" for formats without ruby: "<ruby>漢<rt>kan</rt></ruby>" becomes "漢 (kan)".
     * It drops <rp> elements, because the written parentheses take their place.
     *
     * @internal
     */
    public static function rubyAsText(string $text): string
    {
        if (stripos($text, "<ruby") === false) {
            return $text;
        }

        return preg_replace_callback('/<ruby\b[^>]*>(.*?)(?:<\/ruby\s*>|$)/is', function (array $ruby): string {
            $parts = preg_split('/<rt\b[^>]*>/i', preg_replace('/<rp\b[^>]*>.*?<\/rp\s*>/is', "", $ruby[1]));
            $text  = array_shift($parts);
            foreach ($parts as $part) {
                [$annotation, $base] = array_pad(preg_split('/<\/rt\s*>/i', $part, 2), 2, "");
                $text = trim($annotation) === "" ? $text . $base : rtrim($text) . " (" . trim($annotation) . ")" . $base;
            }

            return $text;
        }, $text) ?? $text;
    }


    public static function stripAllTags(string $text): string
    {
        return self::keepTags($text, []);
    }


    /**
     * Removes all tags except the given tag names, for example ["b", "i"].
     *
     * @param list<string> $tagNames
     */
    public static function keepTags(string $text, array $tagNames): string
    {
        $keep = array_map("strtolower", $tagNames);

        return preg_replace_callback(
            self::TAG_REGEX,
            fn (array $tag): string => preg_match('/^<\/?([^\s\/>]+)/', $tag[0], $name) === 1
                && in_array(strtolower($name[1]), $keep, true) ? $tag[0] : "",
            $text
        ) ?? $text;
    }


    /**
     * Decodes HTML entities such as &lt; and &amp; for formats without markup.
     */
    public static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8");
    }


    /**
     * Escapes &, < and > without htmlspecialchars(), which returns "" for invalid UTF-8 such as Latin-1 text.
     */
    public static function escapeText(string $text): string
    {
        return str_replace(["&", "<", ">"], ["&amp;", "&lt;", "&gt;"], $text);
    }


    /**
     * Returns a <v> tag for the speaker name with &, < and > escaped and quotes kept.
     * $class holds voice classes such as ".loud".
     */
    public static function voiceTag(string $name, string $class = ""): string
    {
        return "<v$class " . self::escapeText($name) . ">";
    }


    /**
     * Escapes $text and writes a core word timestamp before each word, searching from the end of the previous word.
     * A word that is empty, has no start or is not in the rest of the text gets no timestamp.
     *
     * @param list<array{string, ?float}> $words the text and the start in seconds of each word
     */
    public static function insertWordTimestamps(string $text, array $words): string
    {
        $markup   = "";
        $copied   = 0;
        $searchAt = 0;
        foreach ($words as [$word, $start]) {
            $position = $word === "" || $start === null ? false : strpos($text, $word, $searchAt);
            if ($position === false) {
                continue;
            }

            $markup  .= self::escapeText(substr($text, $copied, $position - $copied)) . "<" . self::coreTimestamp($start) . ">";
            $copied   = $position;
            $searchAt = $position + strlen($word);
        }

        return $markup . self::escapeText(substr($text, $copied));
    }


    /**
     * Decodes &lt;, &gt; and &amp;, the entities that escapeText() writes, and keeps all other entities.
     *
     * @internal
     */
    public static function unescapeText(string $text): string
    {
        return strtr($text, ["&lt;" => "<", "&gt;" => ">", "&amp;" => "&"]);
    }


    /**
     * Escapes a changed text run as escapeText() does.
     * It keeps & and > unescaped where the raw form $raw has them unescaped, as WebVTT text does.
     * An entity such as &nbsp; keeps the form of $raw when $raw holds it as an entity, also after a case change.
     * Otherwise its & gets escaped.
     *
     * @internal
     */
    public static function escapeTextLike(string $text, string $raw): string
    {
        $bare = str_contains(preg_replace('/' . self::ENTITY . '/', "", $raw), "&");
        $text = preg_replace_callback('/' . self::ENTITY . '|&/', fn (array $match): string => match (true) {
            $match[0] !== "&" => self::rawEntity($match[0], $raw) ?? "&amp;" . substr($match[0], 1),
            $bare             => "&",
            default           => "&amp;",
        }, $text);
        $text = str_replace("<", "&lt;", $text);

        return str_contains($raw, ">") ? $text : str_replace(">", "&gt;", $text);
    }


    /**
     * Returns the form in which $raw holds $entity as an entity, such as &nbsp; for &NBSP;, or null when $raw holds it
     * as escaped text such as &amp;nbsp;. Decoded text holds &lt;, &gt; and &amp; only as text.
     */
    private static function rawEntity(string $entity, string $raw): ?string
    {
        if (in_array(strtolower($entity), ["&lt;", "&gt;", "&amp;"], true) || stripos($raw, "&amp;" . substr($entity, 1)) !== false) {
            return null;
        }
        preg_match_all('/' . self::ENTITY . '/', $raw, $matches);
        $forms = array_values(array_unique(array_filter($matches[0], fn (string $form): bool => strcasecmp($form, $entity) === 0)));

        return in_array($entity, $forms, true) ? $entity : (count($forms) === 1 ? $forms[0] : null);
    }


    /**
     * Splits a line at its tags. The text runs are at the even indexes and the tags at the odd indexes.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function splitTags(string $line): array
    {
        return preg_split('/(' . self::TAG . ')/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    }


    /**
     * Calls $fn for each text run between tags, with &lt;, &gt; and &amp; decoded.
     * $first and $last mark the first and the last run of the line that holds text.
     *
     * @param list<string>                                            $lines
     * @param callable(string $text, bool $first, bool $last): string $fn
     * @return list<string>
     */
    public static function mapTextRuns(array $lines, callable $fn): array
    {
        foreach ($lines as $lineIndex => $line) {
            $tokens = self::splitTags($line);
            $runs   = array_keys(array_filter($tokens, fn (string $token, int $index): bool =>
                $index % 2 === 0 && $token !== "", ARRAY_FILTER_USE_BOTH));
            foreach ($runs as $position => $index) {
                $text   = self::unescapeText($tokens[$index]);
                $mapped = $fn($text, $position === 0, $position === count($runs) - 1);
                if ($mapped !== $text) {
                    $tokens[$index] = self::escapeTextLike($mapped, $tokens[$index]);
                }
            }
            $lines[$lineIndex] = implode("", $tokens);
        }

        return $lines;
    }


    /**
     * Writes the English pronoun "i" and its contractions such as "i'm" in upper case in the text runs of one line. "i.e." stays lower case.
     * Decides on the joined runs, so an "i" between tags inside a word stays lower case.
     * Text that is not valid UTF-8 makes the Unicode regex fail. An ASCII regex then decides when $asciiFallback is true.
     *
     * @param array<int, string> $runs
     * @return array<int, string>
     *
     * @internal
     */
    public static function upperCaseEnglishI(array $runs, bool $asciiFallback): array
    {
        $text  = implode("", $runs);
        $count = preg_match_all('/(?<![\p{L}\p{N}\'\x{2019}]|\p{L}\.)i(?=(?:[\'\x{2019}](?:m|ll|ve|d))?(?![\p{L}\p{N}\'\x{2019}]|\.\p{L}))/u',
                                $text, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false && $asciiFallback) {
            $count = preg_match_all('/(?<![A-Za-z0-9\']|[A-Za-z]\.)i(?=(?:\'(?:m|ll|ve|d))?(?![A-Za-z0-9\']|\.[A-Za-z]))/', $text, $matches, PREG_OFFSET_CAPTURE);
        }
        if (!$count) {
            return $runs;
        }

        $start = 0;
        foreach ($runs as $index => $run) {
            foreach (array_column($matches[0], 1) as $offset) {
                if ($offset >= $start && $offset < $start + strlen($run)) {
                    $runs[$index][$offset - $start] = "I";
                }
            }
            $start += strlen($run);
        }

        return $runs;
    }


    /**
     * Tells if one of the lines holds text other than white space outside its tags. An entity counts as text.
     *
     * @param array<string> $lines
     */
    public static function hasVisibleText(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim(preg_replace(self::TAG_REGEX, "", $line)) !== "") {
                return true;
            }
        }

        return false;
    }


    /**
     * Removes tags and decodes entities, for formats without markup.
     */
    public static function plainText(string $text): string
    {
        return self::decodeEntities(self::stripAllTags($text));
    }


    /**
     * Removes tags, decodes entities and trims the text, for example "<i>Hi</i> &amp; bye " becomes "Hi & bye".
     *
     * @internal
     */
    public static function visibleText(string $text): string
    {
        return trim(self::plainText($text));
    }


    /**
     * Returns the name of the first <v> tag, with entities decoded and spaces trimmed, or null when $text has no <v> tag.
     *
     * @internal
     */
    public static function speaker(string $text): ?string
    {
        return preg_match('/' . self::VOICE_TAG . '/', $text, $match) === 1 ? trim(self::decodeEntities($match[2])) : null;
    }


    /**
     * Puts the <v> tag of $speaker before the first line that holds more than white space. An empty $speaker adds no tag.
     *
     * @param list<string> $lines
     * @return list<string>
     *
     * @internal
     */
    public static function addSpeaker(array $lines, string $speaker): array
    {
        if ($speaker === "") {
            return $lines;
        }

        foreach ($lines as $index => $line) {
            if (trim($line) !== "") {
                $lines[$index] = self::voiceTag($speaker) . ltrim($line);
                break;
            }
        }

        return $lines;
    }


    /**
     * Removes style tag pairs without text, such as <i></i> or <b><i></i></b>, until none is left.
     * With $withSpaces, a pair that holds only white space goes too and leaves the white space.
     *
     * @internal
     */
    public static function removeEmptyTagPairs(string $text, bool $ignoreCase = false, bool $withSpaces = false): string
    {
        $pattern = '/<(' . implode("|", self::STYLE_TAGS) . ')(?=[\s.>])[^<>]*>(' . ($withSpaces ? '\s*' : '') . ')<\/\1>/'
            . ($ignoreCase ? "i" : "");
        do {
            $text = preg_replace($pattern, '$2', $text, -1, $count) ?? $text;
        } while ($count > 0);

        return $text;
    }


    /**
     * Returns a pattern that matches an opening or closing tag of $tagNames, such as <c.yellow> or </B>, in any case.
     * Group 1 holds "/" for a closing tag and group 2 the tag name as written.
     *
     * @param list<string> $tagNames lowercase tag names, for example ["b", "i"]
     *
     * @internal
     */
    public static function namedTagRegex(array $tagNames): string
    {
        return '/<(\/?)(' . implode("|", $tagNames) . ')(?=[\s.>])[^<>]*>/i';
    }


    /**
     * Finds the tags of $tagNames that do not pair up, case-insensitively.
     * A closing tag closes the last open tag of its name.
     *
     * @param list<string> $lines
     * @param list<string> $tagNames lowercase tag names, for example ["b", "i"]
     * @return array{stray: list<array{int, int, int}>, open: list<string>} "stray" holds the line index, offset and
     *         length of each closing tag without an open tag. "open" holds the tag names that are still open after
     *         the last line.
     *
     * @internal
     */
    public static function unbalancedTags(array $lines, array $tagNames): array
    {
        $pattern = self::namedTagRegex($tagNames);
        $open    = [];
        $stray   = [];
        foreach ($lines as $lineIndex => $line) {
            preg_match_all($pattern, $line, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($tags as $tag) {
                $name = strtolower($tag[2][0]);
                if ($tag[1][0] === "") {
                    $open[] = $name;
                    continue;
                }

                $match = array_search($name, array_reverse($open, true), true);
                if ($match === false) {
                    $stray[] = [$lineIndex, $tag[0][1], strlen($tag[0][0])];
                } else {
                    unset($open[$match]);
                }
            }
        }

        return ["stray" => $stray, "open" => array_values($open)];
    }


    /**
     * Removes tags, decodes entities, trims each line and drops the lines that end up empty, for formats without markup.
     *
     * @param list<string> $lines
     * @return list<string>
     *
     * @internal
     */
    public static function plainLines(array $lines): array
    {
        return array_values(array_filter(
            array_map(self::visibleText(...), $lines),
            fn (string $line): bool => $line !== ""
        ));
    }


    /**
     * Counts the characters of the text without tags and entities and without leading and trailing spaces.
     */
    public static function visibleLength(string $text): int
    {
        return self::countCharacters(self::visibleText($text));
    }


    /**
     * Counts UTF-8 characters, or bytes for invalid UTF-8 such as the Latin-1 bytes that MicroDVD keeps.
     *
     * @internal
     */
    public static function countCharacters(string $text): int
    {
        // mbstring is not part of a default PHP build, but PCRE is. preg_match_all() fails on invalid UTF-8.
        return preg_match_all('/./su', $text) ?: strlen($text);
    }


    /**
     * Splits text into UTF-8 characters, or into bytes when it is not valid UTF-8.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function characters(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($text);
    }


    /**
     * Splits text at white space into words. The text must hold no tags.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function words(string $text): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Invalid UTF-8, such as the Latin-1 bytes that MicroDVD keeps, makes the /u pattern fail.
        return $words === false ? preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) : $words;
    }


    /**
     * Joins the lines of text with a space, for formats that hold one line per cue or comment.
     *
     * @internal
     */
    public static function toSingleLine(string $text): string
    {
        return preg_replace('/\s*\n\s*/', " ", trim($text));
    }


    /**
     * Returns the core markup tags that are open after $text, given the tags in $openTags that are open before it.
     *
     * @param list<array{name: string, tag: string}> $openTags
     * @return list<array{name: string, tag: string}> the lowercase tag name and the opening tag as written
     *
     * @internal
     */
    public static function openCoreTags(string $text, array $openTags = []): array
    {
        preg_match_all(self::TAG_REGEX, $text, $tags);
        foreach ($tags[0] as $tag) {
            preg_match('/^<(\/?)([a-zA-Z]*)/', $tag, $match);
            [, $slash, $name] = $match;
            $name             = strtolower($name);
            if (!in_array($name, self::CORE_TAGS, true)) {
                continue;
            }

            if ($slash === "") {
                $openTags[] = ["name" => $name, "tag" => $tag];
                continue;
            }

            for ($index = count($openTags) - 1; $index >= 0; $index--) {
                if ($openTags[$index]["name"] === $name) {
                    array_splice($openTags, $index, 1);
                    break;
                }
            }
        }

        return $openTags;
    }


    /**
     * Returns the closing tags for the result of openCoreTags(), the last opened tag first.
     *
     * @param list<array{name: string, tag: string}> $openTags
     *
     * @internal
     */
    public static function closeCoreTags(array $openTags): string
    {
        $closing = "";
        foreach (array_reverse($openTags) as $openTag) {
            $closing .= "</{$openTag["name"]}>";
        }

        return $closing;
    }


    /**
     * Starts each line with the tags that the lines before it leave open, for writers that style each line on its own.
     * It first turns WebVTT color classes into <font> tags, so that a color can span lines too.
     *
     * @param list<string> $lines
     * @return list<string>
     *
     * @internal
     */
    public static function carryOpenTags(array $lines): array
    {
        $lines = explode("\n", MarkupColors::webVttColorsToFont(implode("\n", $lines)));
        $open  = [];
        foreach ($lines as $index => $line) {
            $lines[$index] = implode("", array_column($open, "tag")) . $line;
            $open          = self::openCoreTags($line, $open);
        }

        return $lines;
    }


    /**
     * Returns the seconds of a core word timestamp such as "<00:01:02.500>", or null for other text.
     */
    public static function wordTimestampSeconds(string $timestamp): ?float
    {
        if (preg_match('/^<' . self::WORD_TIMESTAMP . '>$/', $timestamp) !== 1) {
            return null;
        }

        [$hours, $minutes, $seconds, $fraction] = preg_split('/[:.]/', substr($timestamp, 1, -1));

        return Timecode::toSeconds((int) $hours, (int) $minutes, (int) $seconds, $fraction);
    }


    /**
     * Replaces each core word timestamp in $text with the timestamp of $map(seconds). A time below 0 becomes 0.
     *
     * @param callable(float): float $map
     */
    public static function mapWordTimestamps(string $text, callable $map): string
    {
        if (!str_contains($text, "<")) {
            return $text;
        }

        return preg_replace_callback(
            self::WORD_TIMESTAMP_REGEX,
            fn (array $match): string => "<" . self::coreTimestamp(max(0.0, $map(self::wordTimestampSeconds($match[1])))) . ">",
            $text
        ) ?? $text;
    }


    /**
     * Formats seconds as the body of a core word timestamp, for example 62.5 becomes "00:01:02.500".
     *
     * @internal
     */
    public static function coreTimestamp(float $seconds): string
    {
        return sprintf("%02d:%02d:%02d.%03d", ...Timecode::milliseconds($seconds));
    }
}
