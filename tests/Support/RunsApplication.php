<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Cli\Application;

trait RunsApplication
{
    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private static function runApplication(array $arguments, string $stdin = ""): array
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        fwrite($streams[0], $stdin);
        rewind($streams[0]);

        $code = (new Application(...$streams))->run(["stbox", ...$arguments]);

        rewind($streams[1]);
        rewind($streams[2]);

        return [$code, stream_get_contents($streams[1]), stream_get_contents($streams[2])];
    }


    /**
     * Returns the result of a run that stops with a usage error: exit code 2, no output, and the message with the
     * pointer to the help of the command.
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    protected static function usageError(string $command, string $message): array
    {
        return [2, "", "Error: $message\nRun \"stbox help $command\" for the usage.\n"];
    }
}
