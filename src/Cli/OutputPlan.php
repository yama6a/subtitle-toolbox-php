<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;

/**
 * Plans the output file of each input of a WriteCommand from -o, --output-dir and --to, and checks it before the first read.
 * No output may exist, be a file that the command reads, or be the output of 2 inputs.
 *
 * @internal
 */
final class OutputPlan
{
    /** @var list<string> the real paths of the files that the command reads */
    private array $readPaths = [];

    /** @var array<string, string> input => real path of its output file, where check() could tell it */
    private array $plannedTargets = [];

    private bool $dataOnStdout = false;


    /**
     * Fails on an --output that cannot be a new file, or that clashes with --output-dir or --to.
     */
    public function __construct(private readonly ?string $output, private readonly ?string $directory, private readonly ?Format $toFormat)
    {
        if ($output !== null && $directory !== null) {
            Command::fail("Pass only one of --output and --output-dir.");
        }
        FileCommand::checkOutputDirectory($directory);
        if ($output !== null && str_ends_with($output, "/")) {
            Command::fail("The output $output ends with a slash. Pass a file name, or pass --output-dir $output.");
        }
        $lastPart = $output === null ? null : substr((string)strrchr("/$output", "/"), 1);
        if ($lastPart === "." || $lastPart === "..") {
            Command::fail("The output $output ends with \"$lastPart\". Pass a file name, or pass --output-dir $output.");
        }
        if ($output !== null && $output !== FileCommand::DASH && is_dir(FileCommand::realTarget($output))) {
            Command::fail("The output $output is a directory. Pass --output-dir $output.");
        }
        if ($output !== null && $toFormat !== null && $output !== FileCommand::DASH) {
            $named = Format::fromPath($output);
            if ($named !== null && !in_array(strtolower(pathinfo($output, PATHINFO_EXTENSION)), $toFormat->extensions(), true)) {
                Command::fail("The extension of --output $output names the format $named->value, not the --to format $toFormat->value.");
            }
        }
    }


    /**
     * Returns true when the subtitle data goes to standard output or comes from standard input, so reports go to standard error.
     */
    public function dataOnStdout(): bool
    {
        return $this->dataOnStdout;
    }


    /**
     * Plans every output file before the first read.
     * It fails when a file exists, is a file that the command reads, or is the output of two inputs.
     *
     * @param list<string>          $inputs
     * @param list<string>          $readPaths   the files that the command reads
     * @param array<string, string> $sideOutputs the files that the command writes besides the subtitles, by option name
     */
    public function check(array $inputs, array $readPaths, array $sideOutputs, InputReader $inputReader): void
    {
        $count = count($inputs);
        if ($this->output !== null && $count > 1) {
            Command::fail("--output takes one input file, got $count. Pass --output-dir DIR for several files.");
        }
        if ($count > 1 && $this->directory === null) {
            Command::fail("$count input files need --output-dir DIR. One input file goes to standard output or to -o FILE.");
        }
        if ($this->directory !== null && in_array(FileCommand::DASH, $inputs, true)) {
            Command::fail("Standard input has no file name for --output-dir. Pass -o FILE.");
        }

        $this->readPaths    = self::realReadPaths($readPaths);
        $this->dataOnStdout = $this->output === FileCommand::DASH || in_array(FileCommand::DASH, $inputs, true)
            || ($this->output === null && $this->directory === null);

        $this->plannedTargets = [];
        $writers              = [];
        foreach ($inputs as $input) {
            $target = $this->plannedTarget($input, $inputReader);
            if ($target === null || $target === FileCommand::DASH) {
                continue;
            }
            $real = FileCommand::realTarget($target);
            if (isset($writers[$real])) {
                Command::fail("$writers[$real] and $input would both write $target. Pass them in two runs.");
            }
            $this->checkNewFile($real, "The output $target");
            $writers[$real]               = $input;
            $this->plannedTargets[$input] = $real;
        }
        foreach ($sideOutputs as $option => $path) {
            $real = FileCommand::realTarget($path);
            if (isset($writers[$real])) {
                Command::fail("The --$option file $path is also the output of $writers[$real].");
            }
            $this->checkNewFile($real, "The --$option file $path");
            $writers[$real] = "--$option";
        }
    }


    /**
     * Checks an output that check() could not plan, because only the read told the format of the input.
     */
    public function checkTarget(string $input, string $target): void
    {
        if ($target === FileCommand::DASH) {
            return;
        }
        $real = FileCommand::realTarget($target);
        if ($real === ($this->plannedTargets[$input] ?? null)) {
            return;
        }
        if (in_array($real, $this->plannedTargets, true)) {
            Command::fail("The output $target is also the output of another input.");
        }
        $this->checkNewFile($real, "The output $target");
    }


    /**
     * Returns the output of $input: standard output, the --output file, or a file in --output-dir.
     * The file in --output-dir has the base name of the input and the extension of $outputFormat.
     * $outputFormat is null only for standard output and --output.
     */
    public function target(string $input, ?Format $outputFormat): string
    {
        if ($this->output !== null) {
            return $this->output;
        }
        if ($input === FileCommand::DASH || $this->directory === null || $outputFormat === null) {
            return FileCommand::DASH;
        }

        $fileName   = basename($input);
        $extensions = $outputFormat->extensions();
        if (!in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $extensions, true)) {
            $fileName = pathinfo($fileName, PATHINFO_FILENAME) . "." . $extensions[0];
        }

        return rtrim($this->directory, "/\\") . "/$fileName";
    }


    /**
     * Fails when the output $name, with the real path $real, is a file that the command reads or exists.
     */
    private function checkNewFile(string $real, string $name): void
    {
        if (in_array($real, $this->readPaths, true)) {
            Command::fail("$name is a file that the command reads. Pass another output file or directory.");
        }
        if (OutputFiles::exists($real)) {
            Command::fail("$name exists. The tool never overwrites a file. Remove it, or pass another output file or directory.");
        }
    }


    /**
     * Returns the output of $input, or null when only the read can tell it.
     */
    private function plannedTarget(string $input, InputReader $inputReader): ?string
    {
        if ($input === FileCommand::DASH || $this->output !== null || $this->directory === null) {
            return $this->target($input, null);
        }
        $outputFormat = $this->toFormat;
        if ($outputFormat === null) {
            $outputFormat = $inputReader->peekFormat($input);
            $outputFormat = $outputFormat?->canWrite() ? $outputFormat : null;
        }

        return $outputFormat === null ? null : $this->target($input, $outputFormat);
    }


    /**
     * Returns the real paths of $paths that exist, with the other file of each VobSub .idx and .sub pair.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function realReadPaths(array $paths): array
    {
        $real = [];
        foreach ($paths as $path) {
            $realPath = $path === FileCommand::DASH ? false : realpath($path);
            if ($realPath === false) {
                continue;
            }
            $real[]    = $realPath;
            $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
            if ($extension !== "idx" && $extension !== "sub") {
                continue;
            }
            $pair = strtolower(pathinfo($realPath, PATHINFO_FILENAME)) . ($extension === "idx" ? ".sub" : ".idx");
            foreach (scandir(dirname($realPath)) ?: [] as $name) {
                if (strtolower($name) === $pair) {
                    $real[] = dirname($realPath) . DIRECTORY_SEPARATOR . $name;
                }
            }
        }

        return array_values(array_unique($real));
    }
}
