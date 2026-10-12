<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Subtitle;

/**
 * One option group of convert, with the checks of its options.
 * Most options start with the group name, such as --structure- or --sdh-.
 * --video-fps keeps the name of the validate option. --case and --mute-edl read better alone.
 *
 * @internal
 */
abstract class Edit implements OptionGroup
{
    /**
     * Returns the edit, or null when $arguments hold no option that turns it on. Fails on invalid values. Reads no file.
     */
    abstract public static function fromArguments(Arguments $arguments): ?static;


    /**
     * Changes $subtitle and returns it, or returns a new subtitle. $label names the input in messages.
     */
    abstract public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle;


    public static function needsWordTimestamps(Arguments $arguments): bool
    {
        return false;
    }


    /**
     * Returns false when the options of the edit allow only one input file.
     */
    public function takesManyInputs(): bool
    {
        return true;
    }


    /**
     * Loads the files that the options of the edit name. The command calls it once, after all checks of its arguments.
     */
    public function loadSideFiles(): void
    {
    }


    /**
     * Fails when $arguments hold one of $options without the option $needed.
     *
     * @param list<string> $options
     */
    protected static function needs(Arguments $arguments, string $needed, array $options): void
    {
        foreach ($options as $option) {
            if ($arguments->has($option) && !$arguments->has($needed)) {
                Command::fail("Pass --$needed with --$option.");
            }
        }
    }


    /**
     * Fails when $arguments hold $option without one of the options $needed.
     *
     * @param non-empty-list<string> $needed
     */
    public static function needsOneOf(Arguments $arguments, array $needed, string $option): void
    {
        if (!$arguments->has($option) || array_filter($needed, $arguments->has(...)) !== []) {
            return;
        }
        $last = "--" . array_pop($needed);

        Command::fail("Pass " . ($needed === [] ? $last : "--" . implode(", --", $needed) . " or $last") . " with --$option.");
    }
}
