<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Format;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityMask;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\WriteOptions;

/**
 * Checks the options that change the text of the cues: tags, speakers, case, masks, mutes, karaoke and fixes.
 */
class BinaryEditTest extends CliTestCase
{
    public function testStripTags(): void
    {
        copy(self::FILES . "transforms/own_cea608_caps.vtt", "$this->dir/caps.vtt");

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_cea608_caps_stripped.vtt"), ""],
                          $this->runCli(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--strip-tags"]));
    }


    public function testRtl(): void
    {
        copy(self::FILES . "transforms/own_rtl.srt", "$this->dir/rtl.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_rtl_fixed.srt"), ""],
                          $this->runCli(["convert", "rtl.srt", "--to", "srt", "-o", "-", "--rtl", "fix"]));
        copy(self::FILES . "transforms/own_rtl_fixed.srt", "$this->dir/fixed.srt");
        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_rtl.srt"), ""],
                          $this->runCli(["convert", "fixed.srt", "--to", "srt", "-o", "-", "--no-bom", "--rtl", "clean"]));
        $this->assertSame(self::usageError("convert", "The option --rtl must be fix or clean, got \"left\"."),
                          $this->runCli(["convert", "rtl.srt", "--to", "srt", "-o", "-", "--rtl", "left"]));
    }


    public function testForcedOnly(): void
    {
        copy(__DIR__ . "/../files/forced/forced_signs_2398.itt", "$this->dir/signs.itt");

        [$code, $stdout, $stderr] = $this->runCli(["convert", "signs.itt", "--to", "srt", "-o", "signs.srt", "--forced-only"]);

        $this->assertSame([0, "signs.itt -> signs.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertFileEquals(self::FILES . "forced/forced_signs_2398_forced_only.srt", "$this->dir/signs.srt");
        $this->assertSame(6, substr_count($this->runCli(["convert", "signs.itt", "--to", "srt", "-o", "-"])[1], " --> "));
    }


    public function testSpeakers(): void
    {
        $files = __DIR__ . "/../files/speakers/";
        copy($files . "voices.vtt", "$this->dir/voices.vtt");
        copy($files . "sdh_labels.srt", "$this->dir/labels.srt");

        foreach (["prefix", "dashes", "colors"] as $mode) {
            $this->assertSame(
                [0, file_get_contents($files . "voices_$mode.srt"), ""],
                $this->runCli(["convert", "voices.vtt", "--to", "srt", "-o", "-", "--no-bom", "--speakers", $mode])
            );
        }
        $this->assertSame(
            [0, file_get_contents($files . "sdh_labels_voices.vtt"), ""],
            $this->runCli(["convert", "labels.srt", "--to", "vtt", "-o", "-", "--no-bom", "--speakers", "from-prefix"])
        );
        $this->assertSame(
            self::usageError("convert", "The option --speakers must be prefix, dashes, colors or from-prefix, got \"names\"."),
            $this->runCli(["convert", "voices.vtt", "--to", "srt", "--speakers", "names"])
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
            $this->runCli(["convert", "voices.vtt", "--to", "srt", "-o", "-", "--no-bom", "--strip-tags", "--speakers", "prefix"])
        );
        $this->assertSame(
            [0, $voices->toString(Format::WebVtt, new WriteOptions(bom: false)), ""],
            $this->runCli(["convert", "labels.srt", "--to", "vtt", "-o", "-", "--no-bom", "--case", "lower", "--speakers", "from-prefix"])
        );
    }


    public function testReplaceAndCase(): void
    {
        copy(self::FILES . "transforms/own_cea608_caps.vtt", "$this->dir/caps.vtt");
        copy(self::FILES . "transforms/own_multilingual_caps.srt", "$this->dir/multi.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_cea608_caps_sentence.vtt"), ""],
                          $this->runCli(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--case", "sentence"]));
        $this->assertSame(
            [0, file_get_contents(self::FILES . "transforms/own_cea608_caps_cleaned.vtt"), ""],
            $this->runCli(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--replace-regex", "--replace", '/\[[^\]]*\]/=',
                              "--replace", '/\.{4,}/=...', "--strip-tags"])
        );

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_multilingual_caps_upper.srt"), ""],
                          $this->runCli(["convert", "multi.srt", "--to", "srt", "-o", "-", "--case", "upper"]));
        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_multilingual_caps_lower_tr.srt"), ""],
                          $this->runCli(["convert", "multi.srt", "--to", "srt", "-o", "-", "--case", "lower", "--language", "tr"]));
        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_multilingual_caps_replaced.srt"), ""],
                          $this->runCli(["convert", "multi.srt", "--to", "srt", "-o", "-", "--replace", "uhr=Uhr", "--replace-ignore-case"]));
    }


    public function testMaskWords(): void
    {
        $files = __DIR__ . "/../files/profanity/";
        copy($files . "keys.srt", "$this->dir/keys.srt");
        copy($files . "words.txt", "$this->dir/words.txt");

        $this->assertSame([0, file_get_contents($files . "keys_stars.srt"), ""],
                          $this->runCli(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt"]));
        $this->assertSame([0, file_get_contents($files . "keys_first_letter.srt"), ""],
                          $this->runCli(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mask", "first-letter"]));
        $this->assertSame([0, file_get_contents($files . "keys_removed.srt"), ""],
                          $this->runCli(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mask", "remove"]));
        $this->assertSame(
            [3, "", "Error: missing.txt: The file does not exist.\n"],
            $this->runCli(["convert", "keys.srt", "--to", "srt", "--mask-words", "missing.txt"])
        );
    }


    public function testMuteRanges(): void
    {
        copy(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
        $subtitle = Subtitle::fromString($this->file("radio.vtt"));
        $ranges   = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"], ProfanityMask::None, 0.1))->muteRanges;

        [$code, $stdout, $stderr] = $this->runCli(["convert", "radio.vtt", "--to", "vtt", "-o", "out.vtt", "--mask-words", "words.txt", "--mask", "none",
                                                      "--mute-edl", "radio.edl", "--mute-filter", "radio.af", "--mute-padding", "00:00:00,1"]);
        $this->assertSame([0, "radio.vtt -> out.vtt\nradio.vtt -> radio.edl\nradio.vtt -> radio.af\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($subtitle->toString(Format::WebVtt), $this->file("out.vtt"));
        $this->assertSame(MuteRange::toEdl($ranges), $this->file("radio.edl"));
        $this->assertSame("1.500 2.100 1\n6.200 6.800 1\n7.900 9.100 1\n", $this->file("radio.edl"));
        $this->assertFileEquals(self::FILES . "profanity/radio.af", "$this->dir/radio.af");
        $this->assertSame(self::usageError("convert", "The --mute-edl file radio.edl exists. The tool never overwrites a file. Remove it, or pass " .
                                                      "another output file or directory."),
                          $this->runCli(["convert", "radio.vtt", "--to", "srt", "-o", "new.srt", "--mask-words", "words.txt", "--mute-edl", "radio.edl"]));
        $this->assertFileDoesNotExist("$this->dir/new.srt");
        $this->assertSame(self::usageError("convert", "Pass --mask-words with --mute-edl."),
                          $this->runCli(["convert", "radio.vtt", "--to", "srt", "--mute-edl", "new.edl"]));
        $this->assertSame(self::usageError("convert", "--mute-edl and --mute-filter take one input file, got 2."),
                          $this->runCli(["convert", "radio.vtt", "trip.srt", "--to", "vtt", "--output-dir", "out", "--mask-words", "words.txt", "--mute-edl", "new.edl"]));
        $this->assertFileDoesNotExist("$this->dir/new.edl");
    }


    public function testMuteFilesNeverOverwriteAnotherFileOfTheRun(): void
    {
        copy(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
        $run = fn (string ...$options): array => $this->runCli(["convert", "radio.vtt", "--to", "srt", "--mask-words", "words.txt", ...$options]);

        $this->assertSame(self::usageError("convert", "The --mute-filter file radio.mute is also the output of --mute-edl."),
                          $run("-o", "out.srt", "--mute-edl", "radio.mute", "--mute-filter", "radio.mute"));
        $this->assertSame(self::usageError("convert", "The --mute-edl file out.srt is also the output of radio.vtt."),
                          $run("-o", "out.srt", "--mute-edl", "out.srt"));
        $this->assertSame(self::usageError("convert", "The --mute-edl file radio.vtt is a file that the command reads. Pass another output file or directory."),
                          $run("-o", "out.srt", "--mute-edl", "radio.vtt"));
        $this->assertSame(self::usageError("convert", "The --mute-filter file ./words.txt is a file that the command reads. Pass another output file or directory."),
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

        [$code, , $stderr] = $this->runCli(["convert", "keys.srt", "--to", "srt", "-o", "out.srt", "--shift", "100", "--timing-min-duration", "3",
                                               "--mask-words", "words.txt", "--mute-edl", "keys.edl", "--mute-filter", "keys.af"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertSame("103.400 105.500 1\n108.000 109.100 1\n111.500 116.200 1\n", $this->file("keys.edl"));
        $this->assertSame(MuteRange::toEdl($ranges), $this->file("keys.edl"));
        $this->assertSame(MuteRange::toFfmpegVolumeFilter($ranges) . "\n", $this->file("keys.af"));
        $this->assertSame($subtitle->toString(Format::SubRip), $this->file("out.srt"));
    }


    public function testKaraokeWritesOneCuePerWord(): void
    {
        $this->copySongs();

        $this->assertSame([0, "song.json -> word.srt\n", ""], $this->runCli(["convert", "song.json", "--to", "srt", "-o", "word.srt", "--karaoke"]));
        $this->assertFileEquals(self::FILES . "karaoke/whisper_word.srt", "$this->dir/word.srt");
    }


    public function testKaraokeStyleSetsTheHighlightTag(): void
    {
        $this->copySongs();

        $this->assertSame(
            [0, file_get_contents(self::FILES . "karaoke/lrc_yellow.srt"), ""],
            $this->runCli(["convert", "song.lrc", "--to", "srt", "-o", "-", "--karaoke", "--karaoke-style", 'font color="#ffff00"'])
        );
    }


    public function testAssKaraokeTagSetsTheTagOfAssOutput(): void
    {
        $this->copySongs();

        $this->assertSame([0, file_get_contents(self::FILES . "karaoke/whisper_kf.ass"), ""],
                          $this->runCli(["convert", "song.json", "--to", "ass", "-o", "-", "--ass-karaoke-tag", "kf"]));
    }


    public function testAssStyleSetsTheDefaultStyle(): void
    {
        $this->copySongs();

        $this->assertStringContainsString("\nStyle: Default,Roboto,48,",
                                          $this->runCli(["convert", "song.json", "--to", "ass", "-o", "-", "--ass-style", "Fontname=Roboto,Fontsize=48"])[1]);
    }


    public function testAssOptionsWithoutAssOutputWriteNothing(): void
    {
        $this->copySongs();

        $this->assertSame(self::usageError("convert", "Pass --to ass with --ass-karaoke-tag."),
                          $this->runCli(["convert", "song.json", "--to", "srt", "--ass-karaoke-tag", "kf", "--output-dir", "out"]));
        $this->assertSame(self::usageError("convert", "Pass --to ass with --ass-karaoke-tag."),
                          $this->runCli(["convert", "song.json", "-o", "song.srt", "--ass-karaoke-tag", "kf"]));
        $this->assertSame(self::usageError("convert", "Pass --to ass with --ass-karaoke-tag and --ass-style."),
                          $this->runCli(["convert", "song.json", "--to", "srt", "-o", "-", "--ass-karaoke-tag", "kf", "--ass-style", "Fontsize=48"]));
        $this->assertDirectoryDoesNotExist("$this->dir/out");
        $this->assertFileDoesNotExist("$this->dir/song.srt");
    }


    private function copySongs(): void
    {
        copy(self::FILES . "whisper/real/openai_whisper_word_timestamps.json", "$this->dir/song.json");
        copy(self::FILES . "lrc/real/handwritten-enhanced.lrc", "$this->dir/song.lrc");
    }


    public function testFixWrapsAndFixesTheTiming(): void
    {
        [$code, $stdout] = $this->runCli(["convert", "trip.srt", "--to", "srt", "-o", "-", "--timing-fix-overlaps", "--timing-min-gap", "0.1", "--timing-min-duration", "1", "--structure-wrap", "--structure-max-cpl", "30"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:01,000 --> 00:00:02,400\n", $stdout);
        $this->assertStringContainsString("[BELL RINGS] ANNA: We need two tickets\nfor the long ride to the coast.\n", $stdout);
        $this->assertStringContainsString("00:00:06,000 --> 00:00:07,000\n", $stdout);
    }


    public function testFixMergeShort(): void
    {
        copy(__DIR__ . "/../files/short-cues/own_speech_to_text.srt", "$this->dir/speech.srt");
        $narrow = Subtitle::fromString($this->file("speech.srt"))
            ->mergeShortCues(new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 20, maxLinesPerCue: 3)))
            ->toString(Format::SubRip);

        $this->assertSame(
            [0, file_get_contents(__DIR__ . "/../files/short-cues/own_speech_to_text_merged.srt"), ""],
            $this->runCli(["convert", "speech.srt", "--to", "srt", "-o", "-", "--structure-merge-short"])
        );
        $this->assertSame([0, $narrow, ""], $this->runCli(["convert", "speech.srt", "--to", "srt", "-o", "-", "--structure-merge-short", "--structure-max-cpl", "20", "--structure-max-lines", "3"]));
    }


    public function testFixSplitLong(): void
    {
        copy(__DIR__ . "/../files/resegmenting/own_whisper_long_segments.json", "$this->dir/whisper.json");
        $split = function (ResegmentOptions $options): string {
            $subtitle = Subtitle::fromString($this->file("whisper.json"));
            Resegmenter::apply($subtitle, $options);

            return $subtitle->toString(Format::WebVtt);
        };

        [$code, $stdout, $stderr] = $this->runCli(["convert", "whisper.json", "--structure-split-long", "--word-timestamps", "--to", "vtt", "-o", "-"]);

        $this->assertSame([0, file_get_contents(self::FILES . "resegmenting/own_whisper_long_segments_split.vtt"), ""], [$code, $stdout, $stderr]);
        $this->assertSame([0, $split(new ResegmentOptions(ResegmentMode::SplitLong)), ""],
                          $this->runCli(["convert", "whisper.json", "--structure-split-long", "--to", "vtt", "-o", "-"]));
        $this->assertSame(
            [0, $split(new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1))), ""],
            $this->runCli(["convert", "whisper.json", "--structure-split-long", "--structure-max-cpl", "30", "--structure-max-lines", "1", "--to", "vtt", "-o", "-"])
        );
    }


    public function testFixCommonErrors(): void
    {
        copy(self::FILES . "fixing/web-errors.srt", "$this->dir/web.srt");
        copy(self::FILES . "fixing/text-pal.ocr.srt", "$this->dir/pal.srt");
        copy(self::FILES . "fixing/user_OCRFixReplaceList.xml", "$this->dir/list.xml");

        [$code, $stdout, $stderr] = $this->runCli(["convert", "web.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--line-ending", "crlf"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringEqualsFile(self::FILES . "fixing/web-errors.fixed.srt", $stdout);

        [$code, $stdout, $stderr] = $this->runCli(["convert", "pal.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--errors-replace-list", "list.xml",
                                                      "--errors-list-fixes"]);
        $this->assertSame(0, $code);
        $this->assertStringEqualsFile(self::FILES . "fixing/text-pal.fixed.srt", $stdout);
        $this->assertStringStartsWith("pal.srt: cue 1: ", $stderr);
        $this->assertStringContainsString(": replaceList: ", $stderr);

        $this->assertSame(self::usageError("convert", "Pass --case or --errors-fix with --language."),
                          $this->runCli(["convert", "web.srt", "--to", "srt", "-o", "-", "--timing-fix-overlaps", "--language", "en"]));
        $this->assertSame(self::usageError("convert", "Pass --errors-fix with --errors-list-fixes."),
                          $this->runCli(["convert", "web.srt", "--to", "srt", "-o", "-", "--errors-list-fixes"]));
        $this->assertSame([3, "", "Error: missing.xml: The file does not exist.\n"],
                          $this->runCli(["convert", "web.srt", "--to", "srt", "-o", "-", "--errors-fix", "--errors-replace-list", "missing.xml"]));
    }


    public function testErrorsEnableTurnsOnRulesThatAreOffByDefault(): void
    {
        copy(self::FILES . "fixing/optional-rules.srt", "$this->dir/optional.srt");
        $names  = ["doubleApostrophes", "loneLowercaseI", "unneededPeriods", "dialogueOnOneLine", "musicNotes", "sentenceStartCase"];
        $enable = implode(",", $names);

        $this->assertSame([0, file_get_contents(self::FILES . "fixing/optional-rules.fixed.srt"), ""],
                          $this->runCli(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--errors-enable", $enable]));
        $this->assertSame([0, file_get_contents(self::FILES . "fixing/optional-rules.fixed.srt"), ""],
                          $this->runCli(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--errors-enable", strtoupper($enable)]));
        $this->assertSame([0, file_get_contents(self::FILES . "fixing/optional-rules.srt"), ""],
                          $this->runCli(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--language", "en", "--no-bom"]));
        $this->assertSame(self::usageError("convert", "Unknown rule \"dialogOnOneLine\" in --errors-enable. The valid names are " . implode(", ", $names) . "."),
                          $this->runCli(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-fix", "--errors-enable", "$enable,dialogOnOneLine"]));
        $this->assertSame(self::usageError("convert", "Pass --errors-fix with --errors-enable."),
                          $this->runCli(["convert", "optional.srt", "--to", "srt", "-o", "-", "--errors-enable", $enable]));
    }


    public function testWordTimestampsAndResegment(): void
    {
        copy(self::FILES . "resegmenting/own_whisper_long_segments.json", "$this->dir/lecture.json");

        [$code, $stdout, $stderr] = $this->runCli(["convert", "lecture.json", "--structure-resegment", "--to", "srt", "-o", "lecture.srt"]);
        $this->assertSame([0, "lecture.json -> lecture.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertFileEquals(self::FILES . "resegmenting/own_whisper_long_segments_resegmented.srt", "$this->dir/lecture.srt");

        $this->assertSame(
            [0, file_get_contents(self::FILES . "resegmenting/own_whisper_long_segments_resegmented_30x1.srt"), ""],
            $this->runCli(["convert", "lecture.json", "--structure-resegment", "--structure-max-cpl", "30", "--structure-max-lines", "1", "--structure-max-word-gap", "00:00:00.3",
                                   "--to", "srt", "-o", "-"])
        );

        $words = file_get_contents(self::FILES . "resegmenting/own_whisper_long_segments_words.vtt");
        $this->assertSame([0, $words, ""],
                          $this->runCli(["convert", "lecture.json", "--to", "vtt", "-o", "-", "--word-timestamps"]));
        $this->assertSame([0, $words, ""],
                          $this->runCli(["convert", "lecture.json", "--from", "whisper", "--to", "vtt", "-o", "-", "--word-timestamps"]));
        $this->assertSame([0, $words, ""],
                          $this->runCli(["convert", "-", "--to", "vtt", "-o", "-", "--word-timestamps"], $this->file("lecture.json")));
        $this->assertSame(0, $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "-", "--word-timestamps"])[0]);
        $this->assertStringNotContainsString("<00:", $this->runCli(["convert", "lecture.json", "--to", "vtt", "-o", "-"])[1]);
    }


    public function testStripSdh(): void
    {
        [$code, $stdout] = $this->runCli(["convert", "trip.srt", "--sdh", "--to", "vtt", "-o", "-"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nWe need two tickets for the long ride to the coast.\n", $stdout);
        $this->assertStringContainsString("\nToo late.\n", $stdout);
        $this->assertStringNotContainsString("BELL", $stdout);
    }


    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function editUsageErrors(): array
    {
        $caps = ["convert", "caps.vtt", "--to", "vtt", "-o", "-"];
        $song = ["convert", "song.json", "--to", "srt", "-o", "-"];
        $trip = ["convert", "trip.srt", "--to", "srt", "-o", "-"];
        $wrapLimits = "Pass --structure-wrap, --structure-resegment, --structure-merge-short or --structure-split-long with";

        return [
            "--replace without ="           => [[...$caps, "--replace", "colour"], "convert", "The option --replace needs FROM=TO, got \"colour\"."],
            "--replace with empty FROM"     => [[...$caps, "--replace", "=x"], "convert", "The option --replace needs FROM=TO, got \"=x\"."],
            "--replace-regex invalid"       => [[...$caps, "--replace-regex", "--replace", "/(/=x"], "convert", "The option --replace has the invalid regular expression \"/(/\"."],
            "--replace-regex alone"         => [[...$caps, "--replace-regex"], "convert", "Pass --replace with --replace-regex."],
            "--replace-ignore-case alone"   => [[...$caps, "--replace-ignore-case"], "convert", "Pass --replace with --replace-ignore-case."],
            "--case title"                  => [[...$caps, "--case", "title"], "convert", "The option --case must be upper, lower or sentence, got \"title\"."],
            "--language alone"              => [[...$caps, "--language", "tr"], "convert", "Pass --case or --errors-fix with --language."],
            "--regex"                       => [[...$caps, "--regex", "--replace", "a=b"], "convert", "Unknown option --regex."],
            "--case-language"               => [[...$caps, "--case-language", "tr"], "convert", "Unknown option --case-language."],
            "--mask beep"                   => [["convert", "keys.srt", "--to", "srt", "--mask-words", "words.txt", "--mask", "beep"], "convert",
                                                "The option --mask must be stars, first-letter, remove or none, got \"beep\"."],
            "--mask without --mask-words"   => [["convert", "keys.srt", "--to", "srt", "--mask", "stars"], "convert", "Pass --mask-words with --mask."],
            "--mute-edl to standard output" => [["convert", "radio.vtt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mute-edl", "-"], "convert",
                                                "The option --mute-edl needs a file path."],
            "--ass-karaoke-tag x"           => [[...$song, "--ass-karaoke-tag", "x"], "convert", "The option --ass-karaoke-tag must be k, kf or ko, got \"x\"."],
            "--karaoke and --ass-karaoke-tag" => [[...$song, "--karaoke", "--ass-karaoke-tag", "k"], "convert", "Pass only one of --karaoke and --ass-karaoke-tag."],
            "--karaoke-style alone"         => [[...$song, "--karaoke-style", "b"], "convert", "Pass --karaoke with --karaoke-style."],
            "--karaoke-style em"            => [[...$song, "--karaoke", "--karaoke-style", "em"], "convert",
                                                "The style em must be one of the core markup tags b, i, u, s or font, for example u or font color=\"#ffff00\"."],
            "--karaoke-mode"                => [[...$song, "--karaoke", "--karaoke-mode", "cumulative"], "convert", "Unknown option --karaoke-mode."],
            "--karaoke-words"               => [[...$song, "--karaoke", "--karaoke-words", "2"], "convert", "Unknown option --karaoke-words."],
            "--karaoke-tag"                 => [[...$song, "--karaoke-tag", "k"], "convert", "Unknown option --karaoke-tag."],
            "--structure-max-cpl 0"         => [[...$trip, "--structure-wrap", "--structure-max-cpl", "0"], "convert",
                                                "The option --structure-max-cpl needs a whole number of 1 or more, got \"0\"."],
            "--timing-min-gap without fix"  => [[...$trip, "--timing-min-gap", "-1"], "convert",
                                                "Pass --timing-fix-overlaps, --timing-min-duration, --timing-lead-in or --timing-lead-out with --timing-min-gap."],
            "max cpl without fix"           => [[...$trip, "--structure-max-cpl", "30"], "convert", "$wrapLimits --structure-max-cpl."],
            "max cpl with unwrap"           => [[...$trip, "--structure-max-cpl", "30", "--structure-unwrap"], "convert", "$wrapLimits --structure-max-cpl."],
            "max lines without fix"         => [[...$trip, "--structure-max-lines", "1", "--timing-fix-overlaps"], "convert", "$wrapLimits --structure-max-lines."],
            "min gap without fix"           => [[...$trip, "--timing-min-gap", "0.1", "--structure-wrap"], "convert",
                                                "Pass --timing-fix-overlaps, --timing-min-duration, --timing-lead-in or --timing-lead-out with --timing-min-gap."],
            "merge short --structure-max-cpl 0" => [["convert", "speech.srt", "--to", "srt", "-o", "-", "--structure-merge-short", "--structure-max-cpl", "0"], "convert",
                                                    "The option --structure-max-cpl needs a whole number of 1 or more, got \"0\"."],
            "--structure-max-word-gap without resegment" => [["convert", "lecture.json", "--to", "srt", "-o", "-", "--timing-fix-overlaps", "--structure-max-word-gap", "1"],
                                                             "convert", "Pass --structure-resegment with --structure-max-word-gap."],
            "--sdh-brackets {"              => [[...$trip, "--sdh", "--sdh-brackets", "{"], "convert",
                                                "The option --sdh-brackets needs two characters, an opening and a closing one, got \"{\"."],
            "--sdh-lyrics without --sdh"    => [[...$trip, "--sdh-lyrics"], "convert", "Pass --sdh with --sdh-lyrics."],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("editUsageErrors")]
    public function testUsageError(array $arguments, string $command, string $message): void
    {
        $this->assertSame(self::usageError($command, $message), $this->runCli($arguments));
    }
}
