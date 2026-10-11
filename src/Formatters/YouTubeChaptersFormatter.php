<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class YouTubeChaptersFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $output = "";
        foreach ($subtitle->getCues() as $cue) {
            $start   = Timecode::shortClock($cue->getStart());
            $output .= rtrim($start . " " . implode(" ", $this->plainLines($cue))) . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($output, $options);
    }
}
