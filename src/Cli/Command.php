<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\SubtitleToolboxException;

/**
 * @internal
 */
abstract class Command
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    abstract public function name(): string;


    abstract public function summary(): string;


    /**
     * @return list<string> the argument lines after the command name, for the usage text
     */
    abstract protected function usageLines(): array;


    /**
     * @return list<Option>
     */
    abstract public function options(): array;


    abstract public function execute(Arguments $arguments, Console $console): int;


    /**
     * Runs the command with the arguments after the command name and returns the exit code.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments, Console $console): int
    {
        return $this->execute(Arguments::parse($arguments, $this->options()), $console);
    }


    /**
     * Returns the text after the summary in the help of the command, or "".
     */
    protected function details(): string
    {
        return "";
    }


    /**
     * Reports invalid arguments or a file that the command cannot process.
     */
    public static function fail(string $message): never
    {
        throw new InvalidArgumentException($message);
    }


    /**
     * Reports a file outside the inputs that the command cannot read, or an output that it cannot create.
     * The tool then stops and exits with code 3.
     */
    public static function failFile(string $message): never
    {
        throw new FileFailure($message);
    }


    /**
     * Returns the message of a library exception, which names its class, or the class and message of another error.
     */
    public static function throwableMessage(\Throwable $throwable): string
    {
        return $throwable instanceof SubtitleToolboxException
            ? $throwable->getMessage()
            : $throwable::class . ": " . $throwable->getMessage();
    }


    /**
     * Returns $arguments without the null values.
     * A constructor that gets the result as named arguments then uses its own default for each missing option.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $arguments): array
    {
        return array_filter($arguments, fn (mixed $value): bool => $value !== null);
    }


    /**
     * Returns $value with at most $decimals decimals and no trailing zeros, "INF" for infinity, or "-" for null.
     */
    public static function number(int|float|null $value, int $decimals = 3): string
    {
        return match (true) {
            $value === null     => "-",
            is_int($value)      => (string)$value,
            is_infinite($value) => "INF",
            default             => rtrim(rtrim(number_format($value, $decimals, ".", ""), "0"), "."),
        };
    }


    protected static function helpOption(): Option
    {
        return Option::flag("help", "Show this help.", "h");
    }


    /**
     * Returns the help of the command. $topic is the word after --help, or null. Only convert reads it.
     */
    public function help(?string $topic = null): string
    {
        return $this->helpHeader() . "\nOptions:\n" . self::optionList([...$this->options(), self::helpOption()]);
    }


    protected function helpHeader(): string
    {
        $usage = "";
        foreach ($this->usageLines() as $index => $line) {
            $usage .= HelpLayout::usage(($index === 0 ? "Usage: " : "       ") . Application::NAME . " " . $this->name(), $line);
        }

        $header = $usage . "\n" . HelpLayout::wrap($this->summary());

        return $this->details() === "" ? $header : $header . "\n" . HelpLayout::wrap($this->details());
    }


    /**
     * @param list<Option> $options
     */
    protected static function optionList(array $options): string
    {
        return HelpLayout::table(array_map(fn (Option $option): array => [$option->synopsis(), $option->description], $options));
    }
}
