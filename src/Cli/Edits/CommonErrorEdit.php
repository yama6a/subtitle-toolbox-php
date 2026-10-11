<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Cli\SideFile;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Fixing\CommonErrorRule;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\OptionsCopy;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class CommonErrorEdit extends Edit
{
    private function __construct(
        private CommonErrorOptions $options,
        private readonly bool $list,
        private readonly ?string $replaceListPath,
    ) {
    }


    public static function group(): string
    {
        return "errors";
    }


    public static function summary(): string
    {
        return "Fix spacing, punctuation and OCR errors.";
    }


    public static function options(): array
    {
        return [
            Option::flag("errors-fix", "Fix spacing, punctuation, dash, tag and OCR errors such as lt's for It's."),
            Option::value("errors-replace-list", "FILE", "Also apply this Subtitle Edit OCR replace list, an XML file, with --errors-fix."),
            Option::value("errors-enable", "NAME[,NAME]", "Also apply these rules that are off by default with --errors-fix: " .
                          implode(", ", self::optionalRules()) . "."),
            Option::flag("errors-list-fixes", "Print each change of --errors-fix to standard error."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "errors-fix", ["errors-replace-list", "errors-enable", "errors-list-fixes"]);
        if (!$arguments->has("errors-fix")) {
            return null;
        }

        $enabled = [];
        foreach (array_filter(array_map("trim", explode(",", $arguments->value("errors-enable") ?? ""))) as $name) {
            if (!in_array($name, self::optionalRules(), true)) {
                Command::fail("Unknown rule \"$name\" in --errors-enable. The valid names are " . implode(", ", self::optionalRules()) . ".");
            }
            $enabled[$name] = true;
        }

        return new self(
            new CommonErrorOptions(...["language" => $arguments->value("language"), ...$enabled]),
            $arguments->has("errors-list-fixes"),
            $arguments->value("errors-replace-list"),
        );
    }


    /**
     * @return list<string>
     */
    private static function optionalRules(): array
    {
        $defaults = new CommonErrorOptions();

        return array_values(array_filter(array_map(fn (CommonErrorRule $rule): string => $rule->value, CommonErrorRule::cases()),
                                         fn (string $name): bool => ($defaults->$name ?? null) === false));
    }


    public function loadSideFiles(): void
    {
        if ($this->replaceListPath !== null) {
            $this->options = OptionsCopy::with($this->options, [
                "replaceList" => SideFile::parse($this->replaceListPath, OcrReplaceList::fromSubtitleEditXml(...)),
            ]);
        }
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        foreach (CommonErrorFixer::apply($subtitle, $this->options)->fixes as $fix) {
            if ($this->list) {
                $console->err("$label: cue " . ($fix->cueIndex + 1) . ": {$fix->rule->value}: " .
                              json_encode($fix->before, Command::JSON_FLAGS) . " -> " .
                              json_encode($fix->after, Command::JSON_FLAGS) . "\n");
            }
        }

        return $subtitle;
    }
}
