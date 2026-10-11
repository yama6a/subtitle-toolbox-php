<?php

declare(strict_types=1);

namespace SubtitleToolbox\Dual;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\OptionChecks;

final class DualSubtitleOptions
{
    /**
     * @param DualSubtitleMode $mode               how the secondary cues join the primary cues
     * @param float            $snapTolerance      the seconds within which a secondary time moves to the closest primary time in TopBottom mode
     * @param ?string          $secondaryStyle     a core markup tag around each secondary line, for example "i" or "font color=\"#ffff00\""
     * @param Alignment        $secondaryAlignment the alignment of the secondary cues in TopBottom mode
     */
    public function __construct(
        public readonly DualSubtitleMode $mode = DualSubtitleMode::Stack,
        public readonly float $snapTolerance = 0.25,
        public readonly ?string $secondaryStyle = null,
        public readonly Alignment $secondaryAlignment = Alignment::TopCenter,
    ) {
        OptionChecks::nonNegativeFinite($snapTolerance, "The snap tolerance %s must not be negative.");

        if ($secondaryStyle !== null && !in_array($this->getSecondaryTagName(), Markup::CORE_TAGS, true)) {
            throw new InvalidArgumentException("The secondary style $secondaryStyle must be a core markup tag " .
                                               "such as i or font color=\"#ffff00\".");
        }
    }


    /**
     * Returns the tag name of the secondary style, for example "font" for font color="#ffff00", or null.
     *
     * @internal
     */
    public function getSecondaryTagName(): ?string
    {
        if ($this->secondaryStyle === null) {
            return null;
        }

        return Markup::tagName($this->secondaryStyle);
    }
}
