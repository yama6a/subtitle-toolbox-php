<?php

declare(strict_types=1);

namespace SubtitleToolbox\HearingImpaired;

use SubtitleToolbox\CueList;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * The rules follow the "Remove text for hearing impaired" tool of Subtitle Edit:
 * https://github.com/SubtitleEdit/subtitleedit/blob/5b9ee8baf08c472c7a74fbc337446b656dedabb4/src/libse/Forms/RemoveTextForHI.cs
 */
final class HearingImpairedRemover
{
    private const MUSIC_SYMBOL = '(?:[\x{2669}-\x{266C}]|(?<!\S)#(?!\S))';
    private const UPPER_LABEL  = "(?=[^:\\n]*\\p{Lu})[\\p{Lu}\\p{N}][\\p{Lu}\\p{N}.'&-]*(?:\\h[\\p{Lu}\\p{N}.'&-]+){0,3}";
    private const ANY_LABEL    = "\\p{Lu}[\\p{L}\\p{N}.'&-]*(?:\\h[\\p{L}\\p{N}.'&-]+){0,3}";


    /**
     * Removes sound descriptions, speaker labels and music lines that the options select, and removes cues that become empty.
     */
    public static function apply(Subtitle $subtitle, ?HearingImpairedOptions $options = null): HearingImpairedReport
    {
        $options ??= new HearingImpairedOptions();
        $lineCounts  = new \SplObjectStorage();
        $removedCues = CueList::setLinesAndRemoveEmptied($subtitle, function (SubtitleCue $cue) use ($options, $lineCounts): array {
            $before = array_values($cue->getLines());
            $lines  = self::removeFromLines($before, $options);
            $lineCounts[$cue] = count($before);

            return $lines;
        });

        $removedLines = 0;
        foreach ($lineCounts as $cue) {
            $removedLines += isset($removedCues[$cue]) ? $lineCounts[$cue] : max(0, $lineCounts[$cue] - count($cue->getLines()));
        }

        return new HearingImpairedReport($removedLines, $removedCues->count());
    }


    /**
     * Returns the text of a cue with $text after apply() with $options. An emptied cue gives its remaining lines.
     *
     * @internal
     */
    public static function removeFromText(string $text, HearingImpairedOptions $options): string
    {
        $cue = new SubtitleCue(0, 1, $text);

        return $cue->setLines(self::removeFromLines(array_values($cue->getLines()), $options))->getText();
    }


    /**
     * Returns true when apply() with $options changes $line or removes it.
     */
    public static function isAnnotation(string $line, ?HearingImpairedOptions $options = null): bool
    {
        $options ??= new HearingImpairedOptions();
        $cue    = new SubtitleCue(0, 1, $line);
        $before = $cue->getLines();

        return $cue->setLines(self::removeFromLines($before, $options))->getLines() !== $before;
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function removeFromLines(array $lines, HearingImpairedOptions $options): array
    {
        $original = $lines;

        $brackets = $options->customBrackets;
        if ($options->squareBrackets) {
            $brackets[] = ["[", "]"];
        }
        if ($options->parentheses) {
            $brackets[] = ["(", ")"];
        }
        $bracketPatterns = array_map(fn (array $pair): string => self::bracketPattern($pair[0], $pair[1]), $brackets);
        do {
            $before = $lines;
            $lines  = self::removeMatches($lines, $bracketPatterns, true);
        } while ($lines !== $before);

        if ($options->speakerLabels) {
            $label = $options->speakerLabelsUpperCaseOnly ? self::UPPER_LABEL : self::ANY_LABEL;
            $lines = self::removeMatches($lines, ["/^\\h*(?:-\\h*)?\\K$label\\h*:(?:\\h+|$)/mu"], false);
        }

        $music    = self::MUSIC_SYMBOL;
        $patterns = [];
        if ($options->lyrics) {
            $patterns[] = "/$music(?:(?!$music).)*$music/su";
            $patterns[] = "/^\\h*(?:-\\h*)?$music.*$|^.*$music\\h*$/mu";
        }
        if ($options->musicOnlyLines) {
            $patterns[] = "/^\\h*(?:-\\h*)?(?:$music\\h*)+$/mu";
        }
        $lines = self::removeMatches($lines, $patterns, true);

        return self::removeEmptiedLines($original, $lines);
    }


    private static function bracketPattern(string $open, string $close): string
    {
        $open  = preg_quote($open, "/");
        $close = preg_quote($close, "/");

        // A backslash after the opening bracket marks an ASS override tag such as {\an8}, which is not an annotation.
        return "/$open(?!\\\\)(?:(?!$open|$close).)*$close:?/su";
    }


    /**
     * Removes the visible text that each PCRE pattern matches. The patterns see the lines joined with "\n", without tags.
     *
     * @param list<string> $lines
     * @param list<string> $patterns
     * @return list<string>
     */
    private static function removeMatches(array $lines, array $patterns, bool $widen): array
    {
        [$visible, $map] = self::visibleText($lines);

        $removed = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $visible, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$text, $start]) {
                $end = $start + strlen($text);
                if ($widen) {
                    [$start, $end] = self::widenRange($visible, $start, $end);
                }
                self::markRemoved($removed, $map, $start, $end);
            }
        }

        foreach ($removed as $lineIndex => $spans) {
            $lines[$lineIndex] = self::cutSpans($lines[$lineIndex], $spans);
        }

        return $lines;
    }


    /**
     * Marks the raw bytes of the visible range from $start to $end as removed, by line and offset with their length.
     *
     * @param array<int, array<int, int>>     $removed
     * @param list<array{int, int, int}|null> $map
     */
    private static function markRemoved(array &$removed, array $map, int $start, int $end): void
    {
        for ($offset = $start; $offset < $end; $offset++) {
            if ($map[$offset] !== null) {
                $removed[$map[$offset][0]][$map[$offset][1]] = $map[$offset][2];
            }
        }
    }


    /**
     * @param array<int, int> $spans the length of each removed span by its offset
     */
    private static function cutSpans(string $line, array $spans): string
    {
        $result = "";
        for ($offset = 0, $length = strlen($line); $offset < $length;) {
            if (isset($spans[$offset])) {
                $offset += $spans[$offset];
                continue;
            }
            $result .= $line[$offset++];
        }

        return $result;
    }


    /**
     * Returns the text between tags with &lt;, &gt; and &amp; decoded.
     * It also returns for each byte its line, offset and length in the raw line.
     *
     * @param list<string> $lines
     * @return array{string, list<array{int, int, int}|null>}
     */
    private static function visibleText(array $lines): array
    {
        $visible = "";
        $map     = [];
        foreach ($lines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $visible .= "\n";
                $map[]    = null;
            }

            preg_match_all('/' . Markup::TAG . '|&(?:lt|gt|amp);|[^<&]+|[<&]/', $line, $tokens, PREG_OFFSET_CAPTURE);
            foreach ($tokens[0] as [$token, $offset]) {
                if ($token[0] === "<" && strlen($token) > 1) {
                    continue;
                }
                if ($token[0] === "&" && strlen($token) > 1) {
                    $visible .= Markup::unescapeText($token);
                    $map[]    = [$lineIndex, $offset, strlen($token)];
                    continue;
                }
                $visible .= $token;
                for ($index = 0, $length = strlen($token); $index < $length; $index++) {
                    $map[] = [$lineIndex, $offset + $index, 1];
                }
            }
        }

        return [$visible, $map];
    }


    /**
     * Widens a removed range by the spaces around it, so "Wait (sighs) now" becomes "Wait now" and "Now (sighs)." becomes "Now.".
     *
     * @return array{int, int}
     */
    private static function widenRange(string $visible, int $start, int $end): array
    {
        $previous = $start > 0 ? $visible[$start - 1] : "\n";
        if (in_array($previous, ["\n", " ", "\t"], true)) {
            $end += strspn($visible, " \t", $end);
        }

        $next = $end < strlen($visible) ? $visible[$end] : "\n";
        if (in_array($next, ["\n", ".", ",", "!", "?", ";", ":"], true)) {
            while ($start > 0 && in_array($visible[$start - 1], [" ", "\t"], true)) {
                $start--;
            }
        }

        return [$start, $end];
    }


    /**
     * Removes lines that had text before and hold no text or only a dash now.
     * It also removes the dash of the last dialogue line left.
     *
     * @param list<string> $original
     * @param list<string> $lines
     * @return list<string>
     */
    private static function removeEmptiedLines(array $original, array $lines): array
    {
        $result     = [];
        $pending    = "";
        $dashLines  = 0;
        $anyRemoved = false;
        foreach ($lines as $index => $line) {
            $wasText = self::visibleLine($original[$index]);
            if (preg_match('/^\h*-/', $wasText) === 1) {
                $dashLines++;
            }
            if ($line === $original[$index]) {
                $result[] = $pending . $line;
                $pending  = "";
                continue;
            }

            // Any tag pair, such as <c.loud></c>, goes when the removed text was all it held.
            do {
                $before = $line;
                $line   = preg_replace('/<([a-zA-Z][a-zA-Z0-9]*)(?:[\s.][^<>]*)?>\h*<\/\1\s*>/', "", $line) ?? $line;
            } while ($line !== $before);

            if (trim($wasText) !== "" && in_array(trim(self::visibleLine($line)), ["", "-"], true)) {
                preg_match_all('/' . Markup::TAG . '/', $line, $tags);
                $pending   .= implode("", $tags[0]);
                $anyRemoved = true;
                continue;
            }

            $result[] = $pending . $line;
            $pending  = "";
        }
        if ($pending !== "" && $result !== []) {
            $result[count($result) - 1] .= $pending;
        }

        return $anyRemoved && $dashLines >= 2 ? self::removeLastDash($result) : $result;
    }


    /**
     * Removes the dash of the only dialogue line left, as one speaker needs no dash.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function removeLastDash(array $lines): array
    {
        $dashLines = array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^\h*-/', self::visibleLine($line)) === 1));
        if (count($dashLines) === 1) {
            $lines[$dashLines[0]] = self::removeMatches([$lines[$dashLines[0]]], ['/^\h*-\h*/'], false)[0];
        }

        return $lines;
    }


    private static function visibleLine(string $line): string
    {
        return self::visibleText([$line])[0];
    }
}
