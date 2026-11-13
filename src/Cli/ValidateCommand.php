<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Format;
use SubtitleToolbox\OptionsCopy;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\Validation\ValidationRules;

/**
 * @internal
 */
final class ValidateCommand extends ReportCommand
{
    public const DEFAULT_FPS = 23.976;

    private const FLAGS = [
        "check-overlaps"                   => "noOverlap",
        "check-empty-cues"                 => "noEmptyCues",
        "check-double-spaces"              => "noDoubleSpaces",
        "check-leading-or-trailing-spaces" => "noLeadingOrTrailingSpaces",
        "check-unbalanced-tags"            => "noUnbalancedTags",
        "check-all-caps-lines"             => "noAllCapsLines",
    ];

    private ?ValidationRules $rules = null;

    private int $withProblems = 0;


    public function name(): string
    {
        return "validate";
    }


    public function summary(): string
    {
        return "Check the cues against reading speed, line length, timing and text rules.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --preset netflix-en|bbc [options]", "<input>... [--max-cpl CHARS] [--check-overlaps] [...] [options]"];
    }


    protected function details(): string
    {
        return "Prints one line per broken rule. Cue numbers start at 1. The exit code is 1 when a file breaks a rule.\n" .
               "The netflix-en preset has the limits of the Netflix English (USA) Timed Text Style Guide. " .
               "These are 20 characters per second, 42 characters per line and 2 lines. " .
               "A cue lasts 0.833 s to 7 s and keeps a gap of 2 frames, with no overlaps.\n" .
               "The bbc preset has the limits of the BBC Subtitle Guidelines. " .
               "These are 37 characters per line, 180 words per minute and 0.3 s per word.\n" .
               "A rule option overrides the value of the preset.";
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps and --video-fps. A specific option wins over --fps.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("preset", "NAME", "Rule set: netflix-en or bbc."),
            Option::value("video-fps", "RATE", "Frame rate of the video, for the 2-frame gap of netflix-en. Default: 23.976."),
            Option::value("max-cps", "CHARS", "Maximum characters per second."),
            Option::value("max-cpl", "CHARS", "Maximum characters per line."),
            Option::value("max-lines", "LINES", "Maximum lines per cue."),
            Option::value("min-duration", "SECONDS", "Minimum duration of a cue."),
            Option::value("max-duration", "SECONDS", "Maximum duration of a cue."),
            Option::value("min-gap", "SECONDS", "Minimum gap between cues."),
            Option::flag("check-overlaps", "Report overlapping cues."),
            Option::flag("check-empty-cues", "Report cues without text."),
            Option::value("max-wpm", "WORDS", "Maximum words per minute."),
            Option::value("min-seconds-per-word", "SECONDS", "Minimum duration of a cue per word."),
            Option::value("max-speakers", "SPEAKERS", "Maximum speakers per cue, from dialogue dashes or <v> names."),
            Option::value("dialogue-dash", "STYLE", "Report dialogue dashes in another style than STYLE, for example \"- \" or \"-\"."),
            Option::value("allowed-characters", "LIST", "Report other characters. LIST is a list or a class such as \"[A-Za-z0-9 .,!?]\"."),
            Option::flag("check-double-spaces", "Report two or more spaces between words."),
            Option::flag("check-leading-or-trailing-spaces", "Report lines that start or end with a space."),
            Option::flag("check-unbalanced-tags", "Report formatting tags without a partner tag."),
            Option::flag("check-all-caps-lines", "Report lines in upper case only."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->withProblems = 0;
        $preset             = $arguments->choice("preset", ["netflix-en", "bbc"]);
        if ($arguments->has("video-fps") && $preset !== "netflix-en") {
            self::fail("--video-fps sets the frame rate of the netflix-en gap rule. Pass --preset netflix-en, or leave out --video-fps.");
        }
        $base = match ($preset) {
            null         => new ValidationRules(),
            "bbc"        => ValidationRules::bbc(),
            "netflix-en" => ValidationRules::netflixEnglish($arguments->rate("video-fps") ?? self::DEFAULT_FPS),
        };

        $this->rules = self::rules($arguments, $base);
        if ($this->rules == new ValidationRules()) {
            self::fail("Pass --preset or at least one rule option.");
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $violations = $subtitle->validate($this->rules);
        if ($violations !== []) {
            $this->withProblems++;
        }

        $label = self::label($input);
        $text  = $violations === [] ? "$label: no problems\n" : "";
        foreach ($violations as $violation) {
            $text .= self::violationLine($label, $violation);
        }

        $this->emit($console, $text, [
            "file"       => $input,
            "format"     => $format->value,
            "valid"      => $violations === [],
            "violations" => array_map(self::violationJson(...), $violations),
            "warnings"   => self::warningsJson($this->parseWarnings),
        ]);
    }


    protected function batchSummary(int $total, int $skipped): ?string
    {
        $valid   = $this->succeeded - $this->withProblems;
        $summary = "$total files: $valid valid, $this->withProblems with problems, $this->failed failed";

        return $summary . ($skipped > 0 ? ", $skipped skipped." : ".");
    }


    protected function exitCode(): int
    {
        return match (true) {
            $this->failed > 0       => Application::EXIT_FILE,
            $this->withProblems > 0 => Application::EXIT_RESULT,
            default                 => Application::EXIT_OK,
        };
    }


    /**
     * Returns the rules of $base with the rule options in $arguments over them.
     */
    public static function rules(Arguments $arguments, ValidationRules $base): ValidationRules
    {
        $overrides = [
            "maxCharactersPerSecond" => $arguments->positiveFloat("max-cps"),
            "maxCharactersPerLine"   => $arguments->positiveInt("max-cpl"),
            "maxLinesPerCue"         => $arguments->positiveInt("max-lines"),
            "minDuration"            => $arguments->positiveSeconds("min-duration"),
            "maxDuration"            => $arguments->positiveSeconds("max-duration"),
            "minGap"                 => $arguments->positiveSeconds("min-gap"),
            "dialogueDashStyle"      => self::dialogueDashStyle($arguments->value("dialogue-dash")),
            "maxSpeakersPerCue"      => $arguments->positiveInt("max-speakers"),
            "maxWordsPerMinute"      => $arguments->positiveFloat("max-wpm"),
            "minSecondsPerWord"      => $arguments->positiveSeconds("min-seconds-per-word"),
            "allowedCharacters"      => $arguments->value("allowed-characters"),
        ];
        foreach (self::FLAGS as $option => $field) {
            $overrides[$field] = $arguments->has($option) ? true : null;
        }

        return OptionsCopy::with($base, array_filter($overrides, fn (mixed $value): bool => $value !== null));
    }


    /**
     * Returns the text line of $violation. A violation without a cue gets no cue number.
     */
    public static function violationLine(string $label, ValidationViolation $violation): string
    {
        $cue   = $violation->cueIndex === null ? "" : "cue " . ($violation->cueIndex + 1) . ": ";
        $limit = $violation->limit === null ? "" : ", limit " . self::number($violation->limit);

        return "$label: $cue" . $violation->rule->value . " " . self::number($violation->value) . "$limit\n";
    }


    /**
     * @return array{cueIndex: ?int, rule: string, value: int|float|null, infinite: bool, limit: int|float|null}
     */
    public static function violationJson(ValidationViolation $violation): array
    {
        $infinite = is_float($violation->value) && is_infinite($violation->value);

        return [
            "cueIndex" => $violation->cueIndex,
            "rule"     => $violation->rule->value,
            "value"    => $infinite ? null : $violation->value,
            "infinite" => $infinite,
            "limit"    => $violation->limit,
        ];
    }


    private static function dialogueDashStyle(?string $style): ?DialogueDashStyle
    {
        if ($style === null) {
            return null;
        }

        return DialogueDashStyle::tryFrom($style) ?? self::fail("The option --dialogue-dash must be a hyphen, a Unicode " .
            "hyphen, an en dash or an em dash, with or without one space after it, got \"$style\".");
    }
}
