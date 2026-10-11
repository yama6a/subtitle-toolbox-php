<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\Attributes\Group;
use SubtitleToolbox\Format;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Checks the image formats and the OCR options.
 */
class BinaryOcrTest extends CliTestCase
{
    public function testForcedOnlyRunsOcrOnTheForcedCuesAlone(): void
    {
        copy(self::FILES . "vobsub/two-tracks-pal.idx", "$this->dir/movie.idx");
        copy(self::FILES . "vobsub/two-tracks-pal.sub", "$this->dir/movie.sub");
        $forced = Subtitle::load("$this->dir/movie.idx", Format::VobSub)->withForcedCuesOnly();

        [$code, $stdout, $stderr] = $this->runCli(["convert", "movie.idx", "--to", "srt", "-o", "-", "--ocr", "--ocr-engine", "glyph", "--forced-only"]);
        $this->assertSame([0, "movie.idx: OCR 1/1\n"], [$code, $stderr]);
        $this->assertCount(1, $forced);
        $this->assertSame(1, substr_count($stdout, " --> "));
    }


    public function testImageFormats(): void
    {
        mkdir("$this->dir/disc");
        copy(__DIR__ . "/../files/pgs/shapes_576p.sup", "$this->dir/disc/shapes.sup");
        copy(__DIR__ . "/../files/vobsub/two-tracks-pal.idx", "$this->dir/disc/tracks.idx");
        copy(__DIR__ . "/../files/vobsub/two-tracks-pal.sub", "$this->dir/disc/tracks.sub");

        $this->assertSame(
            [3, "", "disc/shapes.sup: The file holds image cues without text. Run OCR on them first, or pass --skip-image-cues.\n"],
            $this->runCli(["convert", "disc/shapes.sup", "--to", "srt"])
        );
        $this->assertSame([0, "disc/shapes.sup -> out/shapes.srt\n", ""], $this->runCli(["convert", "disc/shapes.sup", "--to", "srt", "--output-dir", "out", "--skip-image-cues"]));

        [$code, $stdout] = $this->runCli(["info", "disc", "--json"]);
        $this->assertSame(0, $code);
        $this->assertSame(["disc/shapes.sup", "disc/tracks.idx"], array_column(json_decode($stdout, true), "file"));
        $this->assertSame(["pgs", "vobsub"], array_column(json_decode($stdout, true), "format"));
        $this->assertSame(5, json_decode($stdout, true)[1]["statistics"]["cueCount"]);
    }


    public function testOcrReadsTheImageCuesOfTheTextFixtures(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        copy(__DIR__ . "/../files/vobsub/text-pal.idx", "$this->dir/text.idx");
        copy(__DIR__ . "/../files/vobsub/text-pal.sub", "$this->dir/text.sub");

        $this->assertSame([0, "text.sup -> text.srt\n", "text.sup: OCR 12/12\n"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr", "--ocr-engine", "glyph"]));
        $this->assertFileEquals(__DIR__ . "/../files/pgs/text_1080p.ocr.srt", "$this->dir/text.srt");

        [$code, $stdout, $stderr] = $this->runCli(["convert", "text.idx", "--to", "srt", "--output", "-", "--ocr", "--ocr-engine", "glyph"]);
        $this->assertSame([0, "text.idx: OCR 6/6\n"], [$code, $stderr]);
        $this->assertStringEqualsFile(__DIR__ . "/../files/vobsub/text-pal.ocr.srt", $stdout);
    }


    public function testOcrDatabaseReplacesTheLatinDatabase(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        (new GlyphDatabase())->save("$this->dir/empty.nocr");

        [$code, $stdout] = $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-database", "empty.nocr"]);

        $this->assertSame(0, $code);
        $subtitle = Subtitle::fromStringAutoDetectFormat($stdout);
        $this->assertCount(12, $subtitle->getCues());
        foreach ($subtitle->getCues() as $cue) {
            $this->assertMatchesRegularExpression("/^\\*+( \\*+)*$/", implode(" ", $cue->getLines()));
        }
    }


    public function testOcrOptionErrors(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        file_put_contents("$this->dir/broken.nocr", "no database");

        $this->assertSame([2, "", "Error: Pass --ocr with --ocr-database.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr-database", "broken.nocr"]));
        $this->assertSame([3, "", "Error: broken.nocr: Cannot read the glyph database - the data is not gzip-compressed!\n"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr", "--ocr-database", "broken.nocr"]));
        $this->assertFileDoesNotExist("$this->dir/text.srt");
    }


    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private function runWithPath(string $path, array $arguments): array
    {
        $original = getenv("PATH");
        putenv("PATH=$path");
        try {
            return $this->runBinary($arguments);
        } finally {
            putenv("PATH=$original");
        }
    }


    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private function runWithFakeTesseract(array $arguments): array
    {
        return $this->runWithPath(realpath(self::FILES . "ocr/fake-tesseract") . PATH_SEPARATOR . getenv("PATH"), $arguments);
    }


    public function testOcrPrefersTesseractAndPassesTheLanguage(): void
    {
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");

        [$code, $stdout, $stderr] = $this->runWithFakeTesseract(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr",
                                                                 "--ocr-language", "deu+eng"]);

        $this->assertSame([0, "text.sup: OCR 12/12\n"], [$code, $stderr]);
        $cues = Subtitle::fromStringAutoDetectFormat($stdout)->getCues();
        $this->assertCount(12, $cues);
        $this->assertSame("deu+eng psm6", $cues[0]->getLines()[0]);
    }


    public function testGlyphEngineIgnoresTheOcrLanguageWithOneWarning(): void
    {
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/again.sup");

        $this->assertSame([0, "text.sup -> out/text.srt\nagain.sup -> out/again.srt\n2 files: 2 succeeded, 0 failed.\n",
                           "Warning: the glyph engine ignores --ocr-language.\ntext.sup: OCR 12/12\nagain.sup: OCR 12/12\n"],
                          $this->runWithFakeTesseract(["convert", "text.sup", "again.sup", "--to", "srt", "--output-dir", "out", "--ocr",
                                                       "--ocr-engine", "glyph", "--ocr-language", "deu"]));
        $this->assertFileEquals(self::FILES . "pgs/text_1080p.ocr.srt", "$this->dir/out/text.srt");
    }


    public function testOcrEngineErrors(): void
    {
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");
        $usage = "Run \"subtitle-toolbox help convert\" for the usage.\n";

        $this->assertSame([2, "", "Error: The option --ocr-engine must be tesseract or glyph, got \"easyocr\".\n$usage"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr", "--ocr-engine", "easyocr"]));
        $this->assertSame([2, "", "Error: Pass --ocr-engine glyph with --ocr-database.\n$usage"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr", "--ocr-engine", "tesseract",
                                            "--ocr-database", "my.nocr"]));
        $this->assertSame([2, "", "Error: Pass --ocr with --ocr-engine.\n$usage"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr-engine", "glyph"]));
        $this->assertSame([2, "", "Error: Pass --ocr with --ocr-language.\n$usage"],
                          $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr-language", "deu"]));
        $this->assertSame([2, "", "Error: Cannot run OCR with Tesseract: the program \"tesseract\" is missing. " .
                                  TesseractOcrEngine::INSTALL_HINT . "\n$usage"],
                          $this->runWithPath($this->dir, ["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr", "--ocr-engine", "tesseract"]));
        $this->assertSame([2, "", "Error: Cannot run OCR with Tesseract in the language \"fra\": the language data of " .
                                  "fra is missing. Install it, for example with apt install tesseract-ocr-fra. The " .
                                  "installed languages are: deu, eng, osd.\n$usage"],
                          $this->runWithFakeTesseract(["convert", "text.sup", "--to", "srt", "-o", "text.srt", "--ocr", "--ocr-language", "fra"]));
        $this->assertFileDoesNotExist("$this->dir/text.srt");
    }


    #[Group("tesseract")]
    public function testOcrWithTesseractReadsLatinAndCyrillicText(): void
    {
        if (!TesseractOcrEngine::isInstalled()) {
            $this->markTestSkipped("Tesseract is not installed.");
        }
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");
        copy(self::FILES . "pgs/text_cyrillic_1080p.sup", "$this->dir/cyrillic.sup");

        [$code, $stdout] = $this->runCli(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-engine", "tesseract"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nThe train to Bergen leaves at 7:45.\n", $stdout);

        [$code, $stdout] = $this->runCli(["convert", "cyrillic.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-language", "rus"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nПоезд в Берген уходит в 7:45.\n", $stdout);
    }


    public function testOcrLeavesFilesWithoutImageCuesAsTheyAre(): void
    {
        $this->assertSame([0, "trip.srt -> trip.vtt\n", ""], $this->runCli(["convert", "trip.srt", "--to", "vtt", "--ocr", "-o", "trip.vtt"]));
        $this->assertSame(Subtitle::fromStringAutoDetectFormat($this->file("trip.srt"))->toString(Format::WebVtt), $this->file("trip.vtt"));
    }


    public function testInfoCountsImageCuesAndImageCuesWithText(): void
    {
        copy(__DIR__ . "/../files/vobsub/text-pal.idx", "$this->dir/text.idx");
        copy(__DIR__ . "/../files/vobsub/text-pal.sub", "$this->dir/text.sub");
        $this->assertSame(0, $this->runCli(["convert", "text.idx", "--to", "json", "-o", "text.json", "--ocr"])[0]);

        [$code, $stdout] = $this->runCli(["info", "text.idx"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("\n  Cues:                  6\n  Image cues:            6, 0 with text\n", $stdout);

        [, $stdout] = $this->runCli(["info", "text.json"]);
        $this->assertStringContainsString("\n  Cues:                  6\n  Image cues:            6, 6 with text\n", $stdout);

        [$code, $stdout] = $this->runCli(["info", "text.idx", "text.json", "trip.srt", "--json"]);
        $this->assertSame(0, $code);
        $this->assertSame([["count" => 6, "withText" => 0], ["count" => 6, "withText" => 6], ["count" => 0, "withText" => 0]],
                          array_column(json_decode($stdout, true), "imageCues"));

        [, $stdout] = $this->runCli(["info", "trip.srt"]);
        $this->assertStringNotContainsString("Image cues", $stdout);
    }
}
