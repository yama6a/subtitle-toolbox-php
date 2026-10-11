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


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = $this->plainLines($cue);

            // An empty line ends an SBV cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $blocks[] = sprintf(self::TIME_PATTERN, ...Timecode::milliseconds($cue->getStart())) . "," .
                        sprintf(self::TIME_PATTERN, ...Timecode::milliseconds($cue->getEnd())) .
                        LineEnding::Lf->value .
                        implode(LineEnding::Lf->value, $lines) .
                        LineEnding::Lf->value;
        }

        return $this->applyOutputOptions(implode(LineEnding::Lf->value, $blocks), $options);
    }
}
