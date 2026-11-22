<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class HtmlTranscriptRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/html/real/";


    public static function realFiles(): array
    {
        return [
            "spec example shape" => [
                "spec_example_shape.html",
                5,
                [0.0, 12.0, "<v Marta>Welcome back to the garden show. Today we're planting tomatoes, and we'll talk about the right soil for them."],
                [62.0, 67.0, "<v Marta>That's all for this week. Next time we talk about water."],
            ],
            "hh:mm:ss times and empty paragraphs" => [
                "hhmmss_empty_paragraphs.html",
                6,
                [0.0, 4.0, "<v Speaker 1>Good morning and welcome to the station news."],
                [3603.0, 3608.0, "<v Speaker 1>See you next week."],
            ],
        ];
    }


    private static function parse(string $fileName): Subtitle
    {
        return (new HtmlTranscriptParser())->parse(file_get_contents(self::DIR . $fileName), new ReadOptions());
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = self::parse($fileName)->getCues();
        $last = $cues[count($cues) - 1];

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    public static function realFileNames(): array
    {
        return array_map(fn (array $file): array => [$file[0]], self::realFiles());
    }


    #[DataProvider("realFileNames")]
    public function testFormatterOutputRoundTripsByteForByte(string $fileName): void
    {
        $html = self::parse($fileName)->toString(Format::HtmlTranscript);

        $this->assertSame($html, (new HtmlTranscriptParser())->parse($html, new ReadOptions())->toString(Format::HtmlTranscript));
    }


    public function testSpecExampleShapeKeepsItsCuesThroughTheFormatter(): void
    {
        $subtitle = self::parse("spec_example_shape.html");
        $again    = (new HtmlTranscriptParser())->parse($subtitle->toString(Format::HtmlTranscript), new ReadOptions());

        $this->assertEquals($subtitle->getCues(), $again->getCues());
    }


    public function testParagraphsOfOneSpeakerWithoutAGapJoin(): void
    {
        $html = self::parse("hhmmss_empty_paragraphs.html")->toString(Format::HtmlTranscript);

        $this->assertStringStartsWith(
            "<cite>Speaker 1:</cite>\n<time>0:00</time>\n" .
            "<p>Good morning and welcome to the station news. The new timetable starts on Monday.</p>\n<cite>Speaker 2:</cite>\n",
            $html
        );
        $this->assertStringEndsWith("<cite>Speaker 1:</cite>\n<time>1:00:03</time>\n<p>See you next week.</p>\n", $html);
    }
}
