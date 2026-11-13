<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class SbvFormatter extends SubtitleFormatter
{
    private const TIME_PATTERN = "%d:%02d:%02d.%03d";

    // What the parser reads as a timing line in strict or lenient mode.
    private const TIMING_LINE = '/^\s*(?:\d+:\d\d:\d\d\.\d+,|\d+:[0-5]?\d:[0-5]?\d[.,:]\d{1,4}\s*[.,]\s*\d+:[0-5]?\d:[0-5]?\d[.,:]\d{1,4}\s*$)/';


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = self::splitAtLineFeeds($this->plainLines($cue));

            // An empty line ends an SBV cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $blocks[] = sprintf(self::TIME_PATTERN, ...Timecode::milliseconds($cue->getStart())) . "," .
                        sprintf(self::TIME_PATTERN, ...Timecode::milliseconds($cue->getEnd())) .
                        LineEnding::Lf->value .
                        implode(LineEnding::Lf->value, array_map(fn(string $line) => self::keepAsText($line, self::TIMING_LINE), $lines)) .
                        LineEnding::Lf->value;
        }

        return $this->applyOutputOptions(implode(LineEnding::Lf->value, $blocks), $options);
    }
}
