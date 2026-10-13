<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMElement;
use DOMText;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\XmlLoader;

/**
 * Reads the text subtitles of digital cinema packages: Interop <DCSubtitle> and SMPTE ST 428-7 <SubtitleReel>.
 * Each <Text> element is one line. Nested <Font> elements inherit the attributes of the outer ones.
 *
 * @see https://cinepedia.com/interop-docs/Reference_Documents/CineCanvas(tm)_RevC.pdf
 * @see https://pub.smpte.org/doc/st428-7/20140211-pub/st0428-7-2014.pdf
 */
final class DcpParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Dcp->value;

    private const ROOT_PATTERN = '(?:DCSubtitle|(?:[A-Za-z_][\w.-]*:)?SubtitleReel)';

    // Interop counts the last field of HH:MM:SS:TTT in ticks of 4 ms.
    private const INTEROP_TICKS = 250;

    // The default of SMPTE ST 428-7 for a missing EditRate.
    private const DEFAULT_RATE = 24;

    private const WHITE = "ffffff";


    protected function read(string $content): Subtitle
    {
        $repaired = XmlLoader::skipLeadingText($content, self::ROOT_PATTERN, $skipped);
        if ($skipped !== "") {
            $this->skipTextBeforeXml($content, $skipped);
        }
        $root = XmlLoader::xml($repaired)?->documentElement ?? throw new ParsingException("The content is not well-formed XML.");

        $interop = $root->localName === "DCSubtitle";
        if (!$interop && $root->localName !== "SubtitleReel") {
            throw new ParsingException("The root element must be <DCSubtitle> or <SubtitleReel>, not <{$root->nodeName}>.");
        }

        $subtitle = new Subtitle();
        $title    = $this->childText($root, $interop ? "MovieTitle" : "ContentTitleText");
        if ($title !== null && $title !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, $title);
        }
        // Interop names the language in words, such as "English". SMPTE gives a language tag, such as "de".
        $language = $interop ? null : $this->childText($root, "Language");
        if ($language !== null && $language !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
        }

        $rate     = $interop ? self::INTEROP_TICKS : $this->timeCodeRate($root);
        $fileData = ["dialect" => $interop ? "interop" : "smpte"];
        if (!$interop) {
            $fileData += ["namespace" => $root->namespaceURI ?? "", "timeCodeRate" => $rate];
            $startTime = $this->childText($root, "StartTime");
            if ($startTime !== null) {
                $fileData["startTime"] = $startTime;
            }
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData);

        $cues = [];
        $this->readElement($root, [], $rate, $interop, $cues);

        return $subtitle->addCues($cues);
    }


    /**
     * Collects the cues of the <Subtitle> elements under $element, with the font attributes of the <Font> elements around them.
     *
     * @param array<string, string> $font
     * @param list<SubtitleCue>     $cues
     */
    private function readElement(DOMElement $element, array $font, float $rate, bool $interop, array &$cues): void
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === "Font") {
                $this->readElement($child, $this->font($child, $font), $rate, $interop, $cues);
            } elseif ($child->localName === "Subtitle") {
                try {
                    $cue = $this->cue($child, $font, $rate, $interop);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $child->getLineNo(), null, []);
                    continue;
                }
                if ($cue !== null) {
                    $cues[] = $cue;
                }
            } elseif ($child->localName === "SubtitleList") {
                $this->readElement($child, $font, $rate, $interop, $cues);
            }
        }
    }


    /**
     * @param array<string, string> $font
     */
    private function cue(DOMElement $subtitle, array $font, float $rate, bool $interop): ?SubtitleCue
    {
        $lineNumber = $subtitle->getLineNo();
        $start      = $this->time($subtitle->getAttribute("TimeIn"), $rate, $interop, $lineNumber);
        $end        = $this->time($subtitle->getAttribute("TimeOut"), $rate, $interop, $lineNumber);
        [$start, $end] = $this->orderedTimes($start, $end, $lineNumber, null, []);

        $lines = [];
        $this->collectTexts($subtitle, $font, $lines);
        if ($lines === []) {
            if ($this->findElement($subtitle, "Image") !== null) {
                throw new ParsingException("The subtitle at " . $subtitle->getAttribute("TimeIn") . " is an image. The parser reads only text subtitles.", $lineNumber);
            }

            return null;
        }

        usort($lines, fn (array $a, array $b): int => $a["y"] <=> $b["y"]);
        $text = array_values(array_filter(array_column($lines, "text"), fn (string $line): bool => $line !== ""));
        if ($text === []) {
            return null;
        }

        return (new SubtitleCue($start, $end, $text))->setAlignment($this->alignment($lines));
    }


    /**
     * Collects each <Text> element under $element as a line with its markup and its height on the screen, from 0 at the top to 100 at the bottom.
     *
     * @param array<string, string>                                           $font
     * @param list<array{text: string, y: float, horizontal: string}> $lines
     */
    private function collectTexts(DOMElement $element, array $font, array &$lines): void
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === "Font") {
                $this->collectTexts($child, $this->font($child, $font), $lines);
            } elseif ($child->localName === "Text") {
                $runs = [];
                $this->collectRuns($child, $font, $runs);
                $lines[] = [
                    "text"       => trim(StyleRuns::toMarkup($runs, true)),
                    "y"          => $this->screenY($child),
                    "horizontal" => strtolower($this->position($child, "HAlign") ?? "center"),
                ];
            }
        }
    }


    /**
     * @param array<string, string>                   $font
     * @param list<array{string, array<string, mixed>}> $runs
     */
    private function collectRuns(DOMElement $element, array $font, array &$runs): void
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                // Line breaks and tabs inside <Text> are not shown on the screen.
                $text = str_replace(["\r", "\n", "\t"], "", $child->data);
                if ($text !== "") {
                    $runs[] = [$text, $this->style($font)];
                }
            } elseif ($child instanceof DOMElement && $child->localName === "Font") {
                $this->collectRuns($child, $this->font($child, $font), $runs);
            } elseif ($child instanceof DOMElement && $child->localName === "Space") {
                $runs[] = [" ", $this->style($font)];
            } elseif ($child instanceof DOMElement && $child->localName === "Ruby") {
                $base       = $this->childText($child, "Rb") ?? "";
                $annotation = $this->childText($child, "Rt") ?? "";
                $runs[]     = [$annotation === "" ? $base : "$base ($annotation)", $this->style($font)];
            } elseif ($child instanceof DOMElement) {
                $this->collectRuns($child, $font, $runs);
            }
        }
    }


    /**
     * Returns $font with the attributes of the <Font> element $element. Attribute names are lower case, so Interop
     * Underlined and SMPTE Underline both count.
     *
     * @param array<string, string> $font
     *
     * @return array<string, string>
     */
    private function font(DOMElement $element, array $font): array
    {
        foreach ($element->attributes as $attribute) {
            $font[strtolower($attribute->name)] = strtolower(trim($attribute->value));
        }

        return $font;
    }


    /**
     * @param array<string, string> $font
     *
     * @return array{color: ?string, b: bool, i: bool, u: bool}
     */
    private function style(array $font): array
    {
        $color = preg_match('/^[0-9a-f]{2}([0-9a-f]{6})$/', $font["color"] ?? "", $matches) && $matches[1] !== self::WHITE ? "#" . $matches[1] : null;

        return [
            "color" => $color,
            "b"     => ($font["weight"] ?? "") === "bold",
            // SMPTE ST 428-7:2014 adds "left" and "right" for the slant direction.
            "i"     => in_array($font["italic"] ?? "", ["yes", "left", "right"], true),
            "u"     => ($font["underlined"] ?? $font["underline"] ?? "") === "yes",
        ];
    }


    /**
     * Returns the height of the line on the screen in percent, from 0 at the top to 100 at the bottom.
     * VPosition counts from the edge that VAlign names, or down from the middle for "center", the default.
     */
    private function screenY(DOMElement $text): float
    {
        $position = (float) ($this->position($text, "VPosition") ?? 0);

        return match (strtolower($this->position($text, "VAlign") ?? "center")) {
            "top"    => $position,
            "bottom" => 100 - $position,
            default  => 50 + $position,
        };
    }


    /**
     * Returns the alignment of the middle of the lines, by the third of the screen it is in, and of the first line's HAlign.
     *
     * @param list<array{text: string, y: float, horizontal: string}> $lines
     */
    private function alignment(array $lines): Alignment
    {
        $y          = array_sum(array_column($lines, "y")) / count($lines);
        $horizontal = $lines[0]["horizontal"];
        $column     = $horizontal === "left" ? 0 : ($horizontal === "right" ? 2 : 1);
        $row        = $y < 100 / 3 ? 7 : ($y > 200 / 3 ? 1 : 4);

        return Alignment::from($row + $column);
    }


    /**
     * Returns the position attribute $name of a <Text> element. Interop writes VAlign, SMPTE writes Valign.
     */
    private function position(DOMElement $element, string $name): ?string
    {
        foreach ([$name, ucfirst(strtolower($name))] as $spelling) {
            if ($element->hasAttribute($spelling)) {
                return trim($element->getAttribute($spelling));
            }
        }

        return null;
    }


    /**
     * Reads HH:MM:SS:EE, where EE counts $rate units per second, or Interop HH:MM:SS.sss with a decimal fraction.
     */
    private function time(string $value, float $rate, bool $interop, int $lineNumber): float
    {
        $value = trim($value);
        if ($interop && preg_match('/^(\d+):(\d{1,2}):(\d{1,2})\.(\d+)$/', $value, $matches)) {
            $seconds = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3] + (float) "0.$matches[4]";
        } elseif (preg_match('/^(\d+):(\d{1,2}):(\d{1,2}):(\d{1,3})$/', $value, $matches) && (int) $matches[4] < $rate) {
            $seconds = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3] + (int) $matches[4] / $rate;
        } else {
            throw new ParsingException("The time \"$value\" is not a DCP time code at $rate units per second.", $lineNumber);
        }

        return self::boundedTime(Timecode::roundToMilliseconds($seconds), $value, $lineNumber);
    }


    /**
     * Returns the TimeCodeRate of a SMPTE file, else its EditRate rounded, else 24.
     */
    private function timeCodeRate(DOMElement $root): float
    {
        $timeCodeRate = $this->childText($root, "TimeCodeRate");
        if ($timeCodeRate !== null && preg_match('/^\d+$/D', $timeCodeRate) && (int) $timeCodeRate > 0) {
            return (float) $timeCodeRate;
        }
        if (preg_match('/^(\d+)\s+(\d+)$/D', $this->childText($root, "EditRate") ?? "", $matches) && (int) $matches[1] > 0 && (int) $matches[2] > 0) {
            return (float) round((int) $matches[1] / (int) $matches[2]);
        }

        return self::DEFAULT_RATE;
    }


    private function childText(DOMElement $element, string $name): ?string
    {
        $child = $this->findElement($element, $name, false);

        return $child === null ? null : trim($child->textContent);
    }


    private function findElement(DOMElement $element, string $name, bool $deep = true): ?DOMElement
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === $name) {
                return $child;
            }
            if ($deep && ($found = $this->findElement($child, $name)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
