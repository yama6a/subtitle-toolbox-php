<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;

/**
 * Expands the input arguments of a command into files.
 *
 * @internal
 */
final class InputFiles
{
    /**
     * Returns the files for each argument: "-", a file, the subtitle files of a directory, or the matches of a glob.
     * A glob helps on shells that do not expand it, such as cmd.exe.
     *
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public static function expand(array $arguments): array
    {
        $inputs = [];
        foreach ($arguments as $argument) {
            if ($argument !== FileCommand::DASH && is_dir($argument)) {
                $files = self::directoryFiles($argument);
                if ($files === []) {
                    Command::fail("The directory $argument holds no file with a known subtitle extension.");
                }
                array_push($inputs, ...$files);
            } elseif ($argument === FileCommand::DASH || file_exists($argument) || strpbrk($argument, "*?[") === false) {
                $inputs[] = $argument;
            } else {
                $matches = array_values(array_filter(glob($argument) ?: [], "is_file"));
                array_push($inputs, ...($matches === [] ? [$argument] : $matches));
            }
        }

        $unique = [];
        foreach ($inputs as $input) {
            $unique[$input === FileCommand::DASH ? FileCommand::DASH : (realpath($input) ?: $input)] ??= $input;
        }

        return array_values($unique);
    }


    /**
     * @return list<string>
     */
    private static function directoryFiles(string $directory): array
    {
        $directory = rtrim($directory, "/\\");
        $files     = [];
        foreach (scandir($directory) ?: [] as $name) {
            $path   = "$directory/$name";
            $format = Format::tryFromPath($name);
            if (!is_file($path) || $format === null || !$format->canRead()) {
                continue;
            }
            // The .sub file of a VobSub pair is not MicroDVD. The parser reads it through its .idx file.
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === "sub"
                && glob($directory . "/" . pathinfo($name, PATHINFO_FILENAME) . ".[iI][dD][xX]") !== []) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }
}
