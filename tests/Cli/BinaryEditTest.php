<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Format;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityMask;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\ReplaceTextOptions;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\BinaryTestCase;
use SubtitleToolbox\WriteOptions;

/**
 * Checks the options that change the text of the cues: tags, speakers, case, masks, mutes, karaoke and fixes.
 */
class BinaryEditTest extends BinaryTestCase
{
    public function testStripTags(): void
    {
        [$code, $stdout] = $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--strip-tags"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nThe train leaves at noon.\n", $stdout);
    }


    public function testRtl(): void
    {
        copy(self::FILES . "transforms/own_rtl.srt", "$this->dir/rtl.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_rtl_fixed.srt"), ""],
                          $this->runBinary(["convert", "rtl.srt", "--to", "srt", "-o", "-", "--rtl", "fix"]));
        copy(self::FILES . "transforms/own_rtl_fixed.srt", "$this->dir/fixed.srt");
        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_rtl.srt"), ""],
                          $this->runBinary(["convert", "fixed.srt", "--to", "srt", "-o", "-", "--no-bom", "--rtl", "clean"]));
        $this->assertSame([2, "", "Error: The option --rtl must be fix or clean, got \"left\".\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "rtl.srt", "--to", "srt", "-o", "-", "--rtl", "left"]));
    }


    public function testForcedOnly(): void
    {
        copy(__DIR__ . "/../files/forced/forced_signs_2398.itt", "$this->dir/signs.itt");
        $expected = Subtitle::fromStringAutoDetectFormat($this->file("signs.itt"))->withForcedCuesOnly()->toString(Format::SubRip);

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "signs.itt", "--to", "srt", "-o", "signs.srt", "--forced-only"]);

        $this->assertSame([0, "signs.itt -> signs.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($expected, $this->file("signs.srt"));
        $this->assertSame(3, substr_count($expected, " --> "));
        $this->assertSame(6, substr_count($this->runBinary(["convert", "signs.itt", "--to", "srt", "-o", "-"])[1], " --> "));
    }


    public function testSpeakers(): void
    {
        $files = __DIR__ . "/../files/speakers/";
        copy($files . "voices.vtt", "$this->dir/voices.vtt");
        copy($files . "sdh_labels.srt", "$this->dir/labels.srt");

        foreach (["prefix", "dashes", "colors"] as $mode) {
            $this->assertSame(
                [0, file_get_contents($files . "voices_$mode.srt"), ""],
                $this->runBinary(["convert", "voices.vtt", "--to", "srt", "-o", "-", "--no-bom", "--speakers", $mode])
            );
        }
        $this->assertSame(
            [0, file_get_contents($files . "sdh_labels_voices.vtt"), ""],
            $this->runBinary(["convert", "labels.srt", "--to", "vtt", "-o", "-", "--no-bom", "--speakers", "from-prefix"])
        );
        $this->assertSame(
            [2, "", "Error: The option --speakers must be prefix, dashes, colors or from-prefix, got \"names\".\n" .
                    "Run \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "voices.vtt", "--to", "srt", "--speakers", "names"])
        );
    }


    public function testSpeakersRunBeforeCaseAndStripTags(): void
    {
        $files = __DIR__ . "/../files/speakers/";
        copy($files . "voices.vtt", "$this->dir/voices.vtt");
        copy($files . "sdh_labels.srt", "$this->dir/labels.srt");
        $prefix = Subtitle::fromString(file_get_contents($files . "voices_prefix.srt"), Format::SubRip)->stripFormatting();
        $voices = Subtitle::fromString(file_get_contents($files . "sdh_labels_voices.vtt"), Format::WebVtt)->changeCase(CaseMode::Lower);

        $this->assertSame(
            [0, $prefix->toString(Format::SubRip, new WriteOptions(bom: false)), ""],
            $this->runBinary(["convert", "voices.vtt", "--to", "srt", "-o", "-", "--no-bom", "--strip-tags", "--speakers", "prefix"])
        );
        $this->assertSame(
            [0, $voices->toString(Format::WebVtt, new WriteOptions(bom: false)), ""],
            $this->runBinary(["convert", "labels.srt", "--to", "vtt", "-o", "-", "--no-bom", "--case", "lower", "--speakers", "from-prefix"])
        );
    }


    public function testReplaceAndCase(): void
    {
        copy(self::FILES . "transforms/own_cea608_caps.vtt", "$this->dir/caps.vtt");
        copy(self::FILES . "transforms/own_multilingual_caps.srt", "$this->dir/multi.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_cea608_caps_sentence.vtt"), ""],
                          $this->runBinary(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--case", "sentence"]));
        $this->assertSame(
            [0, file_get_contents(self::FILES . "transforms/own_cea608_caps_cleaned.vtt"), ""],
            $this->runBinary(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--replace-regex", "--replace", '/\[[^\]]*\]/=',
                              "--replace", '/\.{4,}/=...', "--strip-tags"])
        );

        $expected = Subtitle::fromStringAutoDetectFormat($this->file("multi.srt"))->replaceText("uhr", "Uhr", new ReplaceTextOptions(caseSensitive: false))->changeCase(CaseMode::Lower, "tr");
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary([
            "convert", "multi.srt", "--to", "srt", "-o", "-", "--replace", "uhr=Uhr", "--replace-ignore-case", "--case", "lower", "--language", "tr",
        ]));
        $this->assertStringContainsString("<font color=\"#ffff00\">istasyon kap\u{131}s\u{131} \u{131}\u{15f}\u{131}kl\u{131}.</font>",
                                          $this->runBinary(["convert", "multi.srt", "--to", "srt", "-o", "-", "--case", "lower", "--language", "tr"])[1]);

        foreach ([["--replace", "colour"], ["--replace", "=x"], ["--replace-regex", "--replace", "/(/=x"], ["--replace-regex"],
                  ["--replace-ignore-case"], ["--case", "title"], ["--language", "tr"], ["--regex", "--replace", "a=b"], ["--case-language", "tr"]] as $options) {
            $this->assertSame(2, $this->runBinary(["convert", "caps.vtt", "--to", "vtt", "-o", "-", ...$options])[0], implode(" ", $options));
        }
    }


    public function testMaskWords(): void
    {
        $files = __DIR__ . "/../files/profanity/";
        copy($files . "keys.srt", "$this->dir/keys.srt");
        copy($files . "words.txt", "$this->dir/words.txt");
        $masked = function (ProfanityMask $mask): string {
            $subtitle = Subtitle::fromStringAutoDetectFormat($this->file("keys.srt"));
            ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"], $mask));

            return $subtitle->toString(Format::SubRip);
        };

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt"]);
        $this->assertSame([0, $masked(ProfanityMask::Stars), ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("- Go to ****.\n", $stdout);

        $this->assertSame(
            [0, $masked(ProfanityMask::FirstLetter), ""],
            $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mask", "first-letter"])
        );
        $this->assertSame(
            [0, $masked(ProfanityMask::Remove), ""],
            $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mask", "remove"])
        );
        $this->assertSame(2, $this->runBinary(["convert", "keys.srt", "--to", "srt", "--mask-words", "words.txt", "--mask", "beep"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "keys.srt", "--to", "srt", "--mask", "stars"])[0]);
        $this->assertSame(
            [3, "", "Error: missing.txt: The file does not exist.\n"],
            $this->runBinary(["convert", "keys.srt", "--to", "srt", "--mask-words", "missing.txt"])
        );
    }


    public function testMuteRanges(): void
    {
        copy(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
        $subtitle = Subtitle::fromStringAutoDetectFormat($this->file("radio.vtt"));
        $ranges   = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"], ProfanityMask::None, 0.1))->muteRanges;

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "radio.vtt", "--to", "vtt", "-o", "out.vtt", "--mask-words", "words.txt", "--mask", "none",
                                                      "--mute-edl", "radio.edl", "--mute-filter", "radio.af", "--mute-padding", "00:00:00,1"]);
        $this->assertSame([0, "radio.vtt -> out.vtt\nradio.vtt -> radio.edl\nradio.vtt -> radio.af\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($subtitle->toString(Format::WebVtt), $this->file("out.vtt"));
        $this->assertSame(MuteRange::toEdl($ranges), $this->file("radio.edl"));
        $this->assertSame("1.500 2.100 1\n6.200 6.800 1\n7.900 9.100 1\n", $this->file("radio.edl"));
        $this->assertSame(MuteRange::toFfmpegVolumeFilter($ranges) . "\n", $this->file("radio.af"));

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "radio.vtt", "--to", "srt", "-o", "-", "--mask-words", "words.txt",
                                                      "--mute-edl", "-"]);
        $this->assertSame([2, ""], [$code, $stdout]);
        $this->assertSame([2, "", "Error: The --mute-edl file radio.edl exists. The tool never overwrites a file. Remove it, or pass another output " .
                                  "file or directory.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "radio.vtt", "--to", "srt", "-o", "new.srt", "--mask-words", "words.txt", "--mute-edl", "radio.edl"]));
        $this->assertFileDoesNotExist("$this->dir/new.srt");
        $this->assertSame(2, $this->runBinary(["convert", "radio.vtt", "--to", "srt", "--mute-edl", "new.edl"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "radio.vtt", "trip.srt", "--to", "vtt", "--output-dir", "out",
                                               "--mask-words", "words.txt", "--mute-edl", "new.edl"])[0]);
        $this->assertFileDoesNotExist("$this->dir/new.edl");
    }


    public function testMuteFilesNeverOverwriteAnotherFileOfTheRun(): void
    {
        copy(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
        $usage = "\nRun \"subtitle-toolbox help convert\" for the usage.\n";
        $run   = fn (string ...$options): array => $this->runBinary(["convert", "radio.vtt", "--to", "srt", "--mask-words", "words.txt", ...$options]);

        $this->assertSame([2, "", "Error: The --mute-filter file radio.mute is also the output of --mute-edl.$usage"],
                          $run("-o", "out.srt", "--mute-edl", "radio.mute", "--mute-filter", "radio.mute"));
        $this->assertSame([2, "", "Error: The --mute-edl file out.srt is also the output of radio.vtt.$usage"],
                          $run("-o", "out.srt", "--mute-edl", "out.srt"));
        $this->assertSame([2, "", "Error: The --mute-edl file radio.vtt is a file that the command reads. Pass another output file or directory.$usage"],
                          $run("-o", "out.srt", "--mute-edl", "radio.vtt"));
        $this->assertSame([2, "", "Error: The --mute-filter file ./words.txt is a file that the command reads. Pass another output file or directory.$usage"],
                          $run("-o", "out.srt", "--mute-filter", "./words.txt"));

        $this->assertFileDoesNotExist("$this->dir/radio.mute");
        $this->assertFileDoesNotExist("$this->dir/out.srt");
        $this->assertFileEquals(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        $this->assertFileEquals(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
    }


    public function testMuteRangesHoldTheTimesAfterTheTimingEdits(): void
    {
        copy(self::FILES . "profanity/keys.srt", "$this->dir/keys.srt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
        $subtitle = Subtitle::load("$this->dir/keys.srt", Format::SubRip)->shift(100);
        $subtitle->extendShortCues(3);
        $ranges   = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"]))->muteRanges;

        [$code, , $stderr] = $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "out.srt", "--shift", "100", "--timing-min-duration", "3",
                                               "--mask-words", "words.txt", "--mute-edl", "keys.edl", "--mute-filter", "keys.af"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertSame("103.400 105.500 1\n108.000 109.100 1\n111.500 116.200 1\n", $this->file("keys.edl"));
        $this->assertSame(MuteRange::toEdl($ranges), $this->file("keys.edl"));
        $this->assertSame(MuteRange::toFfmpegVolumeFilter($ranges) . "\n", $this->file("keys.af"));
        $this->assertSame($subtitle->toString(Format::SubRip), $this->file("out.srt"));
    }


    public function testKaraoke(): void
    {
        copy(self::FILES . "whisper/real/openai_whisper_word_timestamps.json", "$this->dir/song.json");
        copy(self::FILES . "lrc/real/handwritten-enhanced.lrc", "$this->dir/song.lrc");

        $this->assertSame([0, "song.json -> word.srt\n", ""], $this->runBinary(["convert", "song.json", "--to", "srt", "-o", "word.srt", "--karaoke"]));
        $this->assertFileEquals(self::FILES . "karaoke/whisper_word.srt", "$this->dir/word.srt");
        $expected = Subtitle::loadAutoDetectFormat("$this->dir/song.lrc");
        WordHighlight::apply($expected, new WordHighlightOptions(style: 'font color="#ffff00"'));
        $this->assertSame(
            [0, $expected->toString(Format::SubRip), ""],
            $this->runBinary(["convert", "song.lrc", "--to", "srt", "-o", "-", "--karaoke", "--karaoke-style", 'font color="#ffff00"'])
        );
        $this->assertSame([0, file_get_contents(self::FILES . "karaoke/whisper_kf.ass"), ""],
                          $this->runBinary(["convert", "song.json", "--to", "ass", "-o", "-", "--ass-karaoke-tag", "kf"]));

        $this->assertSame([2, "", "Error: Pass --to ass with --ass-karaoke-tag.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "song.json", "--to", "srt", "--ass-karaoke-tag", "kf", "--output-dir", "out"]));
        $this->assertSame(2, $this->runBinary(["convert", "song.json", "-o", "song.srt", "--ass-karaoke-tag", "kf"])[0]);
        $this->assertStringContainsString("\nStyle: Default,Roboto,48,",
                                          $this->runBinary(["convert", "song.json", "--to", "ass", "-o", "-", "--ass-style", "Fontname=Roboto,Fontsize=48"])[1]);
        $this->assertSame([2, "", "Error: Pass --to ass with --ass-karaoke-tag and --ass-style.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "song.json", "--to", "srt", "-o", "-", "--ass-karaoke-tag", "kf", "--ass-style", "Fontsize=48"]));
        $this->assertDirectoryDoesNotExist("$this->dir/out");
        $this->assertFileDoesNotExist("$this->dir/song.srt");
        foreach ([["--ass-karaoke-tag", "x"], ["--karaoke", "--ass-karaoke-tag", "k"], ["--karaoke-style", "b"], ["--karaoke", "--karaoke-style", "em"],
                  ["--karaoke", "--karaoke-mode", "cumulative"], ["--karaoke", "--karaoke-words", "2"], ["--karaoke-tag", "k"]] as $options) {
            $this->assertSame(2, $this->runBinary(["convert", "song.json", "--to", "srt", "-o", "-", ...$options])[0], implode(" ", $options));
        }
    }


    public function testFix(): void
    {
        [$code, $stdout] = $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--timing-fix-overlaps", "--timing-min-gap", "0.1", "--timing-min-duration", "1", "--structure-wrap", "--structure-max-cpl", "30"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:01,000 --> 00:00:02,400\n", $stdout);
        $this->assertStringContainsString("[BELL RINGS] ANNA: We need two tickets\nfor the long ride to the coast.\n", $stdout);
        $this->assertStringContainsString("00:00:06,000 --> 00:00:07,000\n", $stdout);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--structure-wrap", "--structure-max-cpl", "0"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--timing-min-gap", "-1"])[0]);
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function fixLimitsWithoutTheirFix(): array
    {
        return [
            "max cpl"             => [["--structure-max-cpl", "30"],
                                      "Pass --structure-wrap, --structure-resegment, --structure-merge-short or --structure-split-long with --structure-max-cpl."],
            "max cpl with unwrap" => [["--structure-max-cpl", "30", "--structure-unwrap"],
                                      "Pass --structure-wrap, --structure-resegment, --structure-merge-short or --structure-split-long with --structure-max-cpl."],
            "max lines"           => [["--structure-max-lines", "1", "--timing-fix-overlaps"],
                                      "Pass --structure-wrap, --structure-resegment, --structure-merge-short or --structure-split-long with --structure-max-lines."],
            "min gap"             => [["--timing-min-gap", "0.1", "--structure-wrap"], "Pass --timing-fix-overlaps, --timing-min-duration, --timing-lead-in or --timing-lead-out with --timing-min-gap."],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("fixLimitsWithoutTheirFix")]
    public function testFixLimitWithoutItsFixIsAUsageError(array $options, string $message): void
    {
        $this->assertSame(
            [2, "", "Error: $message\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", ...$options])
        );
    }


    public function testFixMergeShort(): void
    {
        copy(__DIR__ . "/../files/short-cues/own_speech_to_text.srt", "$this->dir/speech.srt");
        $narrow = Subtitle::fromStringAutoDetectFormat($this->file("speech.srt"))
            ->mergeShortCues(new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 20, maxLinesPerCue: 3)))
            ->toString(Format::SubRip);

        $this->assertSame(
            [0, file_get_contents(__DIR__ . "/../files/short-cues/own_speech_to_text_merged.srt"), ""],
            $this->runBinary(["convert", "speech.srt", "--to", "srt", "-o", "-", "--structure-merge-short"])
        );
        $this->assertSame([0, $narrow, ""], $this->runBinary(["convert", "speech.srt", "--to", "srt", "-o", "-", "--structure-merge-short", "--structure-max-cpl", "20", "--structure-max-lines", "3"]));
        $this->assertSame(2, $this->runBinary(["convert", "speech.srt", "--to", "srt", "-o", "-", "--structure-merge-short", "--structure-max-cpl", "0"])[0]);
    }


    public function testFixSplitLong(): void
    {
        copy(__DIR__ . "/../files/resegmenting/own_whisper_long_segments.json", "$this->dir/whisper.json");
        $split = function (ResegmentOptions $options): string {
            $subtitle = Subtitle::fromStringAutoDetectFormat($this->file("whisper.json"));
            Resegmenter::apply($subtitle, $options);

            return $subtitle->toString(Format::WebVtt);
        };

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "whisper.json", "--structure-split-long", "--to", "vtt", "-o", "-"]);

        $this->assertSame([0, $split(new ResegmentOptions(ResegmentMode::SplitLong)), ""], [$code, $stdout, $stderr]);
        $this->assertGreaterThan(count(Subtitle::fromStringAutoDetectFormat($this->file("whisper.json"))->getCues()), substr_count($stdout, " --> "));
        $this->assertSame(
            [0, $split(new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1))), ""],
            $this->runBinary(["convert", "whisper.json", "--structure-split-long", "--structure-max-cpl", "30", "--structure-max-lines", "1", "--to", "vtt", "-o", "-"])
        );
    }


    public function testFixCommonErrors(): void
    {
        copy(self::FILES . "fixing/web-errors.srt", "$this->dir/web.srt");
        copy(self::FILES . "fixing/text-pal.ocr.srt", "$this->dir/pal.srt");
        copy(self::FILES . "fixing/user_OCRFixReplaceList.xml", "$this->dir/list.xml");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "web.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--line-ending", "crlf"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringEqualsFile(self::FILES . "fixing/web-errors.fixed.srt", $stdout);

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "pal.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--errors-replace-list", "list.xml",
                                                      "--errors-list-fixes"]);
        $this->assertSame(0, $code);
        $this->assertStringEqualsFile(self::FILES . "fixing/text-pal.fixed.srt", $stdout);
        $this->assertStringStartsWith("pal.srt: cue 1: ", $stderr);
        $this->assertStringContainsString(": replaceList: ", $stderr);

        $this->assertSame([2, "", "Error: Pass --case or --errors-fix with --language.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "web.srt", "--to", "srt", "-o", "-", "--timing-fix-overlaps", "--language", "en"]));
        $this->assertSame([2, "", "Error: Pass --errors-fix with --errors-list-fixes.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "web.srt", "--to", "srt", "-o", "-", "--errors-list-fixes"]));
        $this->assertSame([3, "", "Error: missing.xml: The file does not exist.\n"],
                          $this->runBinary(["convert", "web.srt", "--to", "srt", "-o", "-", "--errors-fix", "--errors-replace-list", "missing.xml"]));
    }


    public function testErrorsEnableTurnsOnRulesThatAreOffByDefault(): void
    {
        copy(self::FILES . "fixing/optional-rules.srt", "$this->dir/optional.srt");
        $names  = ["doubleApostrophes", "loneLowercaseI", "unneededPeriods", "dialogueOnOneLine", "musicNotes", "sentenceStartCase"];
        $enable = implode(",", $names);

        $this->assertSame([0, file_get_contents(self::FILES . "fixing/optional-rules.fixed.srt"), ""],
                          $this->runBinary(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--errors-enable", $enable]));
        $this->assertSame([0, file_get_contents(self::FILES . "fixing/optional-rules.srt"), ""],
                          $this->runBinary(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--no-bom"]));
        $this->assertSame([2, "", "Error: Unknown rule \"dialogOnOneLine\" in --errors-enable. The valid names are " . implode(", ", $names) . ".\n" .
                                  "Run \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--errors-enable", "$enable,dialogOnOneLine"]));
        $this->assertSame([2, "", "Error: Pass --errors-fix with --errors-enable.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-enable", $enable]));
    }


    public function testWordTimestampsAndResegment(): void
    {
        copy(self::FILES . "resegmenting/own_whisper_long_segments.json", "$this->dir/lecture.json");
        $withWords = fn (): Subtitle => (new WhisperJsonParser())->parse($this->file("lecture.json"), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "lecture.json", "--structure-resegment", "--to", "srt", "-o", "lecture.srt"]);
        $this->assertSame([0, "lecture.json -> lecture.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertFileEquals(self::FILES . "resegmenting/own_whisper_long_segments_resegmented.srt", "$this->dir/lecture.srt");

        $resegmented = $withWords();
        Resegmenter::apply($resegmented, new ResegmentOptions(ResegmentMode::ByWords, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1), maxWordGap: 0.3));
        $this->assertSame(
            [0, $resegmented->toString(Format::SubRip), ""],
            $this->runBinary(["convert", "lecture.json", "--structure-resegment", "--structure-max-cpl", "30", "--structure-max-lines", "1", "--structure-max-word-gap", "00:00:00.3",
                                   "--to", "srt", "-o", "-"])
        );

        $this->assertSame([0, $withWords()->toString(Format::WebVtt), ""],
                          $this->runBinary(["convert", "lecture.json", "--to", "vtt", "-o", "-", "--word-timestamps"]));
        $this->assertSame([0, $withWords()->toString(Format::WebVtt), ""],
                          $this->runBinary(["convert", "lecture.json", "--from", "whisper", "--to", "vtt", "-o", "-", "--word-timestamps"]));
        $this->assertSame([0, $withWords()->toString(Format::WebVtt), ""],
                          $this->runBinary(["convert", "-", "--to", "vtt", "-o", "-", "--word-timestamps"], $this->file("lecture.json")));
        $this->assertSame(0, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "-o", "-", "--word-timestamps"])[0]);
        $this->assertStringNotContainsString("<00:", $this->runBinary(["convert", "lecture.json", "--to", "vtt", "-o", "-"])[1]);
        $this->assertSame(2, $this->runBinary(["convert", "lecture.json", "--to", "srt", "-o", "-", "--timing-fix-overlaps", "--structure-max-word-gap", "1"])[0]);
    }


    public function testStripSdh(): void
    {
        [$code, $stdout] = $this->runBinary(["convert", "trip.srt", "--sdh", "--to", "vtt", "-o", "-"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nWe need two tickets for the long ride to the coast.\n", $stdout);
        $this->assertStringContainsString("\nToo late.\n", $stdout);
        $this->assertStringNotContainsString("BELL", $stdout);

        [, $stdout] = $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--sdh", "--sdh-keep-parentheses", "--sdh-keep-speaker-labels"]);
        $this->assertStringContainsString("\nANNA: We need", $stdout);
        $this->assertStringContainsString("\n(sighs) Too late.\n", $stdout);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--sdh", "--sdh-brackets", "{"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--sdh-lyrics"])[0]);
    }
}
