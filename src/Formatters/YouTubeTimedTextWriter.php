<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Writes YouTube timed text. The YouTube timed text formatter will use it once it writes every variant.
 *
 * @internal
 */
final class YouTubeTimedTextWriter
{
    private const KEY = YouTubeTimedTextParser::FORMAT_DATA_KEY;

    private const XML_DECLARATION = '<?xml version="1.0" encoding="utf-8" ?>';

    // Format data fields that are no XML attributes.
    private const NOT_ATTRIBUTES = ["text" => true, "appends" => true, "segments" => true];


    /**
     * Writes YouTube timed text in the variant that the "format" field of the subtitle format data names.
     */
    public function write(Subtitle $subtitle): string
    {
        $fileData = $subtitle->findFormatData(self::KEY);
        $cues     = $subtitle->getCues();
        $cueData  = array_map(fn (SubtitleCue $cue): array => $cue->findFormatData(self::KEY), $cues);

        return match ($fileData["format"] ?? null) {
            "srv1"  => $this->srv1($cues, $cueData),
            "srv2"  => $this->srv2($cues, $cueData),
            default => $this->srv3($cues, $cueData, $fileData),
        };
    }


    /**
     * Writes the transcript XML: one line, times in seconds, plain text.
     *
     * @param list<SubtitleCue> $cues
     */
    private function srv1(array $cues, array $cueData): string
    {
        $xml = self::XML_DECLARATION . "<transcript>";
        foreach ((new YouTubeCaptionTimes("start", "dur", null, 1, true))->captions($cues, $cueData) as $index => $caption) {
            $xml .= $this->text($caption["fields"], $cues[$index]);
        }

        return $xml . "</transcript>";
    }


    /**
     * Writes srv2: one <text> per line, times in milliseconds, plain text.
     *
     * @param list<SubtitleCue> $cues
     */
    private function srv2(array $cues, array $cueData): string
    {
        $xml = self::XML_DECLARATION . "\n<timedtext>\n";
        foreach ((new YouTubeCaptionTimes("t", "d", null, 1000, true))->captions($cues, $cueData) as $index => $caption) {
            $xml .= $this->text($caption["fields"], $cues[$index]) . "\n";
        }

        return $xml . "</timedtext>\n";
    }


    /**
     * Writes srv3: the pens, window styles and window positions in the head, then the windows, the paragraphs and the
     * append paragraphs in the body. Styles become pens, and equal styles share one pen. An alignment other than bottom
     * center becomes a window position. Word timestamps become <s> segments with a "t" offset in milliseconds.
     *
     * @param list<SubtitleCue> $cues
     */
    private function srv3(array $cues, array $cueData, array $fileData): string
    {
        $head = [];
        foreach (["pen", "ws", "wp"] as $name) {
            $head[$name] = is_array($fileData[$name] ?? null) ? array_values(array_filter($fileData[$name], is_array(...))) : [];
        }

        $body = "";
        foreach (is_array($fileData["w"] ?? null) ? $fileData["w"] : [] as $window) {
            $body .= "<w" . self::attributes(is_array($window) ? $window : []) . "/>\n";
        }
        foreach ((new YouTubeCaptionTimes("t", "d", "w", 1000, true))->captions($cues, $cueData) as $index => $caption) {
            foreach ($caption["appends"] as $append) {
                $body .= self::append($append);
            }
            $body .= $this->srv3Paragraph($cues[$index], $caption["fields"], $head) . "\n";
        }
        foreach (is_array($fileData["appends"] ?? null) ? array_filter($fileData["appends"], is_array(...)) : [] as $append) {
            $body .= self::append($append);
        }

        $headXml = "";
        foreach ($head as $name => $elements) {
            foreach ($elements as $attributes) {
                $headXml .= "<$name" . self::attributes($attributes) . "/>\n";
            }
        }

        return self::XML_DECLARATION . "<timedtext format=\"3\">\n" . ($headXml === "" ? "" : "<head>\n$headXml</head>\n")
            . "<body>\n$body</body>\n</timedtext>\n";
    }


    /**
     * @param array{pen: list<array>, ws: list<array>, wp: list<array>} $head the head elements, to which new pens and window positions go
     */
    private function srv3Paragraph(SubtitleCue $cue, array $fields, array &$head): string
    {
        $penStyles = [];
        foreach ($head["pen"] as $pen) {
            $penStyles[(string) ($pen["id"] ?? "")] = YouTubeTimedTextParser::xmlPenStyle($pen);
        }
        $paragraphPen = is_scalar($fields["p"] ?? null) ? (string) $fields["p"] : null;
        $source       = [];
        foreach (is_array($fields["segments"] ?? null) ? $fields["segments"] : [] as $segment) {
            $segment  = is_array($segment) ? $segment : [];
            $source[] = [
                "text"   => Markup::decodeEntities(is_string($segment["text"] ?? null) ? $segment["text"] : ""),
                "offset" => is_numeric($segment["t"] ?? null) ? (float) $segment["t"] / 1000 : null,
                "style"  => self::penStyle($penStyles, $segment["p"] ?? $paragraphPen),
                "fields" => $segment,
            ];
        }

        [$unchanged, $segments] = YouTubeSegments::forCue($cue, $source);
        $fields                 = self::windowPosition($cue, array_diff_key($fields, self::NOT_ATTRIBUTES), $head["wp"]);
        if ($unchanged) {
            $content = implode("", array_map(fn (array $segment): string => self::span($segment["fields"]), $segments));

            return "<p" . self::attributes($fields) . ">$content</p>";
        }

        unset($fields["p"]);
        if (count($segments) === 1 && !isset($segments[0]["fields"]["t"]) && ($segments[0]["offset"] ?? 0.0) === 0.0) {
            $pen = self::pen($head["pen"], $penStyles, $segments[0]["style"], $paragraphPen);

            return "<p" . self::attributes($fields + ($pen === null ? [] : ["p" => $pen])) . ">" . self::segmentText($segments[0]) . "</p>";
        }

        $content = "";
        foreach ($segments as $segment) {
            $pen         = self::pen($head["pen"], $penStyles, $segment["style"], is_scalar($segment["fields"]["p"] ?? null) ? (string) $segment["fields"]["p"] : null);
            $attributes  = array_diff_key($segment["fields"], ["text" => true, "t" => true, "p" => true]);
            $offset      = (int) round(($segment["offset"] ?? 0.0) * 1000);
            $attributes  = ($offset > 0 || isset($segment["fields"]["t"]) ? ["t" => (string) $offset] : []) + ($pen === null ? [] : ["p" => $pen]) + $attributes;
            $content    .= $attributes === [] ? self::escape(self::segmentText($segment)) : "<s" . self::attributes($attributes) . ">" . self::segmentText($segment) . "</s>";
        }

        return "<p" . self::attributes($fields) . ">$content</p>";
    }


    /**
     * Returns a stored srv3 segment: an <s> element when it has attributes, else its text.
     */
    private static function span(array $segment): string
    {
        $text       = self::escape(is_string($segment["text"] ?? null) ? $segment["text"] : "");
        $attributes = array_diff_key($segment, ["text" => true]);

        return $attributes === [] ? $text : "<s" . self::attributes($attributes) . ">$text</s>";
    }


    /**
     * Returns the escaped text of a segment. A text that the source wrote with escaped entities keeps them.
     */
    private static function segmentText(array $segment): string
    {
        $raw = $segment["fields"]["text"] ?? null;

        return self::escape(is_string($raw) && Markup::decodeEntities($raw) === $segment["text"] ? $raw : $segment["text"]);
    }


    private static function append(array $append): string
    {
        $text = is_string($append["text"] ?? null) ? $append["text"] : "\n";

        return "<p" . self::attributes(array_diff_key($append, ["text" => true])) . ">" . self::escape($text) . "</p>\n";
    }


    private static function penStyle(array $penStyles, mixed $id): array
    {
        return $penStyles[is_scalar($id) ? (string) $id : ""] ?? YouTubeTimedTextParser::xmlPenStyle([]);
    }


    /**
     * Returns the ID of the pen for a style: the preferred pen or the first pen with that style. Adds a pen when none has it.
     * A segment without style gets no pen.
     *
     * @param list<array>          $pens
     * @param array<string, array> $penStyles
     */
    private static function pen(array &$pens, array &$penStyles, array $style, ?string $preferred): ?string
    {
        if ($preferred !== null && ($penStyles[$preferred] ?? null) === $style) {
            return $preferred;
        }
        if ($style === YouTubeTimedTextParser::xmlPenStyle([])) {
            return null;
        }
        $id = array_search($style, $penStyles, true);
        if ($id !== false) {
            return (string) $id;
        }

        $id     = (string) (max([0, ...array_map(intval(...), array_keys($penStyles))]) + 1);
        $pens[] = ["id" => $id] + ($style["b"] ? ["b" => "1"] : []) + ($style["i"] ? ["i" => "1"] : []) + ($style["u"] ? ["u" => "1"] : [])
            + ($style["color"] === null ? [] : ["fc" => strtoupper($style["color"])]);
        $penStyles[$id] = $style;

        return $id;
    }


    /**
     * Sets "wp" to a window position with the alignment of the cue. Bottom center needs none. A new window position
     * has the anchor point and the matching position in percent.
     *
     * @param list<array> $positions
     */
    private static function windowPosition(SubtitleCue $cue, array $fields, array &$positions): array
    {
        $alignments = [];
        foreach ($positions as $position) {
            $anchor = is_numeric($position["ap"] ?? null) ? (int) $position["ap"] : null;
            $alignments[(string) ($position["id"] ?? "")] = YouTubeTimedTextParser::alignment($anchor) ?? Alignment::BottomCenter->value;
        }

        $alignment = $cue->getAlignment()->value;
        $current   = is_scalar($fields["wp"] ?? null) ? (string) $fields["wp"] : null;
        if (($current === null ? Alignment::BottomCenter->value : $alignments[$current] ?? Alignment::BottomCenter->value) === $alignment) {
            return $fields;
        }
        if ($alignment === Alignment::BottomCenter->value) {
            unset($fields["wp"]);

            return $fields;
        }

        $id = array_search($alignment, $alignments, true);
        if ($id === false) {
            $anchor      = YouTubeTimedTextParser::anchorPoint($cue->getAlignment());
            $id          = (string) (max([0, ...array_map(intval(...), array_keys($alignments))]) + 1);
            $positions[] = ["id" => $id, "ap" => (string) $anchor, "ah" => (string) ($anchor % 3 * 50), "av" => (string) (intdiv($anchor, 3) * 50)];
        }

        $fields["wp"] = (string) $id;

        return $fields;
    }


    /**
     * Returns the <text> element of a cue. A text that the source wrote with escaped entities keeps them.
     */
    private function text(array $fields, SubtitleCue $cue): string
    {
        $text = implode("\n", Markup::plainLines(array_map(Markup::rubyAsText(...), $cue->getLines())));
        $raw  = $fields["text"] ?? null;

        return "<text" . self::attributes(array_diff_key($fields, self::NOT_ATTRIBUTES)) . ">"
            . self::escape(is_string($raw) && Markup::decodeEntities($raw) === $text ? $raw : $text) . "</text>";
    }


    private static function attributes(array $attributes): string
    {
        $xml = "";
        foreach ($attributes as $name => $value) {
            if (is_string($name) && preg_match('/^[A-Za-z_][\w.-]*$/', $name) === 1 && is_scalar($value)) {
                $xml .= " $name=\"" . htmlspecialchars(XmlOutput::text((string) $value), ENT_XML1 | ENT_COMPAT, "UTF-8") . '"';
            }
        }

        return $xml;
    }


    private static function escape(string $text): string
    {
        return htmlspecialchars(XmlOutput::text($text), ENT_XML1 | ENT_NOQUOTES, "UTF-8");
    }
}
