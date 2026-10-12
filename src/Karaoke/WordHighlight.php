<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\CueList;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class WordHighlight
{
    /**
     * Replaces each cue with one cue per timed word, in which the style marks the active word.
     */
    public static function apply(Subtitle $subtitle, ?WordHighlightOptions $options = null): WordHighlightReport
    {
        $options ??= new WordHighlightOptions();
        $anchors  = CommentAnchors::of($subtitle->getCues(), $subtitle->getComments());
        $firstNew = new \SplObjectStorage();
        $groups   = [];
        foreach ($subtitle->getCues() as $cue) {
            $group          = self::expandCue($cue, $options);
            $groups[]       = $group;
            $firstNew[$cue] = $group[0];
        }

        $cues = CueList::inStartOrder(array_merge([], ...$groups));

        $cuesBefore = count($subtitle->getCues());
        $subtitle->replaceCues($cues, CommentAnchors::remap($anchors, $firstNew));

        return new WordHighlightReport($cuesBefore, count($cues));
    }


    /**
     * @return list<SubtitleCue>
     */
    private static function expandCue(SubtitleCue $cue, WordHighlightOptions $options): array
    {
        [$lines, $times] = self::splitWords($cue);
        if ($times === []) {
            return [clone $cue];
        }

        $start  = $cue->getStart();
        $end    = max($start, $cue->getEnd());
        $bounds = [$start];
        foreach ($times as $time) {
            $bounds[] = max(end($bounds), min($end, $time));
        }
        $bounds[] = $end;

        $cues = [];
        // Word -1 is the time from the cue start to the first word timestamp. Its cue styles no word.
        for ($word = -1; $word < count($times); $word++) {
            $wordStart = $bounds[$word + 1];
            $wordEnd   = $bounds[$word + 2];
            if (Timecode::roundToMilliseconds($wordEnd - $wordStart) <= 0) {
                continue;
            }

            $cues[] = (clone $cue)
                ->setStart($wordStart)
                ->setEnd($wordEnd)
                ->setLines(self::highlight($lines, $word, count($times), $options))
                ->setIdentifier($cues === [] ? $cue->findIdentifier() : null);
        }

        if ($cues === []) {
            return [(clone $cue)->setLines(self::highlight($lines, -1, count($times), $options))];
        }

        return $cues;
    }


    /**
     * Splits the cue lines into tags and text runs. Each text run carries the number of its word, -1 before the first.
     *
     * @return array{list<list<array{string, string, int}>>, list<float>} the lines of ["tag" or "text", value, word], and the word times
     */
    private static function splitWords(SubtitleCue $cue): array
    {
        $lines = [];
        $times = [];
        foreach ($cue->getLines() as $line) {
            $items  = [];
            foreach (Markup::splitTags($line) as $index => $token) {
                $isTag = $index % 2 === 1;
                $time  = $isTag ? Markup::wordTimestampSeconds($token) : null;
                if ($time !== null) {
                    $times[] = $time;
                } elseif ($isTag) {
                    $items[] = ["tag", $token, count($times) - 1];
                } elseif ($token !== "") {
                    $items[] = ["text", $token, count($times) - 1];
                }
            }
            $lines[] = $items;
        }

        return [$lines, $times];
    }


    /**
     * Writes the lines with the style around the active words and without the words outside the window.
     *
     * @param list<list<array{string, string, int}>> $lines
     *
     * @return list<string>
     */
    private static function highlight(array $lines, int $active, int $wordCount, WordHighlightOptions $options): array
    {
        [$first, $last] = self::window($active, $wordCount, $options->maxWordsPerCue);
        $openTag        = "<" . trim($options->style) . ">";
        $closeTag       = "</" . $options->getTagName() . ">";

        $result = [];
        foreach ($lines as $items) {
            $output  = "";
            $isOpen  = false;
            $pending = "";
            foreach ($items as [$type, $value, $word]) {
                $isVisible = ($word >= $first || ($word === -1 && $first === 0)) && $word <= $last;
                $isStyled  = $word >= 0 && ($options->mode === WordHighlightMode::Word ? $word === $active : $word <= $active);

                if ($type === "text" && !$isVisible) {
                    continue;
                }

                if ($type === "tag" || !$isStyled) {
                    // The style closes before every tag, so that it never crosses the nesting of the other tags.
                    $output .= ($isOpen ? $closeTag : "") . $pending . $value;
                    $isOpen  = false;
                    $pending = "";
                    continue;
                }

                preg_match('/^(\s*)(.*?)(\s*)$/s', $value, $parts);
                if ($parts[2] === "") {
                    $pending .= $value;
                    continue;
                }

                $output .= $pending . $parts[1] . ($isOpen ? "" : $openTag) . $parts[2];
                $isOpen  = true;
                $pending = $parts[3];
            }
            $result[] = $output . ($isOpen ? $closeTag : "") . $pending;
        }

        if ($options->maxWordsPerCue === null) {
            return $result;
        }

        return array_values(array_filter(
            array_map(self::removeEmptyTagPairs(...), $result),
            fn (string $line): bool => trim(Markup::stripAllTags($line)) !== ""
        ));
    }


    /**
     * Returns the first and last word number that the cue shows, centred on the active word.
     *
     * @return array{int, int}
     */
    private static function window(int $active, int $wordCount, ?int $maxWords): array
    {
        if ($maxWords === null || $maxWords >= $wordCount) {
            return [-1, $wordCount - 1];
        }

        $first = max(0, min(max(0, $active) - intdiv($maxWords - 1, 2), $wordCount - $maxWords));

        return [$first, $first + $maxWords - 1];
    }


    /**
     * Removes the tag pairs and the spaces at the line edges that the hidden words leave behind.
     */
    private static function removeEmptyTagPairs(string $line): string
    {
        $line = Markup::removeEmptyTagPairs($line, withSpaces: true);

        return preg_replace(['/^((?:<[^\/][^>]*>)*)\s+/', '/\s+((?:<\/[^>]*>)*)$/'], '$1', $line);
    }
}
