<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\AlignmentGrid;
use SubtitleToolbox\CuePosition;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
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

    private const ALIGNMENT_ROWS    = [AlignmentGrid::BOTTOM_ROW => "", AlignmentGrid::MIDDLE_ROW => "line:50%,center", AlignmentGrid::TOP_ROW => "line:0"];
    private const ALIGNMENT_COLUMNS = [AlignmentGrid::LEFT => "align:left", AlignmentGrid::CENTER => "", AlignmentGrid::RIGHT => "align:right"];
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

        $comments = $this->commentsByCue($subtitle);
        $cues     = array_values($subtitle->getCues());
        foreach ([...$cues, null] as $cueIndex => $cue) {
            foreach ($comments[$cueIndex] ?? [] as $comment) {
                $blocks[] = $this->formatComment($comment->text);
            }
            if ($cue !== null) {
                $blocks[] = $this->formatIdentifiedCue($cue, $cueIndex, $options);
            }
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
        return $this->formatIdentifier($cue->findIdentifier(), $cueIndex) . LineEnding::Lf->value
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
        while (str_contains($text, "-->")) {
            $text = str_replace("-->", "->", $text);
        }
        $text = StringHelpers::removeEmptyLines(StringHelpers::trimEachLine(StringHelpers::cleanString($text)));
        if ($text === "") {
            return "NOTE";
        }

        $separator = str_contains($text, LineEnding::Lf->value) ? LineEnding::Lf->value : " ";

        return "NOTE" . $separator . $text;
    }


    private function formatIdentifier(?string $identifier, int $cueIndex): string
    {
        if ($identifier === null || trim($identifier) === "" || str_contains($identifier, "-->")
            || str_contains($identifier, LineEnding::Lf->value) || str_contains($identifier, "\r")) {
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
            [$lines, $dropped] = MarkupColors::fontToWebVttColors($lines);
            foreach ($dropped as $color) {
                $this->droppedColors[] = new WebVttDroppedColor($cueIndex, $color, "Cue #$cueIndex at {$cue->getStart()} s: "
                    . "dropped the color \"$color\", because WebVTT has classes for 8 colors only.");
            }
            $lines = $this->dropEarlierWordTimestamps($this->keepVttTags($lines));
        }

        $lines = str_replace("-->", "--&gt;", $lines);

        return $lines === "" ? $timeStamps : $timeStamps . LineEnding::Lf->value . $lines;
    }


    /**
     * Leaves out each word timestamp that is earlier than the one before it, because the WebVTT cue text rules
     * need each timestamp to be later. The text after the left out timestamp joins the word before it.
     */
    private function dropEarlierWordTimestamps(string $text): string
    {
        $latest = null;

        return preg_replace_callback(
            Markup::WORD_TIMESTAMP_REGEX,
            function (array $match) use (&$latest): string {
                $seconds = Markup::wordTimestampSeconds($match[1]) ?? $latest ?? 0.0;

                return Markup::keepsWordTimeOrder($seconds, $latest) ? $match[1] : "";
            },
            $text
        ) ?? $text;
    }


    /**
     * Writes the stored settings while the alignment is unchanged. Else it writes the position of the cue when the
     * position lies in the cell of the alignment, and the settings of the alignment otherwise.
     */
    private function formatSettings(SubtitleCue $cue): string
    {
        $cueData   = $cue->findFormatData(WebVttParser::FORMAT_DATA_KEY);
        $alignment = $cue->getAlignment();
        $unchanged = ($cueData["alignment"] ?? Alignment::BottomCenter->value) === $alignment->value;
        $kept      = array_diff(WebVttParser::CUE_SETTINGS, self::POSITION_SETTINGS);
        $placed    = $unchanged && array_intersect(array_keys($cueData), self::POSITION_SETTINGS) !== [];
        $position  = $cue->findPosition();
        // A stored vertical setting swaps the axes of line and position, so the position cannot use them.
        if (!$placed && !isset($cueData["vertical"]) && $position !== null && AlignmentGrid::contains($alignment, $position)) {
            return implode(" ", [...$this->storedSettings($cueData, $kept), ...$this->positionSettings($alignment, $position)]);
        }

        $settings = $this->storedSettings($cueData, $unchanged ? WebVttParser::CUE_SETTINGS : $kept);
        if ($unchanged && $settings !== [] || $alignment === Alignment::BottomCenter) {
            return implode(" ", $settings);
        }

        $settings = [...$settings, self::ALIGNMENT_ROWS[AlignmentGrid::row($alignment)], self::ALIGNMENT_COLUMNS[AlignmentGrid::column($alignment)]];

        return implode(" ", array_filter($settings, fn (string $setting): bool => $setting !== ""));
    }


    /**
     * @return list<string> the stored settings in $names as "name:value", in stored order
     */
    private function storedSettings(array $cueData, array $names): array
    {
        $settings = [];
        foreach ($cueData as $name => $value) {
            if (in_array($name, $names, true)) {
                $settings[] = "$name:$value";
            }
        }

        return $settings;
    }


    /**
     * Returns the settings that put the point of the cue box that $alignment names at $position.
     * Only a bottom center cue gets an exact height, because WebVttParser reads every other line percentage as a
     * bottom center cue. The other rows keep the line setting of the alignment.
     *
     * @return list<string>
     */
    private function positionSettings(Alignment $alignment, CuePosition $position): array
    {
        $settings = [];
        $y        = AlignmentGrid::roundPercent($position->y);
        if ($alignment === Alignment::BottomCenter && $y !== 100.0) {
            $settings[] = "line:" . AlignmentGrid::formatPercent($y) . "%,end";
        } elseif (self::ALIGNMENT_ROWS[AlignmentGrid::row($alignment)] !== "") {
            $settings[] = self::ALIGNMENT_ROWS[AlignmentGrid::row($alignment)];
        }

        $column = AlignmentGrid::column($alignment);
        $x      = AlignmentGrid::roundPercent($position->x);
        if ($x !== [0.0, 50.0, 100.0][$column - 1]) {
            $settings[] = "position:" . AlignmentGrid::formatPercent($x) . "%," . ["line-left", "center", "line-right"][$column - 1];
        }

        if (self::ALIGNMENT_COLUMNS[$column] !== "") {
            $settings[] = self::ALIGNMENT_COLUMNS[$column];
        }

        return $settings;
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
