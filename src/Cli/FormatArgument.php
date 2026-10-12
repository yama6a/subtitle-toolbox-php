<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;

/**
 * Reads the format of --from, --from2 and --to: a format name or a file extension.
 *
 * @internal
 */
final class FormatArgument
{
    /**
     * Returns the format for a format name or a file extension such as "SRT" or ".ssa".
     */
    public static function find(string $nameOrExtension): Format
    {
        $key = strtolower(ltrim($nameOrExtension, "."));

        return Format::tryFrom($key) ?? Format::tryFromPath("file.$key")
            ?? Command::fail("Unknown format \"$nameOrExtension\". Run \"" . Application::NAME . " formats\" for the list.");
    }


    /**
     * Returns the format as find() does, and fails for a format that the library can write but not read.
     */
    public static function readable(string $nameOrExtension): Format
    {
        $format = self::find($nameOrExtension);
        if (!$format->canRead()) {
            Command::fail("The format $format->value can be written but not read.");
        }

        return $format;
    }
}
