<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class KaraokeEdit extends Edit
{
    private function __construct(private readonly WordHighlightOptions $options)
    {
    }


    public static function group(): string
    {
        return "karaoke";
    }


    public static function summary(): string
    {
        return "Write one cue per word timestamp.";
    }


    public static function options(): array
    {
        return [
            Option::flag("karaoke", "Write one cue per word timestamp, with the active word styled, for players without karaoke."),
            Option::value("karaoke-style", "TAG", "Style of the active word for --karaoke: b, i, u, s or 'font color=\"#ffff00\"'. Default: u."),
        ];
    }


    public static function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("karaoke");
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "karaoke", ["karaoke-style"]);
        if (!$arguments->has("karaoke")) {
            return null;
        }

        return new self(new WordHighlightOptions(...Command::withoutNulls(["style" => $arguments->value("karaoke-style")])));
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        WordHighlight::apply($subtitle, $this->options);

        return $subtitle;
    }
}
