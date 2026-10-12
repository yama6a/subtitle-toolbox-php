<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Builds the text segments of a srv3 or json3 caption from a cue. A segment has a text, an offset from the cue
 * start in seconds or null, a pen style, and the other fields of its source segment.
 *
 * @phpstan-type Style array{color: ?string, b: bool, i: bool, u: bool}
 * @phpstan-type Segment array{text: string, offset: ?float, style: Style, fields: array}
 *
 * @internal
 */
final class YouTubeSegments
{
    private const PLAIN = ["color" => null, "b" => false, "i" => false, "u" => false];


    /**
     * Returns whether the cue has the text, the word times and the styles of its source segments, and the segments to write.
     * An unchanged cue keeps its source segments. Else the segments come from the cue markup, and a segment with the text
     * and the offset of a source segment keeps its other fields.
     *
     * @param list<Segment> $source the source segments with their decoded texts
     *
     * @return array{bool, list<Segment>}
     */
    public static function forCue(SubtitleCue $cue, array $source): array
    {
        $start    = max(0.0, $cue->getStart());
        $captions = array_map(fn (array $segment): array => [$segment["text"], $segment["offset"], $segment["style"]], $source);
        foreach ([true, false] as $wordTimestamps) {
            $lines = explode("\n", YouTubeTimedTextParser::captionMarkup($captions, $start, $wordTimestamps));
            if ($source !== [] && (new SubtitleCue(0, 0, $lines))->getLines() === $cue->getLines()) {
                return [true, $source];
            }
        }

        $segments = self::fromMarkup($cue, $start);

        return [false, self::retext($segments, $source) ?? self::keepFields($segments, $source)];
    }


    /**
     * @return list<Segment>
     */
    private static function fromMarkup(SubtitleCue $cue, float $start): array
    {
        $segments = [];
        $styles   = [];
        $offset   = null;
        $timed    = false;
        $text     = Markup::rubyAsText(implode("\n", $cue->getLines()));
        foreach (Markup::splitTags($text) as $index => $token) {
            if ($index % 2 === 0) {
                $style = self::style($styles);
                $last  = count($segments) - 1;
                if ($token === "") {
                    continue;
                }
                if ($last >= 0 && !$timed && $segments[$last]["style"] === $style) {
                    $segments[$last]["text"] .= Markup::decodeEntities($token);
                } elseif ($last >= 0 && !$timed && $style === self::PLAIN && trim($token) === "") {
                    $segments[$last]["text"] .= Markup::decodeEntities($token);
                } else {
                    $segments[] = ["text" => Markup::decodeEntities($token), "offset" => $offset, "style" => $style, "fields" => []];
                }
                $timed = false;
                continue;
            }

            $time = Markup::wordTimestampSeconds($token);
            if ($time !== null) {
                $offset = Timecode::roundToMilliseconds(max(0.0, $time - $start));
                $timed  = true;
                continue;
            }
            $styles = self::applyTag($styles, $token);
        }

        return self::moveSpacesAfterTimes($segments);
    }


    /**
     * The parser writes the leading white space of a timed segment before its word timestamp. So the white space at
     * the end of a segment goes back to the start of the next timed segment.
     *
     * @param list<Segment> $segments
     *
     * @return list<Segment>
     */
    private static function moveSpacesAfterTimes(array $segments): array
    {
        for ($index = 1; $index < count($segments); $index++) {
            if ($segments[$index]["offset"] === null || $segments[$index]["offset"] === $segments[$index - 1]["offset"]) {
                continue;
            }
            preg_match('/\A(.*?)(\s*)\z/s', $segments[$index - 1]["text"], $parts);
            if ($parts[1] !== "") {
                $segments[$index - 1]["text"] = $parts[1];
                $segments[$index]["text"]     = $parts[2] . $segments[$index]["text"];
            }
        }

        return $segments;
    }


    /**
     * A segment with the text and the offset of the next unused source segment keeps the other fields of that segment.
     * A first segment without an offset starts at offset 0.
     *
     * @param list<Segment> $segments
     * @param list<Segment> $source
     *
     * @return list<Segment>
     */
    private static function keepFields(array $segments, array $source): array
    {
        $next = 0;
        foreach ($segments as $index => $segment) {
            for ($candidate = $next; $candidate < count($source); $candidate++) {
                if ($source[$candidate]["text"] === $segment["text"] && ($source[$candidate]["offset"] ?? 0.0) === ($segment["offset"] ?? 0.0)) {
                    $segments[$index]["fields"] = $source[$candidate]["fields"];
                    $next                       = $candidate + 1;
                    break;
                }
            }
        }

        return $segments;
    }


    /**
     * For a cue without word timestamps and styles whose text has as many words as the source has timed segments of one
     * word each, returns the source segments with the new words. A changed word keeps the offset of its source segment.
     * Returns null in the other cases.
     *
     * @param list<Segment> $segments
     * @param list<Segment> $source
     *
     * @return ?list<Segment>
     */
    private static function retext(array $segments, array $source): ?array
    {
        if (count($segments) !== 1 || $segments[0]["offset"] !== null || $segments[0]["style"] !== self::PLAIN
            || array_filter($source, fn (array $segment): bool => $segment["offset"] !== null) === []) {
            return null;
        }

        $words = preg_split('/\s+/u', trim($segments[0]["text"]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) !== count($source)) {
            return null;
        }

        foreach ($source as $index => $segment) {
            preg_match('/\A(\s*)(\S+)(\s*)\z/u', $segment["text"], $parts);
            if ($parts === [] || $segment["style"] !== self::PLAIN) {
                return null;
            }
            if ($parts[2] !== $words[$index]) {
                $source[$index]["text"]   = $parts[1] . $words[$index] . $parts[3];
                $source[$index]["fields"] = [];
            }
        }

        return $source;
    }


    /**
     * @param list<array{string, ?string}> $styles the open style tags and the color of each <font>
     *
     * @return Style
     */
    private static function style(array $styles): array
    {
        $style = self::PLAIN;
        foreach ($styles as [$name, $color]) {
            if ($name === "font") {
                $style["color"] = $color ?? $style["color"];
            } elseif (isset($style[$name])) {
                $style[$name] = true;
            }
        }

        return $style;
    }


    /**
     * @param list<array{string, ?string}> $styles
     *
     * @return list<array{string, ?string}>
     */
    private static function applyTag(array $styles, string $tag): array
    {
        if (preg_match('/^<(\/?)(b|i|u|font)\b/i', $tag, $match) !== 1) {
            return $styles;
        }

        $name = strtolower($match[2]);
        if ($match[1] === "") {
            $styles[] = [$name, $name === "font" ? MarkupColors::fontColorHex($tag) : null];

            return $styles;
        }
        for ($index = count($styles) - 1; $index >= 0; $index--) {
            if ($styles[$index][0] === $name) {
                array_splice($styles, $index, 1);
                break;
            }
        }

        return $styles;
    }
}
