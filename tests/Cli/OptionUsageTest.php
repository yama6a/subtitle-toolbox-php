<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Checks that the other tests in tests/Cli use each option of each command at least once.
 */
class OptionUsageTest extends CliTestCase
{
    private const GLOBAL_OPTIONS = ["--help", "--version"];

    /** @var array{options: array<string, list<string>>, aliases: array<string, string>}|null */
    private static ?array $help = null;


    public function testEveryCommandOptionIsUsedByATest(): void
    {
        ["options" => $options, "aliases" => $aliases] = $this->help();
        $usages = [];
        foreach (array_diff(glob(__DIR__ . "/*.php"), [__FILE__]) as $file) {
            $usages = array_merge_recursive($usages, self::usages(file_get_contents($file), array_keys($options), $aliases));
        }

        $unused = [];
        foreach ($options as $command => $commandOptions) {
            foreach ($commandOptions as $option) {
                if (!isset($usages[$command][$option])) {
                    $unused[] = "$command $option";
                }
            }
        }

        $this->assertSame([], $unused);
    }


    public function testHelpListsTheOptions(): void
    {
        ["options" => $options, "aliases" => $aliases] = $this->help();

        $this->assertSame(["convert", "info", "validate", "sync", "diff", "translate", "dual", "hls", "formats"], array_keys($options));
        $this->assertContains("--sdh-keep-music-lines", $options["convert"]);
        $this->assertContains("--output", $options["convert"]);
        $this->assertNotContains("--help", $options["convert"]);
        $this->assertContains("--split-penalty", $options["sync"]);
        $this->assertSame([], $options["formats"]);
        $this->assertSame(["-o" => "--output"], $aliases);
    }


    public function testAnOptionBelongsToTheLastCommandBeforeIt(): void
    {
        $code = <<<'PHP'
            <?php
            class SampleTest
            {
                #[DataProvider("cases")]
                public function testRun(string $command): void
                {
                    $this->runCli([$command, "a.srt", "--bom"]);
                }

                public static function cases(): array
                {
                    return ["one" => ["hls"], "two" => ["sync"]];
                }

                public function testTwo(): void
                {
                    $this->runCli(["convert", "a.srt", "-o", "-", "--to=vtt"]);
                    $this->runCli(["info", "a.srt", "--json"]);
                    $closure = function () {
                        return "--lenient";
                    };
                }
            }
            PHP;

        $this->assertEquals(
            ["hls" => ["--bom" => true], "sync" => ["--bom" => true], "convert" => ["--output" => true, "--to" => true],
             "info" => ["--json" => true, "--lenient" => true]],
            self::usages($code, ["convert", "hls", "info", "sync"], ["-o" => "--output"])
        );
    }


    /**
     * Reads the options of each command from "help COMMAND", and for convert also from "convert --help all". The help
     * command has no options of its own.
     *
     * @return array{options: array<string, list<string>>, aliases: array<string, string>} long option names by command
     *         name in help order, and long option names by short alias
     */
    private function help(): array
    {
        if (self::$help !== null) {
            return self::$help;
        }

        [$code, $help] = $this->runCli(["help"]);
        $this->assertSame(0, $code);
        $this->assertSame(1, preg_match('/^Commands:\n((?:  .*\n)+)/m', $help, $match));
        preg_match_all('/^  (\S+)/m', $match[1], $commands);

        $options = [];
        $aliases = [];
        foreach (array_diff($commands[1], ["help"]) as $command) {
            $texts = [$this->runCli(["help", $command])];
            if ($command === "convert") {
                $texts[] = $this->runCli(["convert", "--help", "all"]);
            }
            $options[$command] = [];
            foreach ($texts as [$code, $text]) {
                $this->assertSame(0, $code, "help $command");
                preg_match_all('/^ {2}(?:(-[a-zA-Z]), )?(--[a-z0-9][a-z0-9-]*)/m', $text, $found, PREG_SET_ORDER);
                foreach ($found as [, $short, $long]) {
                    $options[$command][] = $long;
                    if ($short !== "" && !in_array($long, self::GLOBAL_OPTIONS, true)) {
                        $aliases[$short] = $long;
                    }
                }
            }
            $options[$command] = array_values(array_diff(array_unique($options[$command]), self::GLOBAL_OPTIONS));
        }

        return self::$help = ["options" => $options, "aliases" => $aliases];
    }


    /**
     * Finds the options that each command gets in a test file, from the string literals of each method and of its data
     * provider. An option belongs to the last command name before it, or to each command name of the method when no
     * command name comes before it. A short alias counts as its long option.
     *
     * @param list<string> $commands
     * @param array<string, string> $aliases
     *
     * @return array<string, array<string, true>> the used long options by command name
     */
    private static function usages(string $code, array $commands, array $aliases): array
    {
        $methods = self::stringsByMethod($code);
        preg_match_all('/#\[DataProvider\("(\w+)"\)\]\s*(?:#\[[^\]]*\]\s*)*public function (\w+)/', $code, $providers, PREG_SET_ORDER);
        foreach ($providers as [, $provider, $test]) {
            $methods[$test] = [...$methods[$test], ...$methods[$provider]];
        }

        $usages = [];
        foreach ($methods as $strings) {
            $command = null;
            $leading = [];
            foreach ($strings as $string) {
                if (in_array($string, $commands, true)) {
                    $command = $string;
                    continue;
                }
                $option = preg_match('/^(--[a-z0-9][a-z0-9-]*)(?:=|$)/', $string, $match) === 1 ? $match[1] : $aliases[$string] ?? null;
                if ($option === null) {
                    continue;
                }
                if ($command === null) {
                    $leading[] = $option;
                } else {
                    $usages[$command][$option] = true;
                }
            }
            foreach (array_intersect($commands, $strings) as $command) {
                foreach ($leading as $option) {
                    $usages[$command][$option] = true;
                }
            }
        }

        return $usages;
    }


    /**
     * Collects the string literals in each method body of a PHP file, also those of closures in the method.
     *
     * @return array<string, list<string>> the strings by method name
     */
    private static function stringsByMethod(string $code): array
    {
        $methods     = [];
        $depth       = 0;
        $methodDepth = null;
        $inSignature = false;
        $name        = "";
        $strings     = [];
        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is(T_FUNCTION) && $methodDepth === null && $depth === 1) {
                $inSignature = true;
            } elseif ($inSignature && $name === "" && $token->is(T_STRING)) {
                $name = $token->text;
            } elseif ($token->is(["{", T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                if ($inSignature) {
                    [$inSignature, $methodDepth, $strings] = [false, $depth, []];
                }
                $depth++;
            } elseif ($token->is("}")) {
                $depth--;
                if ($depth === $methodDepth) {
                    $methods[$name] = $strings;
                    [$methodDepth, $name] = [null, ""];
                }
            } elseif ($token->is(";") && $inSignature) {
                [$inSignature, $name] = [false, ""];
            } elseif ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && $methodDepth !== null) {
                $strings[] = $token->is(T_CONSTANT_ENCAPSED_STRING) ? stripcslashes(substr($token->text, 1, -1)) : $token->text;
            }
        }

        return $methods;
    }
}
