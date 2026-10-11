<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class WebVttFormatter extends SubtitleFormatter
{
    protected const DEFAULT_BOM = true;

    private const INLINE_TIMESTAMP_PATTERN = "/(<(?:\d{2,}:)?[0-5]\d:[0-5]\d\.\d{3}>)/";

    private const SPAN_TAGS = ["strong", "b", "u", "i", "v", "lang", "c", "ruby", "rt"];

    private const ALIGNMENT_ROWS    = [0 => "", 1 => "line:50%,center", 2 => "line:0"];
    private const ALIGNMENT_COLUMNS = [1 => "align:left", 2 => "", 3 => "align:right"];
    private const POSITION_SETTINGS = ["line", "position", "align"];

    /** @var list<WebVttDroppedColor> */
    private array $droppedColors = [];


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        return $this->formatWithReport($subtitle, $options)->content;
    }


    /**
     * Returns the output of format() and each <font color> that no WebVTT color class has, so the output drops it.
     */
    public function formatWithReport(Subtitle $subtitle, ?WriteOptions $options = null): WebVttWriteReport
    {
        $options ??= new WriteOptions();
        $this->droppedColors = [];
        $fileData = $subtitle->findFormatData(WebVttParser::FORMAT_DATA_KEY);
        $header   = "WEBVTT";
        if (($fileData["header"] ?? "") !== "") {
            $header .= " " . $fileData["header"];
        }
        foreach ($fileData["headerLines"] ?? [] as $line) {
            $header .= LineEnding::Lf->value . $line;
        }

        $blocks = [];
        foreach ($fileData["regions"] ?? [] as $region) {
            $blocks[] = $this->formatRegion($region);
        }
        foreach ($fileData["styles"] ?? [] as $style) {
            $blocks[] = "STYLE" . LineEnding::Lf->value . $style;
        }

        $comments = $subtitle->getComments();
        foreach (array_values($subtitle->getCues()) as $cueIndex => $cue) {
            while ($comments !== [] && $comments[0]->beforeCueIndex <= $cueIndex) {
                $blocks[] = $this->formatComment(array_shift($comments)->text);
            }
            $blocks[] = $this->formatIdentifiedCue($cue, $cueIndex, $options);
        }
        foreach ($comments as $comment) {
            $blocks[] = $this->formatComment($comment->text);
        }

        $output = $header . LineEnding::Lf->value . LineEnding::Lf->value;
        if ($blocks !== []) {
            $output .= implode(LineEnding::Lf->value . LineEnding::Lf->value, $blocks)
                       . LineEnding::Lf->value;
        }

        return new WebVttWriteReport($this->applyOutputOptions($output, $options), $this->droppedColors);
    }


    /**
     * Returns the cue block format() writes for the cue at $cueIndex, in the line ending of $options, without a BOM.
     *
     * @internal
     */
    public function formatCueBlock(SubtitleCue $cue, int $cueIndex, WriteOptions $options = new WriteOptions()): string
    {
        $block = $this->formatIdentifiedCue($cue, $cueIndex, $options);

        return $this->applyLineEnding($block, $options);
    }


    /**
     * Returns the colors that formatCueBlock() dropped since the last call, and forgets them.
     *
     * @internal
     *
     * @return list<WebVttDroppedColor>
     */
    public function takeDroppedColors(): array
    {
        $dropped             = $this->droppedColors;
        $this->droppedColors = [];

        return $dropped;
    }


    private function formatIdentifiedCue(SubtitleCue $cue, int $cueIndex, WriteOptions $options): string
    {
        return $this->formatIdentifier($cue->getIdentifier(), $cueIndex) . LineEnding::Lf->value
               . $this->formatCue($cue, $cueIndex, $options);
    }


    private function formatRegion(array $region): string
    {
        $lines = ["REGION"];
        foreach ($region as $name => $value) {
            $lines[] = "$name:$value";
        }

        return implode(LineEnding::Lf->value, $lines);
    }


    /**
     * @see https://www.w3.org/TR/webvtt1/#webvtt-comment-block
     */
    private function formatComment(string $text): string
    {
        $text = StringHelpers::cleanString(str_replace("-->", "->", $text));
        if ($text === "") {
            return "NOTE";
        }

        $separator = str_contains($text, LineEnding::Lf->value) ? LineEnding::Lf->value : " ";

        return "NOTE" . $separator . $text;
    }


    private function formatIdentifier(?string $identifier, int $cueIndex): string
    {
        if ($identifier === null || trim($identifier) === "" || str_contains($identifier, "-->")
            || str_contains($identifier, LineEnding::Lf->value)) {
            return (string) ($cueIndex + 1);
        }

        return $identifier;
    }


    private function formatCue(SubtitleCue $cue, int $cueIndex, WriteOptions $options): string
    {
        $timeStamps = Markup::coreTimestamp($cue->getStart()) . " --> " . Markup::coreTimestamp($cue->getEnd());
        $settings   = $this->formatSettings($cue);
        if ($settings !== "") {
            $timeStamps .= " " . $settings;
        }

        $lines = implode(LineEnding::Lf->value, $cue->getLines());
        if ($options->stripTags) {
            $lines = Markup::stripAllTags($lines);
        } else {
            [$lines, $dropped] = Markup::fontToWebVttColors($lines);
            foreach ($dropped as $color) {
                $this->droppedColors[] = new WebVttDroppedColor($cueIndex, $color, "Cue #$cueIndex at {$cue->getStart()} s: "
                    . "dropped the color \"$color\", because WebVTT has classes for 8 colors only.");
            }
            $lines = $this->keepVttTags($lines);
        }

        $lines = str_replace("-->", "--&gt;", $lines);

        return $lines === "" ? $timeStamps : $timeStamps . LineEnding::Lf->value . $lines;
    }


    private function formatSettings(SubtitleCue $cue): string
    {
        $stored    = $cue->findFormatData(WebVttParser::FORMAT_DATA_KEY);
        $unchanged = ($stored["alignment"] ?? Alignment::BottomCenter->value) === $cue->getAlignment()->value;
        $names     = $unchanged ? WebVttParser::CUE_SETTINGS : array_diff(WebVttParser::CUE_SETTINGS, self::POSITION_SETTINGS);
        $settings  = [];
        foreach ($stored as $name => $value) {
            if (in_array($name, $names, true)) {
                $settings[] = "$name:$value";
            }
        }
        if ($unchanged && $settings !== [] || $cue->getAlignment() === Alignment::BottomCenter) {
            return implode(" ", $settings);
        }

        $alignment = $cue->getAlignment()->value - 1;
        $settings  = [...$settings, self::ALIGNMENT_ROWS[intdiv($alignment, 3)], self::ALIGNMENT_COLUMNS[$alignment % 3 + 1]];

        return implode(" ", array_filter($settings, fn (string $setting): bool => $setting !== ""));
    }


    private function keepVttTags(string $text): string
    {
        $parts = preg_split(self::INLINE_TIMESTAMP_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = Markup::keepTags($part, $this->spanTagNamesWithClasses($part));
            }
        }

        return implode("", $parts);
    }


    /**
     * Markup::keepTags() compares the whole name before the first space, so "c.yellow" must be allowed next to "c".
     *
     * @see https://www.w3.org/TR/webvtt1/#webvtt-cue-span-start-tag
     */
    private function spanTagNamesWithClasses(string $text): array
    {
        preg_match_all("/<\/?((?:" . implode("|", self::SPAN_TAGS) . ")\.[^\s>]*)/i", $text, $matches);

        $namesWithClasses = array_map(fn (string $name): string => rtrim($name, "/"), $matches[1]);

        return array_values(array_unique([...self::SPAN_TAGS, ...$namesWithClasses]));
    }
}
