<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SpeakerLabelOptions
{
    /** @var list<string> */
    public readonly array $colors;


    /**
     * @param bool                  $speakerLabels              turn a label such as "JOHN: " at the start of a line, or after its dialogue dash, into <v John>
     * @param ?SpeakerStyle         $style                      write the <v> tags in this style
     * @param array<string, string> $rename                     new names for the speakers of the <v> tags, for example ["SPEAKER_00" => "Anna"]
     * @param bool                  $writeUpperCase             write the names of SpeakerStyle::Prefix in upper case
     * @param string                $separator                  the text after the name of SpeakerStyle::Prefix
     * @param DialogueDashStyle     $dialogueDashStyle          the dash of SpeakerStyle::DialogueDashes
     * @param list<string>          $colors                     the colors of SpeakerStyle::Colors. After the last color, the list starts again
     * @param bool                  $speakerLabelsUpperCaseOnly read only labels in upper case with $speakerLabels
     */
    public function __construct(
        public readonly bool $speakerLabels = false,
        public readonly ?SpeakerStyle $style = null,
        public readonly array $rename = [],
        public readonly bool $writeUpperCase = true,
        public readonly string $separator = ": ",
        public readonly DialogueDashStyle $dialogueDashStyle = DialogueDashStyle::HyphenSpace,
        array $colors = SpeakerLabels::BBC_COLORS,
        public readonly bool $speakerLabelsUpperCaseOnly = true,
    ) {
        $colors = array_values($colors);
        $valid  = array_filter($colors, fn (mixed $color): bool =>
            is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1);
        if ($colors === [] || count($valid) !== count($colors)) {
            throw new InvalidArgumentException("The speaker colors must be a non-empty list of colors such as \"#ffff00\".");
        }
        $this->colors = $colors;
    }
}
