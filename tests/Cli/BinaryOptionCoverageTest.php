<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Container\Matroska\MkvFixtureWriter;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Formatters\SccFitChange;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Http\LocalServer;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../Http/LocalServer.php";
require_once __DIR__ . "/../files/mkv/generator/MkvFixtureWriter.php";

/**
 * Checks the command options that the other CLI tests do not use, each against the same call without the option.
 */
class BinaryOptionCoverageTest extends CliTestCase
{
    private const STATION = __DIR__ . "/../files/translation/own_station.srt";

    private const URL_VARIABLE = "SUBTITLE_TOOLBOX_TRANSLATE_URL";

    private ?LocalServer $server = null;

    private string|false $savedUrl = false;


    protected function setUp(): void
    {
        parent::setUp();
        $this->savedUrl = getenv(self::URL_VARIABLE);
    }


    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv($this->savedUrl === false ? self::URL_VARIABLE : self::URL_VARIABLE . "=$this->savedUrl");
        parent::tearDown();
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function sdhKeepOptions(): array
    {
        return [
            "--sdh-keep-square-brackets" => ["--sdh-keep-square-brackets", "own_sdh_removed_keep_square_brackets.srt"],
            "--sdh-keep-music-lines"     => ["--sdh-keep-music-lines", "own_sdh_removed_keep_music_lines.srt"],
            "--sdh-any-case-labels"      => ["--sdh-any-case-labels", "own_sdh_removed_any_case_labels.srt"],
            "--sdh-keep-parentheses"     => ["--sdh-keep-parentheses", "own_sdh_removed_keep_parentheses.srt"],
            "--sdh-keep-speaker-labels"  => ["--sdh-keep-speaker-labels", "own_sdh_removed_keep_speaker_labels.srt"],
        ];
    }


    #[DataProvider("sdhKeepOptions")]
    public function testConvertSdhKeepOption(string $option, string $golden): void
    {
        copy(self::FILES . "hearing-impaired/own_sdh.srt", "$this->dir/sdh.srt");

        $this->assertFileNotEquals(self::FILES . "hearing-impaired/own_sdh_removed.srt", self::FILES . "hearing-impaired/$golden");
        $this->assertSame([0, file_get_contents(self::FILES . "hearing-impaired/$golden"), ""],
                          $this->runCli(["convert", "sdh.srt", "--to", "srt", "-o", "-", "--sdh", $option]));
    }


    /**
     * @return array<string, array{list<string>}>
     */
    public static function retimeOptions(): array
    {
        return [
            "--shift --shift-after" => [["--shift", "2", "--shift-after", "4"]],
            "--from-fps --to-fps"   => [["--from-fps", "25", "--to-fps", "23.976"]],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("retimeOptions")]
    public function testConvertRetimes(array $options): void
    {
        [$code, $expected] = $this->runCli(["convert", "shop.vtt", "--to", "srt", ...$options]);
        $this->assertSame(0, $code);
        $this->assertNotSame($this->runCli(["convert", "shop.vtt", "--to", "srt", "--shift", "0"])[1], $expected);
        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "shop.vtt", "--to", "srt", "-o", "-", ...$options]));
    }


    /**
     * @return array<string, array{list<string>, ValidationRules}>
     */
    public static function validateRuleOptions(): array
    {
        return [
            "--max-lines"        => [["--max-lines", "1"], new ValidationRules(maxLinesPerCue: 1)],
            "--min-duration"     => [["--min-duration", "00:00:01.5"], new ValidationRules(minDuration: 1.5)],
            "--max-duration"     => [["--max-duration", "0:00:02"], new ValidationRules(maxDuration: 2)],
            "--min-gap"          => [["--min-gap", "00:00.5"], new ValidationRules(minGap: 0.5)],
            "--check-empty-cues" => [["--check-empty-cues"], new ValidationRules(noEmptyCues: true)],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("validateRuleOptions")]
    public function testValidateRuleOption(array $options, ValidationRules $rules): void
    {
        copy(self::FILES . "validation/own_netflix_checks.srt", "$this->dir/checks.srt");
        $expected = array_map(fn (ValidationViolation $violation): array => [$violation->rule->value, $violation->cueIndex],
                             Subtitle::load("$this->dir/checks.srt", Format::SubRip)->validate($rules));
        $this->assertNotSame([], $expected);

        [$code, $stdout, $stderr] = $this->runCli(["validate", "checks.srt", "--json", ...$options]);
        $violations = json_decode($stdout, true)[0]["violations"];
        $this->assertSame([1, ""], [$code, $stderr]);
        $this->assertSame($expected, array_map(fn (array $violation): array => [$violation["rule"], $violation["cueIndex"]], $violations));
    }


    public function testSyncSplitPenalty(): void
    {
        copy(self::FILES . "sync/own_target_de_25fps.srt", "$this->dir/de.srt");
        copy(self::FILES . "sync/own_reference_en_tv_break.srt", "$this->dir/tv.srt");

        [$code, $stdout, $stderr] = $this->runCli(["sync", "de.srt", "--reference", "tv.srt", "--min-offset", "-180", "--max-offset", "180",
                                                      "--max-splits", "2", "--split-penalty", "5"]);

        $this->assertSame(0, $code);
        $this->assertStringEqualsFile(self::FILES . "sync/own_target_de_split_penalty_synced.srt", $stdout);
        $this->assertStringNotContainsString("from 414.32 s", $stderr);
        $this->assertSame(self::usageError("sync", "The option --split-penalty must not be negative."),
                          $this->runCli(["sync", "de.srt", "--reference", "tv.srt", "--split-penalty", "-1"]));
    }


    public function testDiffIgnoreWhitespace(): void
    {
        copy(self::FILES . "diff/own_original.srt", "$this->dir/v1.srt");
        copy(self::FILES . "diff/own_edited.srt", "$this->dir/v2.srt");

        $this->assertSame([1, file_get_contents(self::FILES . "diff/own_report_ignore_whitespace.json"), ""],
                          $this->runCli(["diff", "v1.srt", "v2.srt", "--json", "--ignore-whitespace"]));
    }


    public function testRetimeKeepsTheWordTimestamps(): void
    {
        copy(self::FILES . "resegmenting/own_whisper_long_segments.json", "$this->dir/lecture.json");
        $expected = Subtitle::load("$this->dir/lecture.json", Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]))
                            ->shift(1);

        $this->assertSame([0, $expected->toString(Format::WebVtt), ""],
                          $this->runCli(["convert", "lecture.json", "--shift", "1", "--to", "vtt", "--word-timestamps"]));
        $this->assertStringNotContainsString("<00:", $this->runCli(["convert", "lecture.json", "--shift", "1", "--to", "vtt"])[1]);
    }


    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function keepGoingCommands(): array
    {
        return [
            "convert" => ["convert", ["--shift", "1"]],
            "sync"   => ["sync", ["--reference", "late.srt"]],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("keepGoingCommands")]
    public function testKeepGoingAfterAFailedFile(string $command, array $options): void
    {
        copy(self::STATION, "$this->dir/station.srt");
        file_put_contents("$this->dir/late.srt", Subtitle::load(self::STATION, Format::SubRip)->shift(2)->toString(Format::SubRip));

        $this->assertSame([3, "2 files: 0 succeeded, 1 failed, 1 skipped.\n",
                           "broken.srt: ParsingException (Error #100): Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\". (line 6)\n" .
                           FileCommand::LENIENT_HINT . "\n" .
                           "Stopped at the first failure. Pass --keep-going to process the other files.\n"],
                          $this->runCli([$command, "broken.srt", "station.srt", ...$options, "--output-dir", "out"]));
        $this->assertDirectoryDoesNotExist("$this->dir/out");

        [$code, $stdout] = $this->runCli([$command, "broken.srt", "station.srt", ...$options, "--output-dir", "out", "--keep-going"]);
        $this->assertSame([3, "station.srt -> out/station.srt\n2 files: 1 succeeded, 1 failed.\n"], [$code, $stdout]);
        $this->assertFileExists("$this->dir/out/station.srt");
    }


    /**
     * @return array<string, array{string}>
     */
    public static function encodingCommands(): array
    {
        return [
            "convert --encoding"   => ["convert"],
            "info --encoding"     => ["info"],
            "validate --encoding" => ["validate"],
            "sync --encoding"     => ["sync"],
            "diff --encoding"     => ["diff"],
            "dual --encoding"     => ["dual"],
            "hls --encoding"      => ["hls"],
        ];
    }


    #[DataProvider("encodingCommands")]
    public function testEncodingOption(string $command): void
    {
        $utf32 = fn (string $text): string => mb_convert_encoding(ltrim($text, self::BOM), "UTF-32LE", "UTF-8");

        [$without, $with] = $this->runWithAndWithoutOption($command, ["--encoding", "UTF-32LE"], $utf32);

        $this->assertSame(3, $this->runCli(self::commandLine($command, "station.srt"))[0]);
        $with[1] = str_replace("Encoding:              UTF-32LE", "Encoding:              UTF-8", $with[1]);
        $this->assertSame($without, $with);
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function frameRateOptions(): array
    {
        return [
            "info --fps"            => ["info", "--fps"],
            "validate --input-fps"  => ["validate", "--input-fps"],
            "sync --input-fps"      => ["sync", "--input-fps"],
            "sync --fps"            => ["sync", "--fps"],
            "diff --fps"            => ["diff", "--fps"],
            "translate --input-fps" => ["translate", "--input-fps"],
            "translate --fps"       => ["translate", "--fps"],
            "dual --fps"            => ["dual", "--fps"],
            "hls --input-fps"       => ["hls", "--input-fps"],
            "hls --fps"             => ["hls", "--fps"],
        ];
    }


    /**
     * Compares MicroDVD input without a frame rate line and the option to MicroDVD input with a frame rate line.
     */
    #[DataProvider("frameRateOptions")]
    public function testFrameRateOption(string $command, string $option): void
    {
        $microDvd = fn (string $text): string => Subtitle::fromString($text, Format::SubRip)
                                                         ->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(25)]));
        $withFrameRateLine = fn (string $text): string => "{1}{1}25\n" . $microDvd($text);

        [$without, $with] = $this->runWithAndWithoutOption($command, [$option, "25"], $microDvd, $withFrameRateLine, "station.sub");

        $this->assertSame(3, $this->runCli(self::commandLine($command, "station.sub", "sub"))[0]);
        $this->assertSame($without, $with);
    }


    /**
     * @return array<string, array{string}>
     */
    public static function lenientCommands(): array
    {
        return [
            "convert --lenient"    => ["convert"],
            "sync --lenient"      => ["sync"],
            "translate --lenient" => ["translate"],
            "dual --lenient"      => ["dual"],
            "hls --lenient"       => ["hls"],
        ];
    }


    #[DataProvider("lenientCommands")]
    public function testLenientOption(string $command): void
    {
        $broken = fn (string $text): string => rtrim($text) . "\n\n99\n00:01:00,000 => 00:01:02,000\nBroken\n";

        [$without, $with] = $this->runWithAndWithoutOption($command, ["--lenient"], $broken);

        $this->assertStringContainsString("Block #", $with[2]);
        [$with[2], $without[2]] = ["", ""];
        $this->assertSame(3, $this->runCli(self::commandLine($command, "station.srt"))[0]);
        $this->assertSame($without, $with);
    }


    /**
     * @return array<string, array{string}>
     */
    public static function trackCommands(): array
    {
        return [
            "convert --track"    => ["convert"],
            "validate --track"  => ["validate"],
            "sync --track"      => ["sync"],
            "translate --track" => ["translate"],
            "hls --track"       => ["hls"],
        ];
    }


    #[DataProvider("trackCommands")]
    public function testTrackOption(string $command): void
    {
        file_put_contents("$this->dir/station.mkv", self::mkv(file_get_contents(self::STATION)));

        [$without, $with] = $this->runWithAndWithoutOption($command, ["--track", "3"], variantInput: "station.mkv");

        $this->assertSame(3, $this->runCli(self::commandLine($command, "station.mkv"))[0]);
        $this->assertSame($without, $with);
    }


    /**
     * @return array<string, array{string}>
     */
    public static function fromCommands(): array
    {
        return [
            "sync --from"      => ["sync"],
            "diff --from"      => ["diff"],
            "translate --from" => ["translate"],
            "hls --from"       => ["hls"],
        ];
    }


    #[DataProvider("fromCommands")]
    public function testFromOption(string $command): void
    {
        [$without, $with] = $this->runWithAndWithoutOption($command, ["--from", "srt"]);

        $this->assertSame(3, $this->runCli([...self::commandLine($command, "station.srt"), "--from", "microdvd"])[0]);
        $this->assertSame($without, $with);
    }


    /**
     * @return array<string, array{string}>
     */
    public static function wordTimestampsCommands(): array
    {
        return [
            "convert --word-timestamps"    => ["convert"],
            "info --word-timestamps"      => ["info"],
            "validate --word-timestamps"  => ["validate"],
            "sync --word-timestamps"      => ["sync"],
            "diff --word-timestamps"      => ["diff"],
            "translate --word-timestamps" => ["translate"],
            "dual --word-timestamps"      => ["dual"],
            "hls --word-timestamps"       => ["hls"],
        ];
    }


    /**
     * The option changes nothing on SubRip input.
     */
    #[DataProvider("wordTimestampsCommands")]
    public function testWordTimestampsOption(string $command): void
    {
        [$without, $with] = $this->runWithAndWithoutOption($command, ["--word-timestamps"]);

        $this->assertSame($without, $with);
    }


    /**
     * @return array<string, array{string}>
     */
    public static function sccRollUpCommands(): array
    {
        return [
            "convert --scc-roll-up"    => ["convert"],
            "info --scc-roll-up"      => ["info"],
            "validate --scc-roll-up"  => ["validate"],
            "sync --scc-roll-up"      => ["sync"],
            "diff --scc-roll-up"      => ["diff"],
            "translate --scc-roll-up" => ["translate"],
            "dual --scc-roll-up"      => ["dual"],
            "hls --scc-roll-up"       => ["hls"],
        ];
    }


    /**
     * The option changes nothing on SubRip input.
     */
    #[DataProvider("sccRollUpCommands")]
    public function testSccRollUpOption(string $command): void
    {
        [$without, $with] = $this->runWithAndWithoutOption($command, ["--scc-roll-up", "screen"]);

        $this->assertSame($without, $with);
    }


    /**
     * Runs the command without the option on the plain input, then with the option on the variant input. Each run
     * first writes the station fixture, its edited copy and its late copy through the transform of the run. Returns
     * both results with the input name replaced by INPUT, and for hls with the written segment files.
     *
     * @param list<string> $options
     *
     * @return array{list<mixed>, list<mixed>} the result without and the result with the option
     */
    private function runWithAndWithoutOption(string $command, array $options, ?callable $variant = null, ?callable $plain = null,
                                             string $input = "station.srt", ?string $variantInput = null): array
    {
        $this->prepareCommand($command);
        $unchanged = fn (string $text): string => $text;
        $extension = pathinfo($input, PATHINFO_EXTENSION);
        $writeInputsAndRun = function (callable $transform, string $input, array $options) use ($command, $extension): array {
            $this->writeStationInputs($transform, $extension);
            $result = array_map(fn (int|string $value): int|string => is_string($value) ? str_replace($input, "INPUT", $value) : $value,
                                $this->runCli([...self::commandLine($command, $input, $extension), ...$options]));

            return $command === "hls" ? [...$result, $this->takeDirectory("hls")] : $result;
        };

        $without = $writeInputsAndRun($plain ?? $unchanged, $input, []);
        $with    = $writeInputsAndRun($variant ?? $unchanged, $variantInput ?? $input, $options);
        $this->assertContains($without[0], [0, 1], $without[2]);

        return [$without, $with];
    }


    /**
     * @return array<string, array{string}>
     */
    public static function writingCommands(): array
    {
        return [
            "convert"   => ["convert"],
            "sync"      => ["sync"],
            "translate" => ["translate"],
            "dual"      => ["dual"],
        ];
    }


    #[DataProvider("writingCommands")]
    public function testOutputFpsWritesMicroDvdAtThatRate(string $command): void
    {
        $srt      = $this->runOnStation($command);
        $expected = Subtitle::fromString($srt, Format::SubRip)->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(25)]));

        $this->assertSame(3, $this->runCli(self::commandLine($command, "station.srt", to: "microdvd"))[0]);
        $this->assertSame([0, $expected],
                          array_slice($this->runCli([...self::commandLine($command, "station.srt", to: "microdvd"), "--output-fps", "25"]), 0, 2));
    }


    #[DataProvider("writingCommands")]
    public function testLineEndingCrlfWritesCrlf(string $command): void
    {
        $srt = $this->runOnStation($command);

        $this->assertSame([0, str_replace("\n", "\r\n", $srt)],
                          array_slice($this->runCli([...self::commandLine($command, "station.srt", "srt"), "--line-ending", "crlf"]), 0, 2));
    }


    #[DataProvider("writingCommands")]
    public function testNoBomDropsTheBomOfSubRip(string $command): void
    {
        $srt = $this->runOnStation($command);

        $this->assertStringStartsWith(self::BOM, $srt);
        $this->assertSame([0, substr($srt, 3)], array_slice($this->runCli([...self::commandLine($command, "station.srt", "srt"), "--no-bom"]), 0, 2));
    }


    #[DataProvider("writingCommands")]
    public function testBomAddsABomToJson(string $command): void
    {
        $this->runOnStation($command);

        [, $json] = $this->runCli(self::commandLine($command, "station.srt", to: "json"));
        $this->assertStringStartsWith("{", $json);
        $this->assertSame([0, self::BOM . $json], array_slice($this->runCli([...self::commandLine($command, "station.srt", to: "json"), "--bom"]), 0, 2));
    }


    /**
     * @return array<string, array{string, int, bool}>
     */
    public static function sccFitCommands(): array
    {
        return [
            "convert"   => ["convert", 3, true],
            "sync"      => ["sync", 3, true],
            "translate" => ["translate", 0, false],
            "dual"      => ["dual", 3, true],
        ];
    }


    /**
     * The fake translation server returns a fixed German text that SCC can hold, so translate needs no --scc-fit.
     */
    #[DataProvider("sccFitCommands")]
    public function testSccFitPrintsEachChangeAndWritesTheFittedScc(string $command, int $codeWithoutFit, bool $transliterates): void
    {
        $this->runOnStation($command);
        file_put_contents("$this->dir/fit.srt", str_replace("Basel", "\u{160}ibenik", file_get_contents(self::STATION)));
        [, $fitSrt] = $this->runCli(self::commandLine($command, "fit.srt", "srt"));
        $report     = (new SccFormatter())->formatWithReport(Subtitle::fromString($fitSrt, Format::SubRip),
                                                             new WriteOptions(formats: [new SccWriteOptions(fit: true)]));
        $changes    = implode("", array_map(fn (SccFitChange $change): string => "fit.srt: $change->message ({$change->action->value})\n", $report->changes));

        $this->assertSame($codeWithoutFit, $this->runCli(self::commandLine($command, "fit.srt", to: "scc"))[0]);
        [$code, $scc, $stderr] = $this->runCli([...self::commandLine($command, "fit.srt", to: "scc"), "--scc-fit"]);
        $this->assertSame([0, $report->content], [$code, $scc]);
        $this->assertTrue(str_ends_with($stderr, $changes), $stderr);
        if ($transliterates) {
            $this->assertStringContainsString("replaced \"\u{160}\" with \"S\". (transliterated)\n", $changes);
        }
        $this->assertSame(0, $this->runCli([...self::commandLine($command, "station.srt", "srt"), "--scc-fit"])[0]);
    }


    #[DataProvider("writingCommands")]
    public function testSkipImageCuesDropsTheImageCues(string $command): void
    {
        $srt  = $this->runOnStation($command);
        $data = json_decode(Subtitle::fromString(file_get_contents(self::STATION), Format::SubRip)->toString(Format::Json), true);
        $image = json_decode(file_get_contents(self::FILES . "json/real/own_image_cues.json"), true);
        $data["cues"] = [...$data["cues"], ...array_values(array_filter($image["cues"], fn (array $cue): bool => $cue["lines"] === []))];
        file_put_contents("$this->dir/images.json", json_encode($data));

        $this->assertSame(3, $this->runCli(self::commandLine($command, "images.json", "srt"))[0]);
        $this->assertSame([0, $srt], array_slice($this->runCli([...self::commandLine($command, "images.json", "srt"), "--skip-image-cues"]), 0, 2));
    }


    /**
     * Writes the station fixture and its copies, runs the command on it without options and returns the SubRip output.
     */
    private function runOnStation(string $command): string
    {
        $this->prepareCommand($command);
        $this->writeStationInputs(fn (string $text): string => $text, "srt");

        [$code, $srt, $stderr] = $this->runCli(self::commandLine($command, "station.srt", "srt"));
        $this->assertSame(0, $code, $stderr);

        return $srt;
    }


    /**
     * Writes the station fixture as station, its copy with one changed word as edited, and its copy 2 s later as late.
     */
    private function writeStationInputs(callable $transform, string $extension): void
    {
        $station = file_get_contents(self::STATION);
        $files   = [
            "station" => $station,
            "edited"  => str_replace("Basel", "Bern", $station),
            "late"    => Subtitle::fromString($station, Format::SubRip)->shift(2)->toString(Format::SubRip),
        ];
        foreach ($files as $name => $text) {
            file_put_contents("$this->dir/$name.$extension", $transform($text));
        }
    }


    /**
     * Returns the arguments of a call of $command on $input. $secondExtension is the extension of the second file of
     * diff and dual and of the reference of sync. $to is the output format of a command that writes a subtitle.
     *
     * @return list<string>
     */
    private static function commandLine(string $command, string $input, string $secondExtension = "srt", string $to = "srt"): array
    {
        return match ($command) {
            "convert"   => ["convert", $input, "--shift", "1", "--to", $to],
            "info"      => ["info", $input],
            "validate"  => ["validate", $input, "--preset", "bbc"],
            "sync"      => ["sync", $input, "--reference", "late.$secondExtension", "--to", $to],
            "diff"      => ["diff", $input, "edited.$secondExtension"],
            "translate" => ["translate", $input, "--engine", "deepl", "--api-key", "key", "--target-language", "de", "--to", $to],
            "dual"      => ["dual", "--primary", $input, "--secondary", "edited.$secondExtension", "--to", $to],
            "hls"       => ["hls", $input, "--output-dir", "hls"],
        };
    }


    private function prepareCommand(string $command): void
    {
        if ($command !== "translate") {
            return;
        }
        if (!extension_loaded("curl")) {
            $this->markTestSkipped("translate needs the PHP extension curl.");
        }
        $this->server = LocalServer::start(self::FILES . "translation/fake-server.php");
        putenv(self::URL_VARIABLE . "=" . $this->server->url);
    }


    /**
     * Returns the files of a directory below the temporary directory by name, and deletes them.
     *
     * @return array<string, string>
     */
    private function takeDirectory(string $name): array
    {
        $files = [];
        foreach (glob("$this->dir/$name/*") as $path) {
            $files[basename($path)] = file_get_contents($path);
            unlink($path);
        }
        if (is_dir("$this->dir/$name")) {
            rmdir("$this->dir/$name");
        }
        ksort($files);

        return $files;
    }


    /**
     * Builds an MKV file with the SubRip text as S_TEXT/UTF8 track 3 and one more subtitle track, so --track is needed.
     */
    private static function mkv(string $srt): string
    {
        $tracks = MkvFixtureWriter::trackEntry(["number" => 3, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8"]) .
                  MkvFixtureWriter::trackEntry(["number" => 4, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8"]);
        $blocks = [MkvFixtureWriter::blockGroup(4, 0, "Other track", 1000)];
        $cues = Subtitle::fromString($srt, Format::SubRip)->getCues();
        foreach (explode("\n\n", trim(str_replace("\r\n", "\n", ltrim($srt, self::BOM)))) as $index => $block) {
            $start    = (int)round($cues[$index]->getStart() * 1000);
            $text     = implode("\n", array_slice(explode("\n", $block), 2));
            $blocks[] = MkvFixtureWriter::blockGroup(3, $start, $text, (int)round($cues[$index]->getEnd() * 1000) - $start);
        }

        return MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::info() .
               MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, $tracks) . MkvFixtureWriter::cluster(0, $blocks));
    }
}
