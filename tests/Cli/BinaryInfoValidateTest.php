<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\Validation\ValidationRules;

/**
 * Checks the info and validate commands.
 */
class BinaryInfoValidateTest extends CliTestCase
{
    public function testInfoAsText(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["info", "trip.srt"]);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("trip.srt\n  Format:                srt\n  Encoding:              UTF-8\n  Cues:                  3\n", $stdout);
        $this->assertStringContainsString("  Gaps:                  min -0.5, average 0.25, max 1 s\n", $stdout);
        $this->assertSame("", $stderr);
    }


    public function testInfoAsJsonGivesNullForStatisticsWithoutData(): void
    {
        [$code, $stdout] = $this->runCli(["info", "-", "--json"], "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n");
        $statistics = json_decode($stdout, true)[0]["statistics"];
        $this->assertSame([0, null, 1], [$code, $statistics["gaps"], $statistics["span"]]);

        [$code, $stdout] = $this->runCli(["info", "-", "--json"], "WEBVTT\n");
        $statistics = json_decode($stdout, true)[0]["statistics"];
        $this->assertSame([0, null, null, null, null, null], [$code, $statistics["span"], $statistics["charactersPerSecond"],
                          $statistics["wordsPerMinute"], $statistics["charactersPerLine"], $statistics["gaps"]]);
        $this->assertStringContainsString("  Gaps:                  -\n", $this->runCli(["info", "-"], "WEBVTT\n")[1]);
    }


    public function testTextWithoutCuesFailsTheFileAndWarnsInLenientMode(): void
    {
        $lyrics = "[ti:Harbour walk]\nThe ferry leaves at nine.\n";

        [$code, , $stderr] = $this->runCli(["info", "-", "--from", "lrc"], $lyrics);
        $this->assertSame(3, $code);
        $this->assertStringContainsString("The file has text but no cues. (line 2)", $stderr);

        [$code, , $stderr] = $this->runCli(["info", "-", "--from", "lrc", "--lenient"], $lyrics);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("line 2: The file has text but no cues. (skipped)", $stderr);
    }


    public function testInfoAsJson(): void
    {
        [$code, $stdout] = $this->runCli(["info", "trip.srt", "--json"]);
        $list = json_decode($stdout, true);
        $info = $list[0];

        $this->assertSame([0, 1], [$code, count($list)]);
        $this->assertSame("trip.srt", $info["file"]);
        $this->assertSame("srt", $info["format"]);
        $this->assertSame(3, $info["statistics"]["cueCount"]);
        $this->assertSame(["word" => "the", "count" => 3], $info["statistics"]["mostUsedWords"][0]);

        [$code, $stdout, $stderr] = $this->runCli(["info", "trip.srt", "shop.vtt", "broken.srt", "--json", "--keep-going"]);
        $list = json_decode($stdout, true);

        $this->assertSame(3, $code);
        $this->assertSame(["trip.srt", "shop.vtt"], array_column($list, "file"));
        $this->assertSame("vtt", $list[1]["format"]);
        $this->assertStringStartsWith("broken.srt: ", $stderr);
        $this->assertStringEndsWith("3 files: 2 succeeded, 1 failed.\n", $stderr);

        [$code, $stdout] = $this->runCli(["info", "broken.srt", "--json"]);
        $this->assertSame([3, "[]\n"], [$code, $stdout]);
    }


    public function testInfoReportsTheSourceEncoding(): void
    {
        $this->assertSame("UTF-8", json_decode($this->runCli(["info", "trip.srt", "--json"])[1], true)[0]["encoding"]);
        $this->assertSame("Windows-1252", json_decode($this->runCli(["info", "latin1.srt", "--json"])[1], true)[0]["encoding"]);
        $this->assertSame("Windows-1250", json_decode($this->runCli(["info", "latin1.srt", "--json", "--encoding", "Windows-1250"])[1], true)[0]["encoding"]);
        $this->assertMatchesRegularExpression('/^  Encoding: +Windows-1252$/m', $this->runCli(["info", "latin1.srt"])[1]);

        [$code, , $stderr] = $this->runCli(["info", "latin1.srt", "--lenient"]);
        $this->assertSame([0, "latin1.srt: The content is not UTF-8. Detection picked Windows-1252. Pass --encoding if that is wrong. (repaired)\n"],
                          [$code, $stderr]);
    }


    public function testInfoListsTheParseWarnings(): void
    {
        $this->assertSame([], json_decode($this->runCli(["info", "trip.srt", "--json"])[1], true)[0]["warnings"]);

        [$code, $stdout, $stderr] = $this->runCli(["info", "broken.srt", "--json", "--lenient"]);
        $this->assertSame(0, $code);
        $this->assertSame([[
            "lineNumber" => 6,
            "blockIndex" => 1,
            "message"    => "Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\".",
            "action"     => "skipped",
        ]], json_decode($stdout, true)[0]["warnings"]);
        $this->assertSame("broken.srt: line 6: Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\". (skipped)\n", $stderr);

        $this->assertMatchesRegularExpression('/^  Warnings: +1$/m', $this->runCli(["info", "broken.srt", "--lenient"])[1]);
        $this->assertDoesNotMatchRegularExpression('/Warnings/', $this->runCli(["info", "trip.srt"])[1]);
    }


    public function testValidateCheckOverlapReportsTheOverlaps(): void
    {
        $path     = self::FILES . "validation/own_netflix_checks.srt";
        $expected = Subtitle::load($path, Format::SubRip)->validate(new ValidationRules(noOverlap: true));
        $this->assertNotSame([], $expected);

        [$code, $stdout, $stderr] = $this->runCli(["validate", $path, "--check-overlaps", "--json"]);

        $this->assertSame([1, ""], [$code, $stderr]);
        $violations = json_decode($stdout, true)[0]["violations"];
        $this->assertSame(array_fill(0, count($expected), "noOverlap"), array_column($violations, "rule"));
        $this->assertSame(array_map(fn (ValidationViolation $violation): int => $violation->cueIndex, $expected), array_column($violations, "cueIndex"));
        $this->assertSame(2, $this->runCli(["validate", $path, "--no-overlap"])[0]);
    }


    public function testValidateWithThePreset(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["validate", "trip.srt", "--preset", "netflix-en"]);

        $this->assertSame(1, $code);
        $this->assertSame(
            "trip.srt: cue 2: maxCharactersPerLine 57, limit 42\n" .
            "trip.srt: cue 2: maxCharactersPerSecond 27.6, limit 20\n" .
            "trip.srt: cue 2: noOverlap 0.5\n" .
            "trip.srt: cue 3: maxCharactersPerSecond 42.5, limit 20\n" .
            "trip.srt: cue 3: minDuration 0.4, limit 0.833\n",
            $stdout
        );
        $this->assertSame("", $stderr);

        $this->assertSame(
            [1, "trip.srt: cue 2: noOverlap 0.5\nshop.vtt: no problems\n2 files: 1 valid, 1 with problems, 0 failed.\n", ""],
            $this->runCli(["validate", "trip.srt", "shop.vtt", "--check-overlaps"])
        );
        $this->assertSame([0, "shop.vtt: no problems\n", ""], $this->runCli(["validate", "shop.vtt", "--preset", "netflix-en", "--max-cps", "30"]));
        $this->assertSame(2, $this->runCli(["validate", "shop.vtt"])[0]);
        $this->assertSame(2, $this->runCli(["validate", "shop.vtt", "--preset", "nope"])[0]);
    }


    public function testValidateTakesTheVideoFrameRateForTheGap(): void
    {
        copy(self::FILES . "validation/own_netflix_checks.srt", "$this->dir/checks.srt");

        $this->assertStringContainsString("checks.srt: cue 2: minGap 0.04, limit 0.083\n",
                                          $this->runCli(["validate", "checks.srt", "--preset", "netflix-en"])[1]);
        $this->assertStringContainsString("checks.srt: cue 2: minGap 0.04, limit 0.08\n",
                                          $this->runCli(["validate", "checks.srt", "--preset", "netflix-en", "--video-fps", "25"])[1]);
        $this->assertStringContainsString("checks.srt: cue 2: minGap 0.04, limit 0.08\n",
                                          $this->runCli(["validate", "checks.srt", "--preset", "netflix-en", "--fps", "25"])[1]);
        $this->assertStringNotContainsString("minGap",
                                             $this->runCli(["validate", "checks.srt", "--preset", "netflix-en", "--video-fps", "50"])[1]);
        $this->assertSame(2, $this->runCli(["validate", "checks.srt", "--preset", "netflix-en", "--from", "25"])[0]);
    }


    public function testValidateWithTheBbcPreset(): void
    {
        $this->assertSame([
            1,
            "trip.srt: cue 2: maxCharactersPerLine 57, limit 37\n" .
            "trip.srt: cue 2: maxWordsPerMinute 336, limit 180\n" .
            "trip.srt: cue 2: minSecondsPerWord 0.179, limit 0.3\n" .
            "trip.srt: cue 3: maxWordsPerMinute 450, limit 180\n" .
            "trip.srt: cue 3: minSecondsPerWord 0.133, limit 0.3\n",
            "",
        ], $this->runCli(["validate", "trip.srt", "--preset", "bbc"]));
        $this->assertSame(
            [1, "trip.srt: cue 2: maxCharactersPerLine 57, limit 37\n", ""],
            $this->runCli(["validate", "trip.srt", "--preset", "bbc", "--max-wpm", "500", "--min-seconds-per-word", "00:00.1"])
        );
    }


    public function testValidateTextRules(): void
    {
        $vtt = "WEBVTT\n\n" .
               "00:00:01.000 --> 00:00:04.000\n-Where is the bus?\n- At the <i>corner.\n\n" .
               "00:00:05.000 --> 00:00:08.000\nTHE BUS IS LATE\nWait <i> here</i>\n\n" .
               "00:00:09.000 --> 00:00:12.000\n<v Anna>Rain today.\n<v Ben>Sun tomorrow.\n<v Cleo>Snow later.\n\n" .
               "00:00:13.000 --> 00:00:15.000\n&nbsp;Café open.\n";

        $this->assertSame([
            1,
            "stdin: cue 1: noUnbalancedTags 1\n" .
            "stdin: cue 1: dialogueDashStyle 1\n" .
            "stdin: cue 2: noDoubleSpaces 1\n" .
            "stdin: cue 2: noAllCapsLines 1\n" .
            "stdin: cue 3: maxSpeakersPerCue 3, limit 2\n" .
            "stdin: cue 4: noLeadingOrTrailingSpaces 1\n" .
            "stdin: cue 4: allowedCharacters 2\n",
            "",
        ], $this->runCli([
            "validate", "-", "--dialogue-dash", "- ", "--check-unbalanced-tags", "--check-all-caps-lines", "--check-double-spaces",
            "--check-leading-or-trailing-spaces", "--max-speakers", "2", "--allowed-characters", "[A-Za-z0-9 .,!?<>/\\-]",
        ], $vtt));
        $this->assertSame(
            [2, "", "Error: The option --dialogue-dash must be a hyphen, an en dash or an em dash, with or without one space after it, got \"x\".\n" .
                    "Run \"subtitle-toolbox help validate\" for the usage.\n"],
            $this->runCli(["validate", "-", "--dialogue-dash", "x"], $vtt)
        );
        $this->assertSame(2, $this->runCli(["validate", "-", "--allowed-characters", "[z-a]"], $vtt)[0]);
    }


    public function testValidateAsJson(): void
    {
        [$code, $stdout] = $this->runCli(["validate", "trip.srt", "--max-cpl", "42", "--json"]);

        $this->assertSame(1, $code);
        $this->assertSame([[
            "file"       => "trip.srt",
            "format"     => "srt",
            "valid"      => false,
            "violations" => [["cueIndex" => 1, "rule" => "maxCharactersPerLine", "value" => 57, "infinite" => false, "limit" => 42]],
            "warnings"   => [],
        ]], json_decode($stdout, true));

        [$code, $stdout] = $this->runCli(["validate", "-", "--max-cps", "20", "--check-overlaps", "--json"],
                                            "1\n00:00:01,000 --> 00:00:01,000\nHello\n\n2\n00:00:01,000 --> 00:00:03,000\nHi\n\n" .
                                            "3\n00:00:02,500 --> 00:00:04,000\nYo\n");
        $this->assertSame(1, $code);
        $this->assertSame([[
            "file"       => "-",
            "format"     => "srt",
            "valid"      => false,
            "violations" => [
                ["cueIndex" => 0, "rule" => "maxCharactersPerSecond", "value" => null, "infinite" => true, "limit" => 20],
                ["cueIndex" => 2, "rule" => "noOverlap", "value" => 0.5, "infinite" => false, "limit" => null],
            ],
            "warnings"   => [],
        ]], json_decode($stdout, true));
    }


    public function testValidateAndDiffListTheParseWarningsOfEachFile(): void
    {
        $warning = [
            "lineNumber" => 6,
            "blockIndex" => 1,
            "message"    => "Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\".",
            "action"     => "skipped",
        ];
        $line    = "line 6: Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\". (skipped)\n";

        [$code, $stdout, $stderr] = $this->runCli(["validate", "broken.srt", "--max-cpl", "42", "--json", "--lenient"]);
        $this->assertSame([0, [$warning], "broken.srt: $line"], [$code, json_decode($stdout, true)[0]["warnings"], $stderr]);

        [$code, $stdout, $stderr] = $this->runCli(["diff", "trip.srt", "broken.srt", "--json", "--lenient"]);
        $json = json_decode($stdout, true)[0];
        $this->assertSame([1, [], [$warning], "broken.srt: $line"], [$code, $json["oldWarnings"], $json["newWarnings"], $stderr]);

        [$code, $stdout] = $this->runCli(["diff", "broken.srt", "trip.srt", "--json", "--lenient"]);
        $json = json_decode($stdout, true)[0];
        $this->assertSame([1, [$warning], []], [$code, $json["oldWarnings"], $json["newWarnings"]]);
    }
}
