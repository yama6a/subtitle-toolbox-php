<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\PlainTextWriteOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class PlainTextFormatterTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/";


    private function walk(): Subtitle
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, ["<v Mara><b>Hello</b>", "world."]));
        $subtitle->addCue(new SubtitleCue(2.5, 4, "Where are you  going?"));
        $subtitle->addCue(new SubtitleCue(6, 7, ["<font color=\"#ff0000\">Home</font> &amp; <00:00:06.500>bed.", "  "]));
        $subtitle->addCue(new SubtitleCue(3725, 3727, "Fish &lt;3"));

        return $subtitle;
    }


    public static function options(): array
    {
        return [
            "defaults"           => [new WriteOptions(), "Hello world. Where are you going?\n\nHome & bed.\n\nFish <3\n"],
            "lines kept"         => [new WriteOptions(formats: [new PlainTextWriteOptions(joinLines: false)]), "Hello\nworld. Where are you going?\n\nHome & bed.\n\nFish <3\n"],
            "one cue per line"   => [new WriteOptions(formats: [new PlainTextWriteOptions(joinCues: false)]), "Hello world.\nWhere are you going?\n\nHome & bed.\n\nFish <3\n"],
            "gap of 2.5 s"       => [new WriteOptions(formats: [new PlainTextWriteOptions(paragraphGap: 2.5)]), "Hello world. Where are you going? Home & bed.\n\nFish <3\n"],
            "gap of 0.5 s"       => [new WriteOptions(formats: [new PlainTextWriteOptions(paragraphGap: 0.5)]), "Hello world.\n\nWhere are you going?\n\nHome & bed.\n\nFish <3\n"],
            "no paragraphs"      => [new WriteOptions(formats: [new PlainTextWriteOptions(paragraphGap: INF)]), "Hello world. Where are you going? Home & bed. Fish <3\n"],
            "with times"         => [new WriteOptions(formats: [new PlainTextWriteOptions(withTimes: true)]),
                                     "[00:00:01] Hello world. Where are you going?\n\n[00:00:06] Home & bed.\n\n[01:02:05] Fish <3\n"],
            "CR LF"              => [new WriteOptions(lineEnding: LineEnding::Crlf, formats: [new PlainTextWriteOptions(paragraphGap: INF)]),
                                     "Hello world. Where are you going? Home & bed. Fish <3\r\n"],
        ];
    }


    #[DataProvider("options")]
    public function testFormatsWithOptions(WriteOptions $options, string $expected): void
    {
        $this->assertSame($expected, $this->walk()->toString(Format::PlainText, $options));
    }


    public function testMeasuresTheGapFromTheLatestEnd(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(0, 10, "Long"));
        $subtitle->addCue(new SubtitleCue(1, 2, "Short"));
        $subtitle->addCue(new SubtitleCue(11, 12, "Overlapped"));

        $this->assertSame("Long Short Overlapped\n", $subtitle->toString(Format::PlainText));
    }


    public function testSkipsCuesWithoutTextAndWritesNothingForNoCues(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "<i></i>"));
        $subtitle->addCue((new CueImage("png", 0, 0, 1, 1, 720, 576))->toCue(new SubtitleCue(3, 4)));

        $this->assertSame("", (new Subtitle())->toString(Format::PlainText));
        $this->assertSame("", $subtitle->toString(Format::PlainText, new WriteOptions(skipImageCues: true)));
    }


    public function testThrowsForANegativeParagraphGap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The paragraph gap must be 0 or more seconds, got -1.");

        new PlainTextWriteOptions(paragraphGap: -1);
    }


    public static function realFiles(): array
    {
        return [
            "defaults"   => ["youtube_studio_lf.txt", new WriteOptions()],
            "with times" => ["youtube_studio_lf_with_times.txt", new WriteOptions(formats: [new PlainTextWriteOptions(withTimes: true, joinCues: false)])],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileGivesTheExpectedTranscript(string $fileName, WriteOptions $options): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "sbv/real/youtube_studio_lf.sbv"), Format::Sbv);

        $this->assertSame(file_get_contents(self::DIR . "plaintext/real/$fileName"), $subtitle->toString(Format::PlainText, $options));
    }
}
