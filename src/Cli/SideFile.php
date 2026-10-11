<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\ParsingException;

/**
 * Reads a side file, a file that an option names besides the inputs, such as the word list of --mask-words.
 * A side file that is missing, unreadable or broken stops the run with exit code 3.
 *
 * @internal
 */
final class SideFile
{
    /**
     * Returns the content of the side file at $path.
     */
    public static function read(string $path): string
    {
        if (!is_file($path)) {
            self::fail($path, "The file does not exist.");
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            self::fail($path, "Cannot read the file.");
        }

        return $content;
    }


    /**
     * Returns $parse applied to the content of the side file at $path. A $failure that $parse throws stops the run.
     *
     * @template T
     *
     * @param callable(string): T      $parse
     * @param class-string<\Throwable> $failure
     *
     * @return T
     */
    public static function parse(string $path, callable $parse, string $failure = ParsingException::class): mixed
    {
        $content = self::read($path);

        try {
            return $parse($content);
        } catch (\Throwable $exception) {
            return $exception instanceof $failure ? self::fail($path, $exception->getMessage()) : throw $exception;
        }
    }


    /**
     * Reports the side file at $path that is missing or does not parse.
     */
    public static function fail(string $path, string $message): never
    {
        Command::failFile("$path: $message");
    }
}
