<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Container\Matroska\MkvFixtureWriter;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Formatters\SccFitChange;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Http\LocalServer;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Tests\Support\BinaryTestCase;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../Http/LocalServer.php";
require_once __DIR__ . "/../files/mkv/generator/MkvFixtureWriter.php";

/**
 * Checks the command options that the other CLI tests do not use, each against the same call without the option.
 */
class BinaryOptionCoverageTest extends BinaryTestCase
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


    public function testConvertSdhKeepOptions(): void
    {
        copy(self::FILES . "hearing-impaired/own_sdh.srt", "$this->dir/sdh.srt");
        $expected = function (HearingImpairedOptions $options): string {
            $subtitle = Subtitle::load("$this->dir/sdh.srt", Format::SubRip);
            HearingImpairedRemover::apply($subtitle, $options);

            return $subtitle->toString(Format::SubRip);
        };
        $default = $expected(new HearingImpairedOptions());

        foreach ([
            "--sdh-keep-square-brackets" => new HearingImpairedOptions(squareBrackets: false),
            "--sdh-keep-music-lines"     => new HearingImpairedOptions(musicOnlyLines: false),
            "--sdh-any-case-labels"      => new HearingImpairedOptions(speakerLabelsUpperCaseOnly: false),
        ] as $option => $options) {
            $this->assertNotSame($default, $expected($options), $option);
            $this->assertSame([0, $expected($options), ""], $this->runBinary(["convert", "sdh.srt", "--to", "srt", "-o", "-", "--sdh", $option]), $option);
        }
    }


    public function testConvertRetimesLikeRetime(): void
    {
        foreach ([["--shift", "2", "--shift-after", "4"], ["--from-fps", "25", "--to-fps", "23.976"]] as $options) {
            [$code, $expected] = $this->runBinary(["retime", "shop.vtt", "--to", "srt", ...$options]);
            $this->assertSame(0, $code);
            $this->assertNotSame($this->runBinary(["retime", "shop.vtt", "--to", "srt", "--shift", "0"])[1], $expected);
            $this->assertSame([0, $expected, ""], $this->runBinary(["convert", "shop.vtt", "--to", "srt", "-o", "-", ...$options]));
        }
    }


    public function testValidateRuleOptions(): void
    {
        copy(self::FILES . "validation/own_netflix_checks.srt", "$this->dir/checks.srt");
        $subtitle = Subtitle::load("$this->dir/checks.srt", Format::SubRip);

        foreach ([
            "--max-lines"        => [["--max-lines", "1"], new ValidationRules(maxLinesPerCue: 1)],
            "--min-duration"     => [["--min-duration", "00:00:01.5"], new ValidationRules(minDuration: 1.5)],
            "--max-duration"     => [["--max-duration", "0:00:02"], new ValidationRules(maxDuration: 2)],
            "--min-gap"          => [["--min-gap", "00:00.5"], new ValidationRules(minGap: 0.5)],
            "--check-empty-cues" => [["--check-empty-cues"], new ValidationRules(noEmptyCues: true)],
        ] as $option => [$options, $rules]) {
            $expected = array_map(fn (ValidationViolation $violation): array => [$violation->rule->value, $violation->cueIndex],
                                 $subtitle->validate($rules));
            $this->assertNotSame([], $expected, $option);

            [$code, $stdout, $stderr] = $this->runBinary(["validate", "checks.srt", "--json", ...$options]);
            $violations = json_decode($stdout, true)[0]["violations"];
            $this->assertSame([1, ""], [$code, $stderr], $option);
            $this->assertSame($expected, array_map(fn (array $violation): array => [$violation["rule"], $violation["cueIndex"]], $violations), $option);
        }
    }


    public function testSyncSplitPenalty(): void
    {
        copy(self::FILES . "sync/own_target_de_25fps.srt", "$this->dir/de.srt");
        copy(self::FILES . "sync/own_reference_en_tv_break.srt", "$this->dir/tv.srt");
        $expected = Subtitle::load("$this->dir/de.srt", Format::SubRip);
        ReferenceSync::apply($expected, new ReferenceSyncOptions(Subtitle::load("$this->dir/tv.srt", Format::SubRip), -180, 180, maxSplits: 2,
                                                                  splitPenalty: 5));

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "tv.srt", "--min-offset", "-180", "--max-offset", "180",
                                                      "--max-splits", "2", "--split-penalty", "5"]);

        $this->assertSame([0, $expected->toString(Format::SubRip)], [$code, $stdout]);
        $this->assertStringNotContainsString("from 414.32 s", $stderr);
        $this->assertStringNotEqualsFile(self::FILES . "sync/own_target_de_split_synced.srt", $stdout);
        $this->assertSame(2, $this->runBinary(["sync", "de.srt", "--reference", "tv.srt", "--split-penalty", "-1"])[0]);
    }


    public function testDiffIgnoreWhitespace(): void
    {
        $old      = Subtitle::load(self::FILES . "diff/own_original.srt", Format::SubRip);
        $new      = Subtitle::load(self::FILES . "diff/own_edited.srt", Format::SubRip);
        $expected = SubtitleDiff::toText(SubtitleDiff::compare($old, $new, new SubtitleDiffOptions(ignoreWhitespace: true)));

        $this->assertNotSame(SubtitleDiff::toText(SubtitleDiff::compare($old, $new)), $expected);
        $this->assertSame([1, $expected, ""],
                          $this->runBinary(["diff", self::FILES . "diff/own_original.srt", self::FILES . "diff/own_edited.srt", "--ignore-whitespace"]));
    }


    public function testRetimeKeepsTheWordTimestamps(): void
    {
        copy(self::FILES . "resegmenting/own_whisper_long_segments.json", "$this->dir/lecture.json");
        $expected = Subtitle::load("$this->dir/lecture.json", Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]))
                            ->shift(1);

        $this->assertSame([0, $expected->toString(Format::WebVtt), ""],
                          $this->runBinary(["retime", "lecture.json", "--shift", "1", "--to", "vtt", "--word-timestamps"]));
        $this->assertStringNotContainsString("<00:", $this->runBinary(["retime", "lecture.json", "--shift", "1", "--to", "vtt"])[1]);
    }


    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function keepGoingCommands(): array
    {
        return [
            "retime" => ["retime", ["--shift", "1"]],
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
                          $this->runBinary([$command, "broken.srt", "station.srt", ...$options, "--output-dir", "out"]));
        $this->assertDirectoryDoesNotExist("$this->dir/out");

        [$code, $stdout] = $this->runBinary([$command, "broken.srt", "station.srt", ...$options, "--output-dir", "out", "--keep-going"]);
        $this->assertSame([3, "station.srt -> out/station.srt\n2 files: 1 succeeded, 1 failed.\n"], [$code, $stdout]);
        $this->assertFileExists("$this->dir/out/station.srt");
    }


    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function inputOptions(): array
    {
        return [
            "retime --encoding"         => ["retime", ["--encoding", "UTF-32LE"]],
            "retime --lenient"          => ["retime", ["--lenient"]],
            "retime --word-timestamps"  => ["retime", ["--word-timestamps"]],
            "retime --scc-roll-up" => ["retime", ["--scc-roll-up", "screen"]],
            "retime --track"            => ["retime", ["--track", "3"]],
            "info --encoding"           => ["info", ["--encoding", "UTF-32LE"]],
            "info --fps"                => ["info", ["--fps", "25"]],
            "info --word-timestamps"    => ["info", ["--word-timestamps"]],
            "info --scc-roll-up" => ["info", ["--scc-roll-up", "screen"]],
            "validate --encoding"       => ["validate", ["--encoding", "UTF-32LE"]],
            "validate --input-fps"      => ["validate", ["--input-fps", "25"]],
            "validate --word-timestamps" => ["validate", ["--word-timestamps"]],
            "validate --scc-roll-up" => ["validate", ["--scc-roll-up", "screen"]],
            "validate --track"          => ["validate", ["--track", "3"]],
            "sync --from"               => ["sync", ["--from", "srt"]],
            "sync --encoding"           => ["sync", ["--encoding", "UTF-32LE"]],
            "sync --lenient"            => ["sync", ["--lenient"]],
            "sync --input-fps"          => ["sync", ["--input-fps", "25"]],
            "sync --fps"                => ["sync", ["--fps", "25"]],
            "sync --word-timestamps"    => ["sync", ["--word-timestamps"]],
            "sync --scc-roll-up" => ["sync", ["--scc-roll-up", "screen"]],
            "sync --track"              => ["sync", ["--track", "3"]],
            "diff --from"               => ["diff", ["--from", "srt"]],
            "diff --encoding"           => ["diff", ["--encoding", "UTF-32LE"]],
            "diff --fps"                => ["diff", ["--fps", "25"]],
            "diff --word-timestamps"    => ["diff", ["--word-timestamps"]],
            "diff --scc-roll-up" => ["diff", ["--scc-roll-up", "screen"]],
            "translate --from"          => ["translate", ["--from", "srt"]],
            "translate --lenient"       => ["translate", ["--lenient"]],
            "translate --input-fps"     => ["translate", ["--input-fps", "25"]],
            "translate --fps"           => ["translate", ["--fps", "25"]],
            "translate --word-timestamps" => ["translate", ["--word-timestamps"]],
            "translate --scc-roll-up" => ["translate", ["--scc-roll-up", "screen"]],
            "translate --track"         => ["translate", ["--track", "3"]],
            "dual --encoding"           => ["dual", ["--encoding", "UTF-32LE"]],
            "dual --lenient"            => ["dual", ["--lenient"]],
            "dual --fps"                => ["dual", ["--fps", "25"]],
            "dual --word-timestamps"    => ["dual", ["--word-timestamps"]],
            "dual --scc-roll-up" => ["dual", ["--scc-roll-up", "screen"]],
            "hls --from"                => ["hls", ["--from", "srt"]],
            "hls --encoding"            => ["hls", ["--encoding", "UTF-32LE"]],
            "hls --lenient"             => ["hls", ["--lenient"]],
            "hls --input-fps"           => ["hls", ["--input-fps", "25"]],
            "hls --fps"                 => ["hls", ["--fps", "25"]],
            "hls --word-timestamps"     => ["hls", ["--word-timestamps"]],
            "hls --scc-roll-up" => ["hls", ["--scc-roll-up", "screen"]],
            "hls --track"               => ["hls", ["--track", "3"]],
        ];
    }


    /**
     * Runs the command with the option on input that needs it, and without the option on the plain input. Both runs
     * must give the same result. --from, --word-timestamps and --scc-roll-up run on the plain input.
     *
     * @param list<string> $options
     */
    #[DataProvider("inputOptions")]
    public function testInputOption(string $command, array $options): void
    {
        $this->prepareCommand($command);
        $station = file_get_contents(self::STATION);
        $edited  = str_replace("Basel", "Bern", $station);
        $late    = Subtitle::fromString($station, Format::SubRip)->shift(2)->toString(Format::SubRip);
        $input   = "station.srt";
        $plain   = fn (string $text): string => $text;
        $variant = $plain;

        switch ($options[0]) {
            case "--encoding":
                $variant = fn (string $text): string => mb_convert_encoding(ltrim($text, self::BOM), "UTF-32LE", "UTF-8");
                break;
            case "--input-fps":
            case "--fps":
                $variant = fn (string $text): string => Subtitle::fromString($text, Format::SubRip)
                                                                ->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(25)]));
                $plain   = fn (string $text): string => "{1}{1}25\n" . $variant($text);
                $input   = "station.sub";
                break;
            case "--lenient":
                $variant = fn (string $text): string => rtrim($text) . "\n\n99\n00:01:00,000 => 00:01:02,000\nBroken\n";
                break;
            case "--track":
                file_put_contents("$this->dir/station.mkv", self::mkv($station));
                break;
        }

        $files     = ["station" => $station, "edited" => $edited, "late" => $late];
        $extension = pathinfo($input, PATHINFO_EXTENSION);
        $run       = function (callable $transform, string $input, array $options) use ($command, $files, $extension): array {
            foreach ($files as $name => $text) {
                file_put_contents("$this->dir/$name.$extension", $transform($text));
            }
            $result = array_map(fn (int|string $value): int|string => is_string($value) ? str_replace($input, "INPUT", $value) : $value,
                                $this->runBinary([...self::call($command, $input, $extension), ...$options]));

            return $command === "hls" ? [...$result, $this->takeDirectory("hls")] : $result;
        };
        $variantInput = $options[0] === "--track" ? "station.mkv" : $input;

        $expected = $run($plain, $input, []);
        $actual   = $run($variant, $variantInput, $options);

        $this->assertContains($expected[0], [0, 1], $expected[2]);
        if ($options[0] === "--lenient") {
            $this->assertStringContainsString("Block #", $actual[2]);
            [$actual[2], $expected[2]] = ["", ""];
        }
        if ($options[0] === "--from") {
            $this->assertSame(3, $this->runBinary([...self::call($command, $input), "--from", "microdvd"])[0]);
        } elseif (!in_array($options[0], ["--word-timestamps", "--scc-roll-up"], true)) {
            $this->assertSame(3, $this->runBinary(self::call($command, $variantInput, $extension))[0]);
        }
        if ($options[0] === "--encoding") {
            $actual[1] = str_replace("Encoding:              UTF-32LE", "Encoding:              UTF-8", $actual[1]);
        }
        $this->takeDirectory("hls");
        $this->assertSame($expected, $actual);
    }


    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function outputOptions(): array
    {
        return [
            "retime --output-fps"      => ["retime", ["--output-fps", "25"]],
            "retime --line-ending"     => ["retime", ["--line-ending", "crlf"]],
            "retime --bom"             => ["retime", ["--bom"]],
            "retime --no-bom"          => ["retime", ["--no-bom"]],
            "retime --skip-image-cues" => ["retime", ["--skip-image-cues"]],
            "retime --scc-fit"          => ["retime", ["--scc-fit"]],
            "sync --output-fps"        => ["sync", ["--output-fps", "25"]],
            "sync --line-ending"       => ["sync", ["--line-ending", "crlf"]],
            "sync --bom"               => ["sync", ["--bom"]],
            "sync --no-bom"            => ["sync", ["--no-bom"]],
            "sync --skip-image-cues"   => ["sync", ["--skip-image-cues"]],
            "sync --scc-fit"          => ["sync", ["--scc-fit"]],
            "translate --output-fps"   => ["translate", ["--output-fps", "25"]],
            "translate --line-ending"  => ["translate", ["--line-ending", "crlf"]],
            "translate --bom"          => ["translate", ["--bom"]],
            "translate --no-bom"       => ["translate", ["--no-bom"]],
            "translate --skip-image-cues" => ["translate", ["--skip-image-cues"]],
            "translate --scc-fit"          => ["translate", ["--scc-fit"]],
            "dual --output-fps"        => ["dual", ["--output-fps", "25"]],
            "dual --line-ending"       => ["dual", ["--line-ending", "crlf"]],
            "dual --bom"               => ["dual", ["--bom"]],
            "dual --no-bom"            => ["dual", ["--no-bom"]],
            "dual --skip-image-cues"   => ["dual", ["--skip-image-cues"]],
            "dual --scc-fit"          => ["dual", ["--scc-fit"]],
        ];
    }


    /**
     * Compares the output of the command with the option to its SubRip output without the option.
     *
     * @param list<string> $options
     */
    #[DataProvider("outputOptions")]
    public function testOutputOption(string $command, array $options): void
    {
        $this->prepareCommand($command);
        $station = file_get_contents(self::STATION);
        file_put_contents("$this->dir/station.srt", $station);
        file_put_contents("$this->dir/edited.srt", str_replace("Basel", "Bern", $station));
        file_put_contents("$this->dir/late.srt", Subtitle::fromString($station, Format::SubRip)->shift(2)->toString(Format::SubRip));

        [$code, $srt, $stderr] = $this->runBinary(self::call($command, "station.srt", "srt"));
        $this->assertSame(0, $code, $stderr);

        switch ($options[0]) {
            case "--output-fps":
                $expected = Subtitle::fromString($srt, Format::SubRip)->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(25)]));
                $this->assertSame(3, $this->runBinary(self::call($command, "station.srt", to: "microdvd"))[0]);
                $this->assertSame([0, $expected], array_slice($this->runBinary([...self::call($command, "station.srt", to: "microdvd"), ...$options]), 0, 2));
                break;
            case "--line-ending":
                $this->assertSame([0, str_replace("\n", "\r\n", $srt)], array_slice($this->runBinary([...self::call($command, "station.srt", "srt"), ...$options]), 0, 2));
                break;
            case "--no-bom":
                $this->assertStringStartsWith(self::BOM, $srt);
                $this->assertSame([0, substr($srt, 3)], array_slice($this->runBinary([...self::call($command, "station.srt", "srt"), ...$options]), 0, 2));
                break;
            case "--bom":
                [, $json] = $this->runBinary(self::call($command, "station.srt", to: "json"));
                $this->assertStringStartsWith("{", $json);
                $this->assertSame([0, self::BOM . $json], array_slice($this->runBinary([...self::call($command, "station.srt", to: "json"), ...$options]), 0, 2));
                break;
            case "--scc-fit":
                file_put_contents("$this->dir/fit.srt", str_replace("Basel", "\u{160}ibenik", $station));
                [, $fitSrt] = $this->runBinary(self::call($command, "fit.srt", "srt"));
                $report     = (new SccFormatter())->formatWithReport(Subtitle::fromString($fitSrt, Format::SubRip),
                                                                     new WriteOptions(formats: [new SccWriteOptions(fit: true)]));
                $changes    = implode("", array_map(fn (SccFitChange $change): string => "fit.srt: $change->message ({$change->action->value})\n", $report->changes));
                $this->assertSame($command === "translate" ? 0 : 3, $this->runBinary(self::call($command, "fit.srt", to: "scc"))[0]);
                [$code, $scc, $stderr] = $this->runBinary([...self::call($command, "fit.srt", to: "scc"), ...$options]);
                $this->assertSame([0, $report->content], [$code, $scc]);
                $this->assertTrue(str_ends_with($stderr, $changes), $stderr);
                if ($command !== "translate") {
                    $this->assertStringContainsString("replaced \"\u{160}\" with \"S\". (transliterated)\n", $changes);
                }
                $this->assertSame(0, $this->runBinary([...self::call($command, "station.srt", "srt"), ...$options])[0]);
                break;
            case "--skip-image-cues":
                $data  = json_decode(Subtitle::fromString($station, Format::SubRip)->toString(Format::Json), true);
                $image = json_decode(file_get_contents(self::FILES . "json/real/own_image_cues.json"), true);
                $data["cues"] = [...$data["cues"], ...array_values(array_filter($image["cues"], fn (array $cue): bool => $cue["lines"] === []))];
                file_put_contents("$this->dir/images.json", json_encode($data));
                $this->assertSame(3, $this->runBinary(self::call($command, "images.json", "srt"))[0]);
                $this->assertSame([0, $srt], array_slice($this->runBinary([...self::call($command, "images.json", "srt"), ...$options]), 0, 2));
                break;
        }
    }


    /**
     * Returns the arguments of a call of $command on $input. $second is the extension of the second file of diff and
     * dual and of the reference of sync. $to is the output format of a command that writes a subtitle.
     *
     * @return list<string>
     */
    private static function call(string $command, string $input, string $second = "srt", string $to = "srt"): array
    {
        return match ($command) {
            "retime"    => ["retime", $input, "--shift", "1", "--to", $to],
            "info"      => ["info", $input],
            "validate"  => ["validate", $input, "--preset", "bbc"],
            "sync"      => ["sync", $input, "--reference", "late.$second", "--to", $to],
            "diff"      => ["diff", $input, "edited.$second"],
            "translate" => ["translate", $input, "--engine", "deepl", "--api-key", "key", "--target-language", "de", "--to", $to],
            "dual"      => ["dual", "--primary", $input, "--secondary", "edited.$second", "--to", $to],
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
