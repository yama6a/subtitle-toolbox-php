<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\DcpParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

/**
 * Writes the dialect that DcpParser read, with its header, fonts and line positions. A new file is SMPTE ST 428-7:2014
 * at 24 fps with the defaults of DCP-o-matic.
 */
final class DcpFormatter extends SubtitleFormatter
{
    private const SMPTE_2014 = "http://www.smpte-ra.org/schemas/428-7/2014/DCST";

    private const XS = "http://www.w3.org/2001/XMLSchema";

    private const INTEROP_TICKS = 250;

    private const DEFAULT_RATE = 24;

    private const DEFAULT_FONT = [
        "AspectAdjust" => "1.0", "Color" => "FFFFFFFF", "Effect" => "none", "EffectColor" => "FF000000", "ID" => "font",
        "Italic" => "no", "Script" => "normal", "Size" => "42", "Underline" => "no", "Weight" => "normal",
    ];

    // The space between the baselines of 2 lines, in percent of the screen height, and the distance of the outer line to the edge.
    private const LINE_SPACING = 7;
    private const EDGE = 8;

    private const INDENT = "  ";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $data     = $this->fileData($subtitle);
        $interop  = $data["dialect"] === "interop";
        $rate     = $interop ? self::INTEROP_TICKS : (float) ($data["timeCodeRate"] ?? self::DEFAULT_RATE);
        $prefix   = $interop || ($data["prefix"] ?? "") === "" ? "" : $data["prefix"] . ":";
        $root     = $prefix . ($interop ? "DCSubtitle" : "SubtitleReel");
        $font     = $data["font"] ?? null;

        $depth   = $interop ? 1 : 2;
        $content = "";
        foreach ($subtitle->getCues() as $index => $cue) {
            $content .= $this->subtitle($cue, $index + 1, $prefix, $interop, $rate, $font ?? [], $depth + ($font === null ? 0 : 1));
        }
        if ($font !== null) {
            $content = str_repeat(self::INDENT, $depth) . "<{$prefix}Font" . $this->attributes($font) . ">\n" . $content . str_repeat(self::INDENT, $depth) . "</{$prefix}Font>\n";
        }

        // A new file gets Ids from the hash of its subtitles, so the same subtitles give the same Ids.
        $id = self::uuid($content);
        $data["header"] = array_map(fn (?string $value): string => $value ?? "urn:uuid:$id", $data["header"]);
        foreach ($data["loadFonts"] as $index => $loadFont) {
            $data["loadFonts"][$index]["text"] = $loadFont["text"] ?? "urn:uuid:" . self::uuid("font $id");
        }

        $rootAttributes = $interop ? [] : [($prefix === "" ? "xmlns" : "xmlns:" . substr($prefix, 0, -1)) => $data["namespace"] ?? self::SMPTE_2014];
        $output         = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<$root" . $this->attributes($rootAttributes + $data["attributes"]) . ">\n";
        foreach ($data["header"] as $name => $value) {
            $output .= self::INDENT . "<$prefix$name>" . $this->escape((string) $value) . "</$prefix$name>\n";
        }
        foreach ($data["loadFonts"] as $loadFont) {
            $attributes = $this->attributes($loadFont["attributes"] ?? []);
            $text       = $loadFont["text"];
            $output    .= self::INDENT . ($text === "" ? "<{$prefix}LoadFont$attributes/>" : "<{$prefix}LoadFont$attributes>" . $this->escape($text) . "</{$prefix}LoadFont>") . "\n";
        }
        $output .= $interop ? $content : self::INDENT . "<{$prefix}SubtitleList>\n" . $content . self::INDENT . "</{$prefix}SubtitleList>\n";
        $output .= "</$root>\n";

        XmlOutput::load($output);

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Returns the format data of a read file, with the title and the language of the metadata. A new file gets the
     * SMPTE 2014 defaults, and header values in the format data win over them.
     *
     * @return array<string, mixed>
     */
    private function fileData(Subtitle $subtitle): array
    {
        $data = $subtitle->findFormatData(DcpParser::FORMAT_DATA_KEY) ?? [];
        if (!in_array($data["dialect"] ?? null, ["interop", "smpte"], true)) {
            $data = [
                "dialect"      => "smpte",
                "namespace"    => self::SMPTE_2014,
                "timeCodeRate" => self::DEFAULT_RATE,
                "attributes"   => ["xmlns:xs" => self::XS],
                "header"       => array_replace([
                    "Id"               => null,
                    "ContentTitleText" => "",
                    "IssueDate"        => gmdate("Y-m-d\TH:i:s"),
                    "ReelNumber"       => "1",
                    "Language"         => "en",
                    "EditRate"         => self::DEFAULT_RATE . " 1",
                    "TimeCodeRate"     => (string) self::DEFAULT_RATE,
                    "StartTime"        => "00:00:00:00",
                ], $data["header"] ?? []),
                "loadFonts"    => [["attributes" => ["ID" => "font"], "text" => null]],
                "font"         => self::DEFAULT_FONT,
            ];
        }
        $data += ["attributes" => [], "header" => [], "loadFonts" => []];

        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE);
        $field = $data["dialect"] === "interop" ? "MovieTitle" : "ContentTitleText";
        if ($title !== null && (array_key_exists($field, $data["header"]) || $data["dialect"] === "smpte")) {
            $data["header"][$field] = $title;
        }
        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        if ($language !== null && $data["dialect"] === "smpte" && array_key_exists("Language", $data["header"])) {
            $data["header"]["Language"] = $language;
        }

        return $data;
    }


    /**
     * Returns a name-based UUID (version 3) from the MD5 hash of $name.
     */
    private static function uuid(string $name): string
    {
        $hash = md5($name);

        return sprintf("%s-%s-3%s-%x%s-%s", substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3),
            hexdec($hash[16]) & 0x3 | 0x8, substr($hash, 17, 3), substr($hash, 20, 12));
    }


    /**
     * @param array<string, string> $font the attributes of the <Font> around the subtitles
     */
    private function subtitle(SubtitleCue $cue, int $number, string $prefix, bool $interop, float $rate, array $font, int $depth): string
    {
        $stored     = $cue->findFormatData(DcpParser::FORMAT_DATA_KEY) ?? [];
        $attributes = ($stored["subtitle"] ?? ["SpotNumber" => "", "TimeIn" => "", "TimeOut" => ""]
            + ($interop ? ["FadeUpTime" => "20", "FadeDownTime" => "20"] : ["FadeUpTime" => "00:00:00:00", "FadeDownTime" => "00:00:00:00"]));
        $attributes["SpotNumber"] = (string) $number;
        $attributes["TimeIn"]     = $this->time(max(0.0, $cue->getStart()), $rate, $interop);
        $attributes["TimeOut"]    = $this->time(max(0.0, $cue->getEnd()), $rate, $interop);

        $lines = array_values(array_filter(
            Markup::carryOpenTags(array_map(Markup::rubyAsText(...), $cue->getLines())),
            fn (string $line): bool => trim(Markup::plainText($line)) !== ""
        ));
        $texts = $this->textAttributes($cue, $stored["texts"] ?? null, count($lines), $interop);

        $indent = str_repeat(self::INDENT, $depth);
        $output = "$indent<{$prefix}Subtitle" . $this->attributes($attributes) . ">\n";
        foreach ($lines as $index => $line) {
            $output .= "$indent" . self::INDENT . "<{$prefix}Text" . $this->attributes($texts[$index]) . ">" .
                       $this->runs($line, $prefix, $interop, $font) . "</{$prefix}Text>\n";
        }

        return $output . "$indent</{$prefix}Subtitle>\n";
    }


    /**
     * Returns the stored <Text> attributes when the cue keeps its alignment and line count, else positions from the alignment.
     *
     * @param ?list<array<string, string>> $stored
     *
     * @return list<array<string, string>>
     */
    private function textAttributes(SubtitleCue $cue, ?array $stored, int $count, bool $interop): array
    {
        if (is_array($stored) && $count > 0 && count($stored) === $count && DcpParser::alignment($stored) === $cue->getAlignment()) {
            return $stored;
        }

        [$vAlign, $hAlign, $vPosition] = $interop ? ["VAlign", "HAlign", "VPosition"] : ["Valign", "Halign", "Vposition"];
        $row        = intdiv($cue->getAlignment()->value - 1, 3);
        $horizontal = ["left", "center", "right"][($cue->getAlignment()->value - 1) % 3];
        $attributes = [];
        for ($index = 0; $index < $count; $index++) {
            [$align, $position] = match ($row) {
                0       => ["bottom", self::EDGE + ($count - 1 - $index) * self::LINE_SPACING],
                2       => ["top", self::EDGE + $index * self::LINE_SPACING],
                default => ["center", ($index - ($count - 1) / 2) * self::LINE_SPACING],
            };
            $attributes[] = ($horizontal === "center" ? [] : [$hAlign => $horizontal]) + [$vAlign => $align, $vPosition => (string) $position];
        }

        return $attributes;
    }


    /**
     * Writes the text of a line, with a <Font> element for each run whose style differs from $font.
     *
     * @param array<string, string> $font
     */
    private function runs(string $line, string $prefix, bool $interop, array $font): string
    {
        $base   = $this->baseStyle($font);
        $style  = ["b" => 0, "i" => 0, "u" => 0];
        $colors = [];
        $output = "";
        foreach (Markup::splitTags(MarkupColors::webVttColorsToFont($line)) as $index => $token) {
            if ($index % 2 === 1) {
                $tag = strtolower($token);
                if (preg_match('/^<(\/?)([biu])>$/', $tag, $matches)) {
                    $style[$matches[2]] = max(0, $style[$matches[2]] + ($matches[1] === "" ? 1 : -1));
                } elseif (preg_match('/^<font\b/', $tag)) {
                    $colors[] = MarkupColors::fontColorHex($tag) ?? (end($colors) ?: null);
                } elseif ($tag === "</font>") {
                    array_pop($colors);
                }
                continue;
            }

            $text = Markup::decodeEntities($token);
            if ($text === "") {
                continue;
            }
            $color      = end($colors) ?: null;
            $attributes = [];
            if ($color !== null && strtolower(substr($color, 1)) !== $base["color"]) {
                $attributes["Color"] = "FF" . strtoupper(substr($color, 1));
            }
            if (($style["i"] > 0) !== $base["i"]) {
                $attributes["Italic"] = $style["i"] > 0 ? "yes" : "no";
            }
            if (($style["b"] > 0) !== $base["b"]) {
                $attributes["Weight"] = $style["b"] > 0 ? "bold" : "normal";
            }
            if (($style["u"] > 0) !== $base["u"]) {
                $attributes[$interop ? "Underlined" : "Underline"] = $style["u"] > 0 ? "yes" : "no";
            }
            $escaped = $this->escape($text);
            $output .= $attributes === [] ? $escaped : "<{$prefix}Font" . $this->attributes($attributes) . ">$escaped</{$prefix}Font>";
        }

        return $output;
    }


    /**
     * @param array<string, string> $font
     *
     * @return array{color: string, b: bool, i: bool, u: bool}
     */
    private function baseStyle(array $font): array
    {
        $font = array_change_key_case(array_map(fn (string $value): string => strtolower(trim($value)), $font), CASE_LOWER);

        return [
            "color" => preg_match('/^[0-9a-f]{2}([0-9a-f]{6})$/', $font["color"] ?? "", $matches) ? $matches[1] : "ffffff",
            "b"     => ($font["weight"] ?? "") === "bold",
            "i"     => in_array($font["italic"] ?? "", ["yes", "left", "right"], true),
            "u"     => ($font["underlined"] ?? $font["underline"] ?? "") === "yes",
        ];
    }


    /**
     * Writes HH:MM:SS:EE in $rate units per second. Interop writes 3 digits of 4 ms ticks.
     */
    private function time(float $seconds, float $rate, bool $interop): string
    {
        $units = (int) round($seconds * $rate);
        $whole = intdiv($units, (int) $rate);
        $width = $interop || $rate > 100 ? 3 : 2;

        return sprintf("%02d:%02d:%02d:%0{$width}d", intdiv($whole, 3600), intdiv($whole, 60) % 60, $whole % 60, $units % (int) $rate);
    }


    /**
     * @param array<string, string> $attributes
     */
    private function attributes(array $attributes): string
    {
        $output = "";
        foreach ($attributes as $name => $value) {
            $output .= " $name=\"" . $this->escape((string) $value) . "\"";
        }

        return $output;
    }


    private function escape(string $text): string
    {
        return htmlspecialchars(XmlOutput::text($text), ENT_XML1 | ENT_COMPAT, "UTF-8");
    }
}
