<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Http\LocalServer;
use SubtitleToolbox\Http\WithoutCurl;
use SubtitleToolbox\Tests\Support\RunsApplication;

require_once __DIR__ . "/../Http/LocalServer.php";
require_once __DIR__ . "/../Http/WithoutCurl.php";

class ApplicationTranslateTest extends TestCase
{
    use RunsApplication;

    private const FILES = __DIR__ . "/../files/";

    private const TRANSLATION = __DIR__ . "/../files/translation/";

    private const TRANSLATE_VARIABLES = ["DEEPL_API_KEY", "GOOGLE_TRANSLATE_API_KEY", "OPENAI_API_KEY", "OPENAI_BASE_URL", "OPENAI_MODEL",
                                         "SUBTITLE_TOOLBOX_TRANSLATE_URL"];

    private ?LocalServer $server = null;

    private string $serverLog = "";


    /** @var array<string, string|false> the values of TRANSLATE_VARIABLES before the test */
    private array $savedVariables = [];


    protected function setUp(): void
    {
        foreach (self::TRANSLATE_VARIABLES as $variable) {
            $this->savedVariables[$variable] = getenv($variable);
            putenv($variable);
        }
        // A translate test without the fake server must not reach a real service.
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL=" . self::closedPortUrl());
    }


    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        if ($this->serverLog !== "") {
            @unlink($this->serverLog);
        }
        foreach ($this->savedVariables as $variable => $value) {
            putenv($value === false ? $variable : "$variable=$value");
        }
    }


    private static function closedPortUrl(): string
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return "http://$name";
    }


    /**
     * Starts the fake translation server, points translate at it and sets the environment variables of $keys.
     *
     * @param array<string, string> $keys
     */
    private function startTranslateServer(array $keys = []): void
    {
        $this->serverLog = tempnam(sys_get_temp_dir(), "translate-log");
        $this->server    = LocalServer::start(self::TRANSLATION . "fake-server.php", ["FAKE_SERVER_LOG" => $this->serverLog]);
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL=" . $this->server->url);
        foreach ($keys as $variable => $key) {
            putenv("$variable=$key");
        }
    }


    /**
     * @return list<array{path: string, key: string, body: array}>
     */
    private function serverRequests(): array
    {
        return array_map(fn (string $line): array => json_decode($line, true), file($this->serverLog, FILE_IGNORE_NEW_LINES));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithDeepLReadsTheKeyOfTheEnvironment(): void
    {
        $this->startTranslateServer(["DEEPL_API_KEY" => "env-key", "GOOGLE_TRANSLATE_API_KEY" => "google-key"]);

        [$code, $stdout, $stderr] = self::runApplication(["translate", self::TRANSLATION . "own_station.srt", "--engine", "deepl",
                                                          "--source-language", "en", "--target-language", "de", "--to", "vtt"]);

        $this->assertSame([0, file_get_contents(self::TRANSLATION . "own_station_de_deepl.vtt"), ""], [$code, $stdout, $stderr]);
        $this->assertSame([["/v2/translate", "env-key", "DE", "EN"]],
                          array_map(fn (array $request): array => [$request["path"], $request["key"], $request["body"]["target_lang"],
                                                                   $request["body"]["source_lang"]], $this->serverRequests()));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithGoogleAndAnApiKeyThatWinsOverTheEnvironment(): void
    {
        $this->startTranslateServer(["GOOGLE_TRANSLATE_API_KEY" => "env-key"]);
        $input  = self::TRANSLATION . "own_station.srt";
        $output = sys_get_temp_dir() . "/translate-" . bin2hex(random_bytes(6)) . ".srt";

        try {
            [$code, $stdout, $stderr] = self::runApplication(["translate", $input, "--engine", "google", "--api-key", "cli-key",
                                                              "--target-language", "fr", "-o", $output]);

            $this->assertSame([0, "$input -> $output\n", ""], [$code, $stdout, $stderr]);
            $this->assertFileEquals(self::TRANSLATION . "own_station_fr_google.srt", $output);
        } finally {
            @unlink($output);
        }
        $request = $this->serverRequests()[0];
        $this->assertSame(["/language/translate/v2", "cli-key"], [$request["path"], $request["key"]]);
        $this->assertSame(["target" => "fr", "format" => "html"], array_slice($request["body"], 1));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithOpenAiSendsNoKeyWithoutOneAndReadsTheModelOfTheEnvironment(): void
    {
        $this->startTranslateServer(["OPENAI_MODEL" => "llama3", "OPENAI_BASE_URL" => "https://llm.example.com/v1", "DEEPL_API_KEY" => "deepl-key"]);

        [$code, $stdout, $stderr] = self::runApplication(["translate", self::TRANSLATION . "own_station.srt", "--engine", "openai",
                                                          "--source-language", "en", "--target-language", "de"]);

        $this->assertSame([0, file_get_contents(self::TRANSLATION . "own_station_de_openai.srt"), ""], [$code, $stdout, $stderr]);
        $requests = $this->serverRequests();
        $this->assertSame([["/v1/chat/completions", "", "llama3", ["system", "user"]]],
                          array_map(fn (array $request): array => [$request["path"], $request["key"], $request["body"]["model"],
                                                                   array_column($request["body"]["messages"], "role")], $requests));
        $this->assertStringStartsWith("You translate subtitle text from en to de.", $requests[0]["body"]["messages"][0]["content"]);
        $this->assertCount(4, json_decode($requests[0]["body"]["messages"][1]["content"], true));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithOpenAiSendsTheKeyOfTheEnvironmentAndFailsOnAnHttpError(): void
    {
        $this->startTranslateServer(["OPENAI_MODEL" => "gpt-9", "OPENAI_API_KEY" => "bad-request"]);

        $this->assertSame(
            [3, "", "stdin: TranslationException (Error #109): The OpenAI-compatible service answered with HTTP 400. " .
                    "The model `gpt-9` does not exist or you do not have access to it.\n"],
            self::runApplication(["translate", "-", "--engine", "openai", "--target-language", "de"], "1\n00:00:01,000 --> 00:00:02,000\nHello\n")
        );
        $this->assertSame("bad-request", $this->serverRequests()[0]["key"]);
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateFailsTheFileOnAnHttpErrorWithExitCode3WithoutPrintingTheKey(): void
    {
        $this->startTranslateServer();

        $this->assertSame(
            [3, "", "stdin: TranslationException (Error #109): DeepL rejected the API key (HTTP 403). Check the key and its plan.\n"],
            self::runApplication(["translate", "-", "--engine", "deepl", "--api-key", "forbidden", "--target-language", "de"],
                                 "1\n00:00:01,000 --> 00:00:02,000\nHello\n")
        );
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithKeepGoingGoesOnAfterAFileFailsAtTheService(): void
    {
        $this->startTranslateServer(["DEEPL_API_KEY" => "forbidden"]);
        $first  = self::TRANSLATION . "own_station.srt";
        $second = self::FILES . "cli/latin1.srt";
        $dir    = sys_get_temp_dir() . "/translate-" . bin2hex(random_bytes(6));

        [$code, $stdout, $stderr] = self::runApplication(["translate", $first, $second, "--engine", "deepl", "--target-language", "de",
                                                          "--encoding", "Windows-1252", "--output-dir", $dir, "--keep-going"]);

        $failure = "TranslationException (Error #109): DeepL rejected the API key (HTTP 403). Check the key and its plan.";
        $this->assertSame([3, "2 files: 0 succeeded, 2 failed.\n", "$first: $failure\n$second: $failure\n"], [$code, $stdout, $stderr]);
        $this->assertCount(2, $this->serverRequests());
        $this->assertDirectoryDoesNotExist($dir);
    }


    public function testTranslateUsageErrorsExitWith2(): void
    {
        putenv("GOOGLE_TRANSLATE_API_KEY=google-key");

        $this->assertSame(self::usageError("translate", "Pass --engine deepl, google or openai."),
                          self::runApplication(["translate", "-", "--target-language", "de"]));
        $this->assertSame(self::usageError("translate", "The option --engine must be deepl, google or openai, got \"bing\"."),
                          self::runApplication(["translate", "-", "--engine", "bing", "--target-language", "de"]));
        $this->assertSame(self::usageError("translate", "Pass --api-key or set the environment variable DEEPL_API_KEY."),
                          self::runApplication(["translate", "-", "--engine", "deepl", "--target-language", "de"]));
        $this->assertSame(self::usageError("translate", "Pass --target-language, for example --target-language fr."),
                          self::runApplication(["translate", "-", "--engine", "google"]));
        $this->assertSame(self::usageError("translate", "The API key is empty. Pass --api-key or set DEEPL_API_KEY."),
                          self::runApplication(["translate", "-", "--engine", "deepl", "--api-key", "", "--target-language", "de"]));
        putenv("DEEPL_API_KEY= ");
        $this->assertSame(self::usageError("translate", "The API key is empty. Pass --api-key or set DEEPL_API_KEY."),
                          self::runApplication(["translate", "-", "--engine", "deepl", "--target-language", "de"]));
        $this->assertSame(self::usageError("translate", "The API key has a control character, or a space at the start or end. Pass the key without them."),
                          self::runApplication(["translate", "-", "--engine", "google", "--api-key", "key\r", "--target-language", "de"]));
        $this->assertSame(self::usageError("translate", "2 input files need --output-dir DIR. One input file goes to standard output or to -o FILE."),
                          self::runApplication(["translate", self::TRANSLATION . "own_station.srt", self::FILES . "cli/latin1.srt",
                                                "--engine", "google", "--target-language", "fr"]));
        $this->assertSame(self::usageError("translate", "Set the environment variable OPENAI_MODEL, for example OPENAI_MODEL=gpt-4o-mini."),
                          self::runApplication(["translate", "-", "--engine", "openai", "--target-language", "de"]));
        putenv("OPENAI_MODEL= ");
        $this->assertSame(self::usageError("translate", "The environment variable OPENAI_MODEL is empty. Set it to a model name, for example gpt-4o-mini."),
                          self::runApplication(["translate", "-", "--engine", "openai", "--target-language", "de"]));
        putenv("OPENAI_MODEL=llama3");
        putenv("OPENAI_BASE_URL=localhost:11434/v1");
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL");
        $this->assertSame(self::usageError("translate", "The environment variable OPENAI_BASE_URL must start with http:// or https://, got \"localhost:11434/v1\"."),
                          self::runApplication(["translate", "-", "--engine", "openai", "--target-language", "de"]));
        $this->assertSame(self::usageError("translate", "The API key is empty. Pass --api-key or set OPENAI_API_KEY."),
                          self::runApplication(["translate", "-", "--engine", "openai", "--api-key", "", "--target-language", "de"]));
    }


    public function testTheTestUrlWorksOnlyForThisMachine(): void
    {
        $cases = [
            "http://127.0.0.1:8080/x?y=1"         => "http://127.0.0.1:8080",
            "https://localhost"                   => "https://localhost",
            "http://[::1]:9000/"                  => "http://[::1]:9000",
            "http://example.com"                  => null,
            "http://127.0.0.1.example.com"        => null,
            "http://localhost@example.com/"       => null,
            "ftp://127.0.0.1"                     => null,
            "127.0.0.1:8080"                      => null,
        ];
        foreach ($cases as $url => $expected) {
            putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL=$url");
            $this->assertSame($expected, TranslateCommand::localTestUrl(), $url);
        }
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL");
        $this->assertNull(TranslateCommand::localTestUrl());
    }


    public function testTranslateHelpListsTheOptionsButNoKeyAndNoTestUrl(): void
    {
        putenv("DEEPL_API_KEY=secret-env-key");

        [$code, $stdout, $stderr] = self::runApplication(["translate", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Usage: subtitle-toolbox translate <input>... --engine deepl|google|openai\n" .
                                      "                                  --target-language CODE\n" .
                                      "                                  [--source-language CODE] [--api-key KEY]\n" .
                                      "                                  [options]\n", $stdout);
        preg_match_all('/^  (?:-\w, )?--([\w-]+)/m', $stdout, $matches);
        $this->assertSame(["engine", "api-key", "source-language", "target-language", "to", "output", "output-dir"], array_slice($matches[1], 0, 7));
        $this->assertNotContains("in-place", $matches[1]);
        $this->assertStringNotContainsString("secret-env-key", $stdout);
        $this->assertStringNotContainsString("SUBTITLE_TOOLBOX_TRANSLATE_URL", $stdout);
        $this->assertStringContainsString("  translate  ", self::runApplication(["--help"])[1]);
    }


    public function testWithoutTheCurlExtensionTranslateExitsWith2AndOtherCommandsRun(): void
    {
        $binary = __DIR__ . "/../../bin/subtitle-toolbox";
        $input  = self::TRANSLATION . "own_station.srt";

        $this->assertSame(
            self::usageError("translate", "PHP has no ext-curl, which the translation engines need. Install the PHP curl extension."),
            WithoutCurl::run([$binary, "translate", $input, "--engine", "deepl", "--api-key", "key", "--target-language", "de"])
        );
        [$code, $stdout, $stderr] = WithoutCurl::run([$binary, "convert", $input, "--to", "vtt", "--output", "-"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("WEBVTT", ltrim($stdout, "\u{FEFF}"));
    }
}
