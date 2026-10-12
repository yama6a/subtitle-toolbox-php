<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

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
            default => $this->srv2($cues, $cueData),
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
