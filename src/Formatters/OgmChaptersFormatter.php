<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class OgmChaptersFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $output = "";
        foreach (array_values($subtitle->getCues()) as $index => $cue) {
            $number  = sprintf("CHAPTER%02d", $index + 1);
            $output .= "$number=" . Markup::coreTimestamp($cue->getStart()) . LineEnding::Lf->value .
                       "{$number}NAME=" . implode(" ", $this->plainLines($cue)) . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($output, $options);
    }
}
