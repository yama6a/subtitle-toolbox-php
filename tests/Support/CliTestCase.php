<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

/**
 * Runs the CLI in a temporary directory with copies of the fixtures.
 */
abstract class CliTestCase extends TestCase
{
    use RunsApplication;

    protected const BIN = __DIR__ . "/../../bin/subtitle-toolbox";

    protected const FIXTURES = __DIR__ . "/../files/cli/";

    protected const FILES = __DIR__ . "/../files/";

    protected const BOM = "\xEF\xBB\xBF";

    private const ERROR_TYPES = [
        E_WARNING => "Warning", E_USER_WARNING => "Warning", E_NOTICE => "Notice", E_USER_NOTICE => "Notice",
        E_DEPRECATED => "Deprecated", E_USER_DEPRECATED => "Deprecated",
    ];

    protected string $dir;


    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . "/subtitle-toolbox-cli-" . bin2hex(random_bytes(6));
        mkdir($this->dir);
        foreach (glob(self::FIXTURES . "*") as $fixture) {
            copy($fixture, "$this->dir/" . basename($fixture));
        }
    }


    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path => $info) {
            $info->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }


    /**
     * Runs the CLI in the test process, in the temporary directory. A PHP warning, notice or deprecation goes to
     * standard error as in bin/subtitle-toolbox, so a test that compares standard error fails on it. PHPUnit lowers
     * the error reporting level while its own error handler runs, so this method raises it for the run.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    protected function runCli(array $arguments, string $stdin = ""): array
    {
        $errors    = "";
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$errors): bool {
            if ((error_reporting() & $type) === 0) {
                return false;
            }
            $errors .= (self::ERROR_TYPES[$type] ?? "Error") . ": $message in $file on line $line\n";

            return true;
        });
        $cwd = getcwd();
        chdir($this->dir);
        try {
            [$code, $stdout, $stderr] = self::runApplication($arguments, $stdin);
        } finally {
            chdir($cwd);
            restore_error_handler();
            error_reporting($reporting);
        }

        return [$code, $stdout, $errors . $stderr];
    }


    /**
     * Runs bin/subtitle-toolbox as a separate process, in the temporary directory.
     *
     * @param list<string> $arguments
     * @param string ...$phpOptions options of the php binary, such as "-d", "memory_limit=32M"
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    protected function runBinary(array $arguments, string $stdin = "", string ...$phpOptions): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$phpOptions, self::BIN, ...$arguments],
            [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes,
            $this->dir
        );
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }


    /**
     * Joins each wrapped help line to the line before it, so that an option and its whole description are on one line.
     */
    public static function unwrapHelp(string $help): string
    {
        return (string)preg_replace('/\n {3,}(?=\S)/', " ", $help);
    }


    protected function file(string $name): string
    {
        return file_get_contents("$this->dir/$name");
    }


    protected function tripAs(Format $format): string
    {
        return Subtitle::fromString(file_get_contents(self::FIXTURES . "trip.srt"), Format::SubRip)->toString($format);
    }


    /**
     * Returns each file below the temporary directory with the hash of its content, or "dir" for a directory.
     *
     * @return array<string, string>
     */
    protected function snapshot(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                                                \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
            $files[substr($path, strlen($this->dir))] = $info->isDir() ? "dir" : md5_file($path);
        }
        ksort($files);

        return $files;
    }
}
