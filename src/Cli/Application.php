<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\SubtitleToolboxException;

/**
 * @internal
 */
final class Application
{
    public const NAME = "subtitle-toolbox";

    public const EXIT_OK     = 0;
    public const EXIT_RESULT = 1;
    public const EXIT_USAGE  = 2;
    public const EXIT_FILE   = 3;

    private Console $console;

    /** @var list<Command> */
    private array $commands;


    /**
     * Creates the application with the given streams, or with the standard streams of the process.
     *
     * @param resource|null $stdin
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param (\Closure(string): void)|null $beforeCreate a test hook that runs before each output file is created, see OutputFiles
     */
    public function __construct($stdin = null, $stdout = null, $stderr = null, ?\Closure $beforeCreate = null)
    {
        $this->console  = new Console(
            $stdin ?? fopen("php://stdin", "rb"),
            $stdout ?? fopen("php://stdout", "wb"),
            $stderr ?? fopen("php://stderr", "wb"),
        );
        $this->commands = [
            new ConvertCommand($beforeCreate),
            new RetimeCommand($beforeCreate),
            new InfoCommand($beforeCreate),
            new ValidateCommand($beforeCreate),
            new SyncCommand($beforeCreate),
            new DiffCommand($beforeCreate),
            new TranslateCommand($beforeCreate),
            new DualCommand($beforeCreate),
            new HlsCommand($beforeCreate),
            new FormatsCommand(),
        ];
    }


    /**
     * Runs the command in $argv, where $argv[0] is the script name, and returns the exit code.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name      = $argv[1] ?? null;
        $arguments = array_slice($argv, 2);

        if ($name === null || $name === "--help" || $name === "-h") {
            $this->console->out($this->help());

            return self::EXIT_OK;
        }
        if ($name === "--version" || $name === "-V") {
            $this->console->out(Version::get() . "\n");

            return self::EXIT_OK;
        }
        if ($name === "help") {
            return $this->runHelp($arguments);
        }

        $command = $this->find($name);
        if ($command === null) {
            return $this->usageError("Unknown command \"$name\".", "help");
        }

        $help = array_key_first(array_filter($arguments, fn (string $argument): bool => $argument === "--help" || $argument === "-h"));
        if ($help !== null) {
            $topic = $arguments[$help + 1] ?? null;

            return $this->printHelp($command, $topic !== null && !str_starts_with($topic, "-") ? $topic : null);
        }

        try {
            return $command->run($arguments, $this->console);
        } catch (FileFailure $failure) {
            $this->console->err("Error: " . $failure->getMessage() . "\n");

            return self::EXIT_FILE;
        } catch (SubtitleToolboxException $exception) {
            return $this->usageError($exception->getMessage(), "help " . $command->name());
        } catch (\Throwable $throwable) {
            $this->console->err("Error: " . Command::throwableMessage($throwable) . "\n");

            return self::EXIT_FILE;
        }
    }


    private function runHelp(array $arguments): int
    {
        if ($arguments === [] || $arguments[0] === "help") {
            $this->console->out($this->help());

            return self::EXIT_OK;
        }

        $command = $this->find($arguments[0]);
        if ($command === null) {
            return $this->usageError("Unknown command \"$arguments[0]\".", "help");
        }

        return $this->printHelp($command, $arguments[1] ?? null);
    }


    private function printHelp(Command $command, ?string $topic): int
    {
        try {
            $this->console->out($command->help($topic));
        } catch (SubtitleToolboxException $exception) {
            return $this->usageError($exception->getMessage(), "help " . $command->name());
        }

        return self::EXIT_OK;
    }


    private function find(string $name): ?Command
    {
        foreach ($this->commands as $command) {
            if ($command->name() === $name) {
                return $command;
            }
        }

        return null;
    }


    private function usageError(string $message, string $helpArguments): int
    {
        $this->console->err("Error: $message\nRun \"" . self::NAME . " $helpArguments\" for the usage.\n");

        return self::EXIT_USAGE;
    }


    private function help(): string
    {
        $name      = self::NAME;
        $commands  = HelpLayout::table([
            ...array_map(fn (Command $command): array => [$command->name(), $command->summary()], $this->commands),
            ["help", "Show the help of a command."],
        ]);
        $about     = HelpLayout::wrap(
            "Run \"$name help <command>\" or \"$name <command> --help\" for the options of a command. " .
            "A file argument of - reads standard input. One input goes to standard output, or to -o FILE. " .
            "Several inputs need --output-dir DIR. The tool never overwrites a file."
        );
        $exitCodes = HelpLayout::table([
            [(string)self::EXIT_OK, "Success."],
            [(string)self::EXIT_RESULT, "A file broke a validation rule, or diff found a difference."],
            [(string)self::EXIT_USAGE, "Invalid arguments."],
            [(string)self::EXIT_FILE, "A file could not be read or written."],
        ]);
        $options   = HelpLayout::table([["-h, --help", "Show this help."], ["-V, --version", "Print the version."]]);

        return "$name " . Version::get() . "\n" .
               "Converts, retimes, checks and fixes subtitle files.\n\n" .
               "Usage: $name <command> [<file>...] [options]\n\n" .
               "Commands:\n$commands\n$about\nExit codes:\n$exitCodes\nOptions:\n$options";
    }
}
