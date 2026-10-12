<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class TextEdit extends Edit
{
    private function __construct(
        private readonly bool $stripTags,
        private readonly ?CaseMode $case,
        private readonly ?string $language,
        private readonly ?SpeakerLabelOptions $speakers,
        private readonly ?string $rtl,
    ) {
    }


    public static function group(): string
    {
        return "text";
    }


    public static function summary(): string
    {
        return "Convert speaker labels, change the case, remove tags, fix right-to-left text.";
    }


    public static function options(): array
    {
        return [
            Option::value("speakers", "MODE", "Convert <v> speaker tags: prefix (ANNA: Hi), dashes, colors, or from-prefix (ANNA: to <v Anna>)."),
            Option::value("case", "MODE", "Change the case of the text between tags: upper, lower or sentence."),
            Option::flag("strip-tags", "Remove all formatting tags, such as <i> and <font>, from the cue text."),
            Option::value("rtl", "MODE", "Right-to-left text: fix wraps each Arabic or Hebrew line in Unicode embedding marks, clean removes all bidi controls."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        $case     = $arguments->choice("case", array_column(CaseMode::cases(), "value"));
        $speakers = $arguments->choice("speakers", array_keys(self::speakerModes()));
        $rtl      = $arguments->choice("rtl", ["fix", "clean"]);
        if (!$arguments->has("strip-tags") && $case === null && $speakers === null && $rtl === null) {
            return null;
        }

        return new self($arguments->has("strip-tags"), $case === null ? null : CaseMode::from($case), $arguments->value("language"),
                        $speakers === null ? null : self::speakerModes()[$speakers], $rtl);
    }


    /**
     * @return array<string, SpeakerLabelOptions> the values of --speakers
     */
    private static function speakerModes(): array
    {
        return [
            "prefix"      => new SpeakerLabelOptions(style: SpeakerStyle::Prefix),
            "dashes"      => new SpeakerLabelOptions(style: SpeakerStyle::DialogueDashes),
            "colors"      => new SpeakerLabelOptions(style: SpeakerStyle::Colors),
            "from-prefix" => new SpeakerLabelOptions(speakerLabels: true),
        ];
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->speakers !== null) {
            SpeakerLabels::apply($subtitle, $this->speakers);
        }
        if ($this->case !== null) {
            $subtitle->changeCase($this->case, $this->language);
        }
        if ($this->stripTags) {
            $subtitle->stripFormatting();
        }
        if ($this->rtl === "fix") {
            $subtitle->fixRightToLeft();
        } elseif ($this->rtl === "clean") {
            $subtitle->removeBidiControls();
        }

        return $subtitle;
    }
}
