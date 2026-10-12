<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMElement;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\XmlLoader;

final class YouTubeTimedTextParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::YouTubeTimedText->value;

    // A window anchor point runs from 0, top left, to 8, bottom right, row by row.
    private const ALIGNMENTS = [7, 8, 9, 4, 5, 6, 1, 2, 3];


    /**
     * Reads the YouTube timed text formats json3, srv3, srv2 and srv1, which is also the transcript XML.
     */
    protected function read(string $content): Subtitle
    {
        [$fileData, $captions] = self::isJson($content) ? $this->readJson($content) : $this->readXml($content);

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $captions   = $this->endAtNextCaption($captions, $fileData);
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData);
        foreach ($captions as $caption) {
            $cue = new SubtitleCue($caption["start"], $caption["end"], explode("\n", self::captionMarkup($caption["segments"], $caption["start"], $this->formatOptions()->wordTimestamps)));
            if ($cue->getLines() === []) {
                continue;
            }
            $cue->setAlignment(Alignment::from($caption["alignment"] ?? Alignment::BottomCenter->value));
            if ($caption["formatData"] !== []) {
                $cue->setFormatData(self::FORMAT_DATA_KEY, $caption["formatData"]);
            }
            $parsedCues[] = $cue;
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * The XML formats handle invalid UTF-8 like TTML. json3 reads it as U+FFFD in strict mode too, like the other JSON parsers.
     */
    protected function replacesInvalidUtf8(string $content): bool
    {
        return !self::isJson(StringHelpers::removeUtf8Bom($content));
    }


    private static function isJson(string $content): bool
    {
        return str_starts_with(ltrim($content), "{");
    }


    private function readJson(string $content): array
    {
        $data = $this->decodeJsonObject($content);
        if (!is_array($data["events"] ?? null) || !array_is_list($data["events"])) {
            throw new ParsingException("The JSON has no \"events\" list.");
        }

        $pens      = is_array($data["pens"] ?? null) ? $data["pens"] : [];
        $positions = is_array($data["wpWinPositions"] ?? null) ? $data["wpWinPositions"] : [];
        $fileData  = ["format" => "json3"] + array_diff_key($data, ["events" => true]);
        $captions  = [];
        foreach ($data["events"] as $index => $event) {
            $path = "events[$index]";
            if (is_array($event) && !isset($event["segs"]) && isset($event["id"])) {
                $fileData["windows"][] = $event;
                continue;
            }

            try {
                $start               = self::boundedField($this->milliseconds($event, "tStartMs", $path) / 1000, "$path.tStartMs");
                $end                 = self::boundedField($start + $this->milliseconds($event, "dDurationMs", $path, 0) / 1000, "$path.dDurationMs");
                [$segments, $extras] = $this->jsonSegments($event, $pens, $path, $start);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($event)]);
                continue;
            }

            $formatData = array_diff_key($event, ["segs" => true]);
            if (array_filter($extras) !== []) {
                $formatData["segments"] = $extras;
            }
            $captions[] = [
                "start"      => $start,
                "end"        => $end,
                "window"     => $event["wWinId"] ?? null,
                "append"     => !empty($event["aAppend"]),
                "alignment"  => $this->alignment($this->entry($positions, $event["wpWinPosId"] ?? null)["apPoint"] ?? null),
                "segments"   => $segments,
                "formatData" => $formatData,
                "source"     => $event,
            ];
        }

        return [$fileData, $captions];
    }


    /**
     * Returns the text segments of an event and the extra fields of each segment.
     *
     * @return array{list<array{string, ?float, array}>, list<array>}
     */
    private function jsonSegments(array $event, array $pens, string $path, float $start): array
    {
        $segments = [];
        $extras   = [];
        foreach (is_array($event["segs"] ?? null) ? $event["segs"] : [] as $segIndex => $seg) {
            $offset     = isset($seg["tOffsetMs"]) ? $this->milliseconds($seg, "tOffsetMs", "$path.segs[$segIndex]") / 1000 : null;
            if ($offset !== null) {
                self::boundedField($start + $offset, "$path.segs[$segIndex].tOffsetMs");
            }
            $pen        = $this->entry($pens, $seg["pPenId"] ?? $event["pPenId"] ?? null);
            $segments[] = [is_string($seg["utf8"] ?? null) ? $seg["utf8"] : "", $offset, $pen === null ? [] : self::jsonPenStyle($pen)];
            $extras[]   = is_array($seg) ? $seg : [];
        }

        return [$segments, $extras];
    }


    private function entry(array $list, mixed $id): ?array
    {
        return is_int($id) && is_array($list[$id] ?? null) ? $list[$id] : null;
    }


    /**
     * Returns the style of a json3 pen: color, b, i and u.
     *
     * @internal
     */
    public static function jsonPenStyle(array $pen): array
    {
        $color = $pen["fcForeColor"] ?? null;

        return [
            "color" => is_int($color) && $color >= 0 && $color <= 0xFFFFFF ? sprintf("#%06x", $color) : null,
            "b"     => !empty($pen["bAttr"]),
            "i"     => !empty($pen["iAttr"]),
            "u"     => !empty($pen["uAttr"]),
        ];
    }


    private function milliseconds(mixed $object, string $key, string $path, ?int $default = null): int|float
    {
        $value = is_array($object) ? $object[$key] ?? $default : null;
        if (!self::isTime($value)) {
            throw new ParsingException("The field $path.$key must be a number.");
        }

        return $value;
    }


    private function readXml(string $content): array
    {
        $repaired = XmlLoader::skipLeadingText($content, "(?:timedtext|transcript)", $skipped);
        if ($skipped !== "") {
            $this->skipTextBeforeXml($content, $skipped);
        }

        $root = XmlLoader::xml($repaired)?->documentElement ?? throw new ParsingException("The content is not well-formed XML.");

        return match (true) {
            $root->nodeName === "transcript"                        => [["format" => "srv1"], $this->readTexts($root, "start", "dur", 1)],
            $root->nodeName === "timedtext" && $this->body($root) !== null => $this->readSrv3($root, $this->body($root)),
            $root->nodeName === "timedtext"                         => [["format" => "srv2"], $this->readTexts($root, "t", "d", 1000)],
            default => throw new ParsingException("The root element must be <timedtext> or <transcript>, not <{$root->nodeName}>."),
        };
    }


    private function body(DOMElement $root): ?DOMElement
    {
        foreach ($this->children($root) as $child) {
            if ($child->nodeName === "body") {
                return $child;
            }
        }

        return null;
    }


    /**
     * @return list<DOMElement>
     */
    private function children(DOMElement $element): array
    {
        return array_values(array_filter(iterator_to_array($element->childNodes), fn ($node): bool => $node instanceof DOMElement));
    }


    private function readTexts(DOMElement $root, string $startName, string $durationName, int $unitsPerSecond): array
    {
        $captions = [];
        foreach ($this->children($root) as $index => $text) {
            try {
                $start = self::boundedTime($this->time($text, $startName) / $unitsPerSecond, $text->getAttribute($startName), $text->getLineNo());
                $end   = $text->hasAttribute($durationName)
                    ? self::boundedTime($start + $this->time($text, $durationName) / $unitsPerSecond, $text->getAttribute($durationName), $text->getLineNo())
                    : null;
            } catch (ParsingException $exception) {
                $this->fail($exception, $text->getLineNo(), $index, [$text->ownerDocument->saveXML($text)]);
                continue;
            }

            $captions[] = [
                "start"      => $start,
                "end"        => $end,
                "window"     => null,
                "append"     => false,
                "alignment"  => null,
                "segments"   => [[Markup::decodeEntities($text->textContent), null, []]],
                "formatData" => $this->attributes($text) + self::rawText($text->textContent),
            ];
        }

        return $captions;
    }


    private function readSrv3(DOMElement $root, DOMElement $body): array
    {
        $fileData         = ["format" => "srv3"];
        [$pens, $anchors] = $this->readSrv3Head($root, $fileData);

        $captions = [];
        foreach ($this->children($body) as $index => $paragraph) {
            $attributes = $this->attributes($paragraph);
            if ($paragraph->nodeName === "w") {
                $fileData["w"][] = $attributes;
                continue;
            }
            if ($paragraph->nodeName !== "p") {
                continue;
            }

            try {
                $start               = self::boundedTime($this->time($paragraph, "t") / 1000, $paragraph->getAttribute("t"), $paragraph->getLineNo());
                $end                 = $paragraph->hasAttribute("d")
                    ? self::boundedTime($start + $this->time($paragraph, "d") / 1000, $paragraph->getAttribute("d"), $paragraph->getLineNo())
                    : null;
                [$segments, $extras] = $this->srv3Segments($paragraph, $pens, $start);
            } catch (ParsingException $exception) {
                $this->fail($exception, $paragraph->getLineNo(), $index, [$paragraph->ownerDocument->saveXML($paragraph)]);
                continue;
            }

            $formatData = $attributes;
            if (array_filter($extras) !== []) {
                $formatData["segments"] = $extras;
            }
            $captions[] = [
                "start"      => $start,
                "end"        => $end,
                "window"     => $attributes["w"] ?? null,
                "append"     => ($attributes["a"] ?? "") === "1",
                "alignment"  => $this->alignment($anchors[$attributes["wp"] ?? ""] ?? null),
                "segments"   => $segments,
                "formatData" => $formatData,
                "source"     => $attributes + ["text" => $paragraph->textContent],
            ];
        }

        return [$fileData, $captions];
    }


    /**
     * Adds the definitions of the head to $fileData, and returns the pen styles and the anchor points by ID.
     *
     * @return array{array<string, array>, array<string, int>}
     */
    private function readSrv3Head(DOMElement $root, array &$fileData): array
    {
        $pens    = [];
        $anchors = [];
        foreach ($this->children($root) as $section) {
            if ($section->nodeName !== "head") {
                continue;
            }
            foreach ($this->children($section) as $definition) {
                $attributes                        = $this->attributes($definition);
                $fileData[$definition->nodeName][] = $attributes;
                $id                                = $attributes["id"] ?? "";
                if ($definition->nodeName === "pen") {
                    $pens[$id] = self::xmlPenStyle($attributes);
                } elseif ($definition->nodeName === "wp" && preg_match('/^[0-9]+$/D', $attributes["ap"] ?? "") === 1) {
                    $anchors[$id] = (int) $attributes["ap"];
                }
            }
        }

        return [$pens, $anchors];
    }


    /**
     * Returns the text segments of a paragraph and the extra attributes of each span.
     *
     * @param array<string, array> $pens
     * @return array{list<array{string, ?float, array}>, list<array<string, string>>}
     */
    private function srv3Segments(DOMElement $paragraph, array $pens, float $start): array
    {
        $segments = [];
        $extras   = [];
        foreach ($paragraph->childNodes as $node) {
            $span       = $node instanceof DOMElement ? $node : null;
            $offset     = $span?->hasAttribute("t") ? $this->time($span, "t") / 1000 : null;
            if ($offset !== null) {
                self::boundedTime($start + $offset, $span->getAttribute("t"), $span->getLineNo());
            }
            $pen        = $pens[$span?->getAttribute("p") ?: $paragraph->getAttribute("p")] ?? [];
            $segments[] = [Markup::decodeEntities($node->textContent), $offset, $pen];
            $extras[]   = ($span === null ? [] : $this->attributes($span)) + ["text" => $node->textContent];
        }

        return [$segments, $extras];
    }


    /**
     * Returns the text before the parser decodes its entities, when the decoding changes it. A file with
     * double-escaped entities such as "&amp;#39;" holds "&#39;" there, and the formatter needs it to write the file again.
     *
     * @return array{text?: string}
     */
    private static function rawText(string $text): array
    {
        return Markup::decodeEntities($text) === $text ? [] : ["text" => $text];
    }


    /**
     * Returns the style of a srv3 pen: color, b, i and u.
     *
     * @internal
     */
    public static function xmlPenStyle(array $attributes): array
    {
        $color = $attributes["fc"] ?? "";

        return [
            "color" => preg_match('/\A#[0-9A-Fa-f]{6}\z/', $color) === 1 ? strtolower($color) : null,
            "b"     => ($attributes["b"] ?? "") === "1",
            "i"     => ($attributes["i"] ?? "") === "1",
            "u"     => ($attributes["u"] ?? "") === "1",
        ];
    }


    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->value;
        }

        return $attributes;
    }


    private function time(DOMElement $element, string $name, ?string $default = null): float
    {
        $value = $element->hasAttribute($name) ? trim($element->getAttribute($name)) : $default;
        if ($value === null || !is_numeric($value) || !self::isTime((float) $value)) {
            throw new ParsingException("The <{$element->nodeName}> element has no valid \"$name\" attribute.", $element->getLineNo());
        }

        return (float) $value;
    }


    private function alignment(mixed $anchorPoint): ?int
    {
        return is_int($anchorPoint) ? self::ALIGNMENTS[$anchorPoint] ?? null : null;
    }


    /**
     * Skips the append captions, which only add a line break. Each cue keeps the append captions before it in
     * "appends" of its format data, and the file keeps the ones after the last cue.
     * Sets the end of each caption with captionEnds().
     */
    private function endAtNextCaption(array $captions, array &$fileData): array
    {
        $kept    = [];
        $appends = [];
        foreach ($captions as $caption) {
            if ($caption["append"]) {
                $appends[] = $caption["source"];
                continue;
            }
            if ($appends !== []) {
                $caption["formatData"]["appends"] = $appends;
                $appends                          = [];
            }
            $kept[] = $caption;
        }
        if ($appends !== []) {
            $fileData["appends"] = $appends;
        }

        $ends = self::captionEnds(array_map(fn (array $caption): array => [$caption["start"], $caption["end"], $caption["window"]], $kept),
                                  $this->options->lastCueDuration);
        foreach ($ends as $index => $end) {
            $kept[$index]["end"] = $end;
        }

        return $kept;
    }


    /**
     * Returns the end of each caption. A caption without a duration ends at the next later start, or lasts
     * $lastCueDuration at the end. A caption ends where the next caption in its window starts.
     * The formatter uses it to tell whether a cue still has the end of its source duration.
     *
     * @internal
     *
     * @param list<array{float, ?float, mixed}> $captions the start, the end of the source duration or null, and the window
     *
     * @return list<float>
     */
    public static function captionEnds(array $captions, float $lastCueDuration): array
    {
        $ends = [];
        foreach ($captions as $index => [$start, $end, $window]) {
            for ($next = $index + 1; $end === null && $next < count($captions); $next++) {
                $end = $captions[$next][0] > $start ? $captions[$next][0] : null;
            }
            $end ??= Timecode::roundToMilliseconds($start + $lastCueDuration);
            for ($next = $index + 1; $window !== null && $next < count($captions); $next++) {
                if ($captions[$next][2] === $window && $captions[$next][0] > $start) {
                    $end = min($end, $captions[$next][0]);
                    break;
                }
            }
            $ends[] = $end;
        }

        return $ends;
    }


    /**
     * Returns the cue markup of the text segments of a caption, each with its text, its offset from $start in seconds
     * or null, and its pen style. The formatter compares a cue with it to tell whether the cue is unchanged.
     *
     * @internal
     *
     * @param list<array{string, ?float, array}> $segments
     */
    public static function captionMarkup(array $segments, float $start, bool $wordTimestamps): string
    {
        $timed  = $wordTimestamps && array_filter(array_column($segments, 1), fn (?float $offset): bool => $offset !== null) !== [];
        $markup = "";
        foreach ($segments as [$text, $offset, $style]) {
            if (!$timed) {
                $markup .= self::styled($text, $style);
                continue;
            }

            preg_match('/\A(\s*)(.*)\z/s', $text, $parts);
            $markup .= $parts[1];
            if ($parts[2] !== "") {
                $markup .= "<" . Markup::coreTimestamp($start + ($offset ?? 0)) . ">" . self::styled($parts[2], $style);
            }
        }

        return $markup;
    }


    private static function styled(string $text, array $style): string
    {
        return implode("\n", array_map(
            fn (string $line): string => StyleRuns::toMarkup([[$line, $style]], true),
            explode("\n", StringHelpers::normalizeEOLs($text))
        ));
    }
}
