<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class TmPlayerFormatter extends SubtitleFormatter
{
    private const TIME_PATTERN = "%02d:%02d:%02d:";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $cues = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = $this->plainLines($cue);
            // A line without text would end the cue before it.
            if ($lines !== []) {
                $cues[] = [$cue, $lines];
            }
        }

        $output = "";
        foreach ($cues as $index => [$cue, $lines]) {
            $start   = Timecode::unitsBelowLimit($cue->getStart(), 1);
            $output .= sprintf(self::TIME_PATTERN, ...Timecode::seconds($start)) . implode("|", $lines) . LineEnding::Lf->value;

            if (!isset($cues[$index + 1])) {
                continue;
            }
            // TMPlayer has no end times. An entry without text hides the cue before the next one starts.
            $end = max(Timecode::unitsBelowLimit($cue->getEnd(), 1), $start + 1);
            if ($end < Timecode::unitsBelowLimit($cues[$index + 1][0]->getStart(), 1)) {
                $output .= sprintf(self::TIME_PATTERN, ...Timecode::seconds($end)) . LineEnding::Lf->value;
            }
        }

        return $this->applyOutputOptions($output, $options);
    }
}
