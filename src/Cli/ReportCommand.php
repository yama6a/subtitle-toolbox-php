<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\ParseWarning;

/**
 * Prints a report on each input file, as text or as JSON.
 *
 * @internal
 */
abstract class ReportCommand extends FileCommand
{
    protected bool $json = false;

    /** @var list<array<string, mixed>> */
    private array $entries = [];


    public function options(): array
    {
        return [
            ...$this->commandOptions(),
            Option::flag("json", $this->jsonDescription()),
            ...$this->inputOptions(),
        ];
    }


    protected function jsonDescription(): string
    {
        return "Print JSON: a list with one object for each input file.";
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->json    = $arguments->has("json");
        $this->entries = [];
    }


    /**
     * Prints the text report now, or keeps the JSON entry for the end of the run.
     *
     * @param array<string, mixed> $entry
     */
    protected function emit(Console $console, string $text, array $entry): void
    {
        if ($this->json) {
            $this->entries[] = $entry;
        } else {
            $console->out($text);
        }
    }


    protected function finish(Console $console): void
    {
        if (!$this->json) {
            return;
        }

        $console->out(json_encode($this->entries, JSON_PRETTY_PRINT | self::JSON_FLAGS | JSON_THROW_ON_ERROR) . "\n");
    }


    protected function report(Console $console, string $line): void
    {
        $this->json ? $console->err($line) : $console->out($line);
    }


    /**
     * @param list<ParseWarning> $warnings
     *
     * @return list<array{lineNumber: ?int, blockIndex: ?int, message: string, action: string}>
     */
    protected static function warningsJson(array $warnings): array
    {
        return array_map(fn (ParseWarning $warning): array => [
            "lineNumber" => $warning->lineNumber,
            "blockIndex" => $warning->blockIndex,
            "message"    => CliMessages::reword($warning->message, null, null),
            "action"     => $warning->action->value,
        ], $warnings);
    }
}
