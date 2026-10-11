<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Timecode;

/**
 * @internal
 */
final class Arguments
{
    /**
     * @param list<string>                $positionals
     * @param array<string, list<string>> $options     long name => values, an empty list for a flag
     */
    private function __construct(
        public readonly array $positionals,
        private readonly array $options,
    ) {
    }


    /**
     * Parses `--name`, `--name=value`, `--name value`, `-n value` and `--`. A lone `-` is a positional argument.
     *
     * @param list<string> $argv   the arguments after the command name
     * @param list<Option> $spec
     */
    public static function parse(array $argv, array $spec): self
    {
        $byName  = [];
        $byShort = [];
        foreach ($spec as $option) {
            $byName[$option->name] = $option;
            if ($option->short !== null) {
                $byShort[$option->short] = $option;
            }
        }

        $positionals = [];
        $options     = [];
        $count       = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $argument = $argv[$i];
            if ($argument === "--") {
                array_push($positionals, ...array_slice($argv, $i + 1));
                break;
            }
            if ($argument === "-" || !str_starts_with($argument, "-")) {
                $positionals[] = $argument;
                continue;
            }

            [$option, $value] = self::findOption($argument, $byName, $byShort);
            if (!$option->takesValue()) {
                if ($value !== null) {
                    Command::fail("The option --$option->name takes no value.");
                }
                $options[$option->name] = [];
                continue;
            }
            if ($value === null) {
                if ($i + 1 >= $count) {
                    Command::fail("The option --$option->name needs a value.");
                }
                $value = $argv[++$i];
            }
            if (isset($options[$option->name]) && !$option->repeatable) {
                Command::fail("The option --$option->name is given twice.");
            }
            $options[$option->name][] = $value;
        }

        return new self($positionals, $options);
    }


    /**
     * Returns the option that $argument names, and the value after "=" in a long option.
     *
     * @param array<string, Option> $byName
     * @param array<string, Option> $byShort
     * @return array{Option, ?string}
     */
    private static function findOption(string $argument, array $byName, array $byShort): array
    {
        if (!str_starts_with($argument, "--")) {
            return [$byShort[substr($argument, 1)] ?? Command::fail("Unknown option $argument."), null];
        }

        [$name, $value] = array_pad(explode("=", substr($argument, 2), 2), 2, null);

        return [$byName[$name] ?? Command::fail("Unknown option --$name."), $value];
    }


    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }


    public function value(string $name): ?string
    {
        return $this->options[$name][0] ?? null;
    }


    /**
     * @return list<string>
     */
    public function values(string $name): array
    {
        return $this->options[$name] ?? [];
    }


    public function float(string $name): ?float
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            Command::fail("The option --$name needs a number, got \"$value\".");
        }
        if (!is_finite((float)$value)) {
            Command::fail("The option --$name needs a finite number, got \"$value\".");
        }

        return (float)$value;
    }


    public function positiveFloat(string $name): ?float
    {
        $value = $this->float($name);
        if ($value !== null && $value <= 0) {
            Command::fail("The option --$name must be greater than 0.");
        }

        return $value;
    }


    public function nonNegativeFloat(string $name): ?float
    {
        $value = $this->float($name);
        if ($value !== null && $value < 0) {
            Command::fail("The option --$name must not be negative.");
        }

        return $value;
    }


    /**
     * Returns the time of the option $name. It takes a number of seconds, or a timecode of Timecode::parse() with an optional minus sign.
     */
    public function seconds(string $name): ?float
    {
        $value = $this->value($name);

        return $value === null ? null : self::time($name, $value);
    }


    /**
     * Returns the seconds of $value, a number or a timecode as seconds() takes it. A usage error names the option $name.
     */
    public static function time(string $name, string $value): float
    {
        if (is_numeric($value)) {
            if (!is_finite((float)$value)) {
                Command::fail("The option --$name needs a finite number, got \"$value\".");
            }

            return (float)$value;
        }
        $timecode = str_starts_with($value, "-") ? substr($value, 1) : $value;
        try {
            $seconds = Timecode::parse($timecode);
        } catch (InvalidArgumentException) {
            return Command::fail("The option --$name needs seconds or a timecode such as 00:01:02.500, got \"$value\".");
        }

        return $timecode === $value ? $seconds : -$seconds;
    }


    public function positiveSeconds(string $name): ?float
    {
        $value = $this->seconds($name);
        if ($value !== null && $value <= 0) {
            Command::fail("The option --$name must be greater than 0.");
        }

        return $value;
    }


    public function nonNegativeSeconds(string $name): ?float
    {
        $value = $this->seconds($name);
        if ($value !== null && $value < 0) {
            Command::fail("The option --$name must not be negative.");
        }

        return $value;
    }


    /**
     * Returns the frame rate of the option $name, else of --fps, which sets all frame rates.
     */
    public function rate(string $name): ?float
    {
        $fps = $this->positiveFloat("fps");

        return $this->positiveFloat($name) ?? $fps;
    }


    /**
     * Returns the whole number of the option $name, from $min to $max. A null $max means no upper limit.
     */
    public function int(string $name, int $min, ?int $max = null): ?int
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        // 18 digits stay below PHP_INT_MAX, so the cast cannot overflow.
        $digits = ltrim(ltrim($value, "-"), "0");
        $number = preg_match('/^-?[0-9]+$/', $value) === 1 && strlen($digits) <= 18 ? (int)$value : null;
        if ($number === null || $number < $min || ($max !== null && $number > $max)) {
            $range = $max === null ? "of $min or more" : "from $min to $max";
            Command::fail("The option --$name needs a whole number $range, got \"$value\".");
        }

        return $number;
    }


    /**
     * Returns the value of the option $name as the entry of $allowed that it matches in any case.
     *
     * @param non-empty-list<string> $allowed
     */
    public function choice(string $name, array $allowed): ?string
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        foreach ($allowed as $choice) {
            if (strcasecmp($choice, $value) === 0) {
                return $choice;
            }
        }
        $last = array_pop($allowed);

        return Command::fail("The option --$name must be " . ($allowed === [] ? $last : implode(", ", $allowed) . " or $last") . ", got \"$value\".");
    }


    public function positiveInt(string $name): ?int
    {
        return $this->int($name, 1);
    }
}
