<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class MicroDvdFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = MicroDvdWriteOptions::class;

    private const STYLE_TAGS = ["b", "i", "u", "s"];


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $formatOptions = $this->formatOptions($options);
        $frameRate     = new FrameRate($formatOptions->frameRate
            ?? $subtitle->findFormatData(MicroDvdParser::FORMAT_DATA_KEY)["frameRate"]
            ?? throw new InvalidArgumentException("The MicroDVD formatter needs a frame rate. Set MicroDvdWriteOptions::\$frameRate."));
        $stripTags     = $options->stripTags;

        $output = "";
        if ($formatOptions->writeFrameRateLine) {
            $output .= "{1}{1}" . $frameRate->getFramesPerSecond() . LineEnding::Lf->value;
        }

        foreach ($subtitle->getCues() as $cue) {
            $output .= "{" . $frameRate->secondsToFrames(max(0.0, $cue->getStart())) . "}" .
                       "{" . $frameRate->secondsToFrames(max(0.0, $cue->getEnd())) . "}" .
                       $this->formatText($cue, $stripTags) .
                       LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($output, $options);
    }


    private function formatText(SubtitleCue $cue, bool $stripTags): string
    {
        $storedLines = $cue->findFormatData(MicroDvdParser::FORMAT_DATA_KEY)["lines"] ?? [];
        $lines       = array_map(fn (string $line): array => $this->readLine(MarkupColors::webVttColorsToFont($line)), array_map(Markup::rubyAsText(...), $cue->getLines()));

        $keepStoredCodes = !$stripTags && count($storedLines) === count($lines);
        foreach ($lines as $index => $line) {
            $keepStoredCodes = $keepStoredCodes &&
                               $line["color"] === $storedLines[$index]["color"] &&
                               $line["tags"] === $storedLines[$index]["tags"];
        }

        $texts = [];
        foreach ($lines as $index => $line) {
            if ($keepStoredCodes) {
                $texts[] = $storedLines[$index]["codes"] . $line["text"];
                continue;
            }

            $codes = "";
            if (!$stripTags) {
                $codes .= $line["color"] === null ? "" : "{c:$" . strtoupper(MarkupColors::rgbToBgr(substr($line["color"], 1))) . "}";
                $codes .= implode("", array_map(fn (string $tag): string => "{y:$tag}", $line["tags"]));
            }
            $texts[] = $codes . ($storedLines[$index]["otherCodes"] ?? "") . $line["text"];
        }

        return implode("|", $texts);
    }


    /**
     * Splits a cue line into the core markup tags that wrap the whole line and the plain text inside them.
     *
     * @return array{color: ?string, tags: list<string>, text: string}
     */
    private function readLine(string $line): array
    {
        $color = null;
        $tags  = [];
        while (true) {
            if (preg_match('/^<([bius])>(.*)<\/\1>$/s', $line, $matches) && !preg_match("/<\/?$matches[1]>/", $matches[2])) {
                $tags[] = $matches[1];
                $line   = $matches[2];
            } elseif ($color === null &&
                      preg_match('/^<font\b([^>]*)>(.*)<\/font>$/si', $line, $matches) &&
                      preg_match('/^#[0-9a-fA-F]{6}$/', trim(MarkupColors::fontColor($matches[1]) ?? ""), $hex) &&
                      !preg_match('/<\/?font\b/i', $matches[2])) {
                $color = strtolower($hex[0]);
                $line  = $matches[2];
            } else {
                break;
            }
        }

        return [
            "color" => $color,
            "tags"  => array_values(array_intersect(self::STYLE_TAGS, $tags)),
            "text"  => Markup::plainText($line),
        ];
    }
}
