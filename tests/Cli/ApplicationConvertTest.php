<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityMask;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\RunsApplication;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChangeTiming;

class ApplicationConvertTest extends TestCase
{
    use RunsApplication;

    private const FILES = __DIR__ . "/../files/";


    public function testConvertRunsOcrAndFixesCommonErrorsInOneCall(): void
    {
        // The golden file comes from the Latin database. The default database reads one more letter right.
        $latin = __DIR__ . "/../../vendor/yama6a/php-glyph-ocr/resources/Latin.nocr";

        [$code, $stdout, $stderr] = self::runApplication(["convert", self::FILES . "fixing/ocr-en.sup", "--to", "srt", "-o", "-", "--ocr",
                                                          "--ocr-engine", "glyph", "--ocr-database", $latin, "--errors-fix", "--language", "en"]);

        $this->assertSame([0, file_get_contents(self::FILES . "fixing/ocr-en.fixed.srt")], [$code, $stdout]);
        $this->assertStringEndsWith(": OCR 6/6\n", $stderr);
    }


    public function testConvertRunsTextAndTimingEditsInOneCall(): void
    {
        $expected = Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip);
        HearingImpairedRemover::apply($expected, new HearingImpairedOptions());
        $expected->fixOverlaps()->shift(-0.5);

        $this->assertSame([0, $expected->toString(Format::WebVtt), ""],
                          self::runApplication(["convert", self::FILES . "cli/trip.srt", "--sdh", "--timing-fix-overlaps", "--shift", "-0.5", "--to", "vtt", "-o", "-"]));
        $this->assertSame("\u{FEFF}WEBVTT\n\n1\n00:00:00.500 --> 00:00:02.000\n<i>The train leaves at noon.</i>\n\n" .
                          "2\n00:00:02.000 --> 00:00:04.500\nWe need two tickets for the long ride to the coast.\n\n" .
                          "3\n00:00:05.500 --> 00:00:05.900\nToo late.\n", $expected->toString(Format::WebVtt));
    }


    /**
     * Each set: the input file, the convert options and the expected file.
     *
     * @return array<string, array{string, list<string>, string}>
     */
    public static function goldenFiles(): array
    {
        return [
            "SDH"           => ["hearing-impaired/own_sdh.srt", ["--sdh", "--line-ending", "crlf", "--bom"], "hearing-impaired/own_sdh_removed.srt"],
            "shot changes"  => ["shot-changes/own_garden_24fps.srt", ["--snap-shot-changes", self::FILES . "shot-changes/own_ffmpeg_showinfo.log",
                                "--video-fps", "24"], "shot-changes/own_garden_24fps_timed.srt"],
            "timing fixes"  => ["fixes/own_overlaps_and_short_cues.srt", ["--timing-fix-overlaps", "--timing-min-duration", "0.833", "--timing-min-gap", "0.083",
                                "--structure-wrap", "--structure-max-cpl", "42"], "fixes/own_overlaps_and_short_cues_fixed.srt"],
            "same time"     => ["editing/own_same_time_speakers.ass", ["--structure-merge-same-time", "--no-bom"], "editing/own_same_time_speakers_merged.srt"],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("goldenFiles")]
    public function testConvertEditsMatchTheGoldenFiles(string $input, array $options, string $expected): void
    {
        $this->assertSame([0, file_get_contents(self::FILES . $expected), ""],
                          self::runApplication(["convert", self::FILES . $input, "--to", "srt", "-o", "-", ...$options]));
    }


    public function testConvertRunsAllGroupsInTheFixedOrder(): void
    {
        $words = tempnam(sys_get_temp_dir(), "words");
        file_put_contents($words, "tickets\n");

        $expected = Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip);
        CommonErrorFixer::apply($expected, new CommonErrorOptions(language: "en"));
        HearingImpairedRemover::apply($expected, new HearingImpairedOptions(parentheses: false));
        $expected->replaceText("Too late", "[late] too late")->stripFormatting()->changeCase(CaseMode::Upper, "en");
        SpeakerLabels::apply($expected, new SpeakerLabelOptions(to: SpeakerStyle::Prefix));
        ProfanityFilter::apply($expected, new ProfanityOptions(["tickets"], ProfanityMask::FirstLetter));
        $expected->wrapLines(20)->removeDuplicateCues()->shift(-0.5)->scale(1.001);
        ShotChangeTiming::apply($expected, new ShotChangeOptions(24));
        $expected->fixOverlaps(0.1)->extendShortCues(1.0, 0.1);
        WordHighlight::apply($expected, new WordHighlightOptions(style: "b"));

        [$code, $stdout, $stderr] = self::runApplication([
            "convert", self::FILES . "cli/trip.srt", "--to", "srt", "-o", "-",
            "--karaoke", "--karaoke-style", "b", "--timing-min-duration", "1", "--timing-fix-overlaps", "--timing-min-gap", "0.1",
            "--snap-min-gap-frames", "2", "--video-fps", "24", "--scale", "1.001", "--shift", "-0.5", "--structure-merge-duplicates", "--structure-wrap", "--structure-max-cpl", "20",
            "--mask-words", $words, "--mask", "first-letter", "--speakers", "prefix", "--case", "upper", "--strip-tags",
            "--replace", "Too late=[late] too late", "--sdh", "--sdh-keep-parentheses", "--language", "en", "--errors-fix",
            "--ocr", "--ocr-engine", "glyph",
        ]);
        unlink($words);

        $this->assertSame([0, $expected->toString(Format::SubRip), ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("\nWE NEED TWO T****** FOR\n", $stdout);
        $this->assertStringContainsString("\n(SIGHS) [LATE]\nTOO LATE.\n", $stdout);
    }


    #[RunInSeparateProcess]
    public function testOcrWithoutEitherEngineFailsWithBothInstallHints(): void
    {
        putenv("PATH=" . __DIR__);
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(function (string $class) use ($loader): void {
                if (!str_starts_with($class, "GlyphOcr\\")) {
                    $loader->loadClass($class);
                }
            });
        }

        $this->assertSame(
            self::usageError("convert", "Cannot run OCR: neither Tesseract nor the package yama6a/php-glyph-ocr is installed. " .
                                        TesseractOcrEngine::INSTALL_HINT . " Or install php-glyph-ocr with: composer require yama6a/php-glyph-ocr"),
            self::runApplication(["convert", __DIR__ . "/../files/pgs/text_1080p.sup", "--to", "srt", "--output", "-", "--ocr"])
        );
    }
}
