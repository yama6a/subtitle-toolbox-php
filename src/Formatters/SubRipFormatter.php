<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class SubRipFormatter extends SubtitleFormatter
{
    protected const DEFAULT_BOM = true;

    private const TIME_PATTERN = "%02d:%02d:%02d,%03d";

    // The parser reads 3 hour digits, so 999:59:59,999 is the last time that it reads back.
    private const LAST_SECONDS = 3599999.999;

    // What the parser in lenient mode reads as a timing line: a time, then "-->" or a shorter or longer arrow.
    private const TIMING_LINE = '/^\s*\d+(?::|：)\d{1,2}\S*?\s*-+>/';


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $output = "";
        foreach (array_values($subtitle->getCues()) as $cueIndex => $cue) {
            $output .= $this->formatNumberedCue($cue, $cueIndex, $options);
        }

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Returns what format() writes for the cue at $cueIndex, in the line ending of $options, without a BOM.
     *
     * @internal
     */
    public function formatCueBlock(SubtitleCue $cue, int $cueIndex, WriteOptions $options = new WriteOptions()): string
    {
        $block = $this->formatNumberedCue($cue, $cueIndex, $options);

        return $this->applyLineEnding($block, $options);
    }


    private function formatNumberedCue(SubtitleCue $cue, int $cueIndex, WriteOptions $options): string
    {
        $output = "";
        if ($cueIndex > 0) {
            $output .= LineEnding::Lf->value;
        }
        $output .= $cueIndex + 1 . LineEnding::Lf->value;
        $output .= $this->formatCue($cue, $options);
        $output .= LineEnding::Lf->value;

        return $output;
    }


    private function formatCue(SubtitleCue $cue, WriteOptions $options): string
    {
        $time  = sprintf(self::TIME_PATTERN, ...Timecode::milliseconds(min($cue->getStart(), self::LAST_SECONDS))) . " --> "
                 . sprintf(self::TIME_PATTERN, ...Timecode::milliseconds(min($cue->getEnd(), self::LAST_SECONDS)));
        $time .= $this->formatCoordinates($cue);
        $lines = Markup::rubyAsText(implode(LineEnding::Lf->value, $cue->getLines()));

        $lines = $options->stripTags
            ? Markup::stripAllTags($lines)
            : Markup::keepTags(MarkupColors::webVttColorsToFont($lines), ["b", "u", "i", "s", "font"]);
        $lines = Markup::decodeEntities($lines);

        // A line that holds only a tag becomes empty, and an empty line ends the cue in SubRip.
        $lines = array_values(array_filter(explode(LineEnding::Lf->value, $lines), fn(string $line) => trim($line) !== ""));

        if ($cue->getAlignment() !== Alignment::BottomCenter) {
            $lines[0] = "{\\an{$cue->getAlignment()->value}}" . ($lines[0] ?? "");
        }
        $lines = implode(LineEnding::Lf->value, array_map(fn(string $line) => self::keepAsText($line, self::TIMING_LINE), $lines));

        return $lines === "" ? $time : $time . LineEnding::Lf->value . $lines;
    }


    private function formatCoordinates(SubtitleCue $cue): string
    {
        $coordinates = $cue->findFormatData(SubRipParser::FORMAT_DATA_KEY)["coordinates"] ?? null;
        if (!is_array($coordinates)) {
            return "";
        }

        return " X1:{$coordinates["x1"]} X2:{$coordinates["x2"]} Y1:{$coordinates["y1"]} Y2:{$coordinates["y2"]}";
    }
}
