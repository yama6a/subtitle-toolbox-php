<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;

/**
 * @internal
 */
final class FormatsCommand extends Command
{
    public function name(): string
    {
        return "formats";
    }


    public function summary(): string
    {
        return "List the format names and file extensions for --from and --to.";
    }


    protected function usageLines(): array
    {
        return [""];
    }


    protected function details(): string
    {
        return "When two formats share an extension, the first one in the list owns it. So .sub is MicroDVD, and .json is the JSON of this library. " .
               "Pass --from to read another one.";
    }


    public function options(): array
    {
        return [];
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        if ($arguments->positionals !== []) {
            self::fail("The formats command takes no arguments.");
        }

        $rows = [["Name", "Extensions", "Read", "Write"]];
        foreach (Format::cases() as $format) {
            $rows[] = [
                $format->value,
                implode(" ", array_map(fn (string $extension): string => ".$extension", $format->extensions())),
                $format->canRead() ? "yes" : "no",
                $format->canWrite() ? "yes" : "no",
            ];
        }

        $console->out(HelpLayout::table($rows, 0));

        return Application::EXIT_OK;
    }
}
