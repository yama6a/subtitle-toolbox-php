<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class YouTubeTimedTextRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/youtube/real/";


    public static function realFiles(): array
    {
        return [
            "json3 manual captions" => [
                "manual.en.json3",
                5,
                [1.2, 3.5, "Welcome to the bakery tour."],
                [11.5, 14.0, "That's all for today."],
            ],
            "json3 automatic captions" => [
                "auto.en.json3",
                4,
                [0.16, 2.96, "<00:00:00.160>hello <00:00:00.560>everyone <00:00:01.120>and <00:00:01.360>welcome <00:00:01.840>back"],
                [7.44, 10.44, "[Music]"],
            ],
            "srv3 automatic captions" => [
                "auto.en.srv3",
                4,
                [0.16, 2.96, "<00:00:00.160>hello <00:00:00.560>everyone <00:00:01.120>and <00:00:01.360>welcome <00:00:01.840>back"],
                [7.44, 10.44, "[Music]"],
            ],
            "srv3 styled captions" => [
                "styled.en.srv3",
                5,
                [0.0, 2.5, '<font color="#ffff00"><b>Chapter one</b></font>'],
                [11.5, 14.5, "Please keep\nyour ticket."],
            ],
            "srv3 after blank lines" => [
                "blank-lines.en.srv3",
                2,
                [1.0, 3.0, "The market opens at eight."],
                [3.5, 6.0, "Fresh bread is sold first."],
            ],
            "srv1 transcript" => [
                "transcript.en.srv1",
                5,
                [0.16, 4.56, "hello everyone and welcome back"],
                [10.5, 14.12, "water them twice a week"],
            ],
        ];
    }


    private static function parse(string $fileName): Subtitle
    {
        $parser = new YouTubeTimedTextParser();

        return $parser->parse(file_get_contents(self::DIR . $fileName), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = self::parse($fileName)->getCues();

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileKeepsItsCuesThroughWebVtt(string $fileName): void
    {
        $subtitle = self::parse($fileName);
        $vtt      = (new WebVttParser())->parse($subtitle->toString(Format::WebVtt), new ReadOptions());

        $asClasses = fn (array $lines): array => array_map(fn (string $line): string => MarkupColors::fontToWebVttColors($line)[0], $lines);
        $this->assertSame(
            array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd(), $asClasses($cue->getLines())], $subtitle->getCues()),
            array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $vtt->getCues())
        );
    }


    public function testJson3AndSrv3OfTheSameAutomaticCaptionsGiveTheSameCues(): void
    {
        $cues = fn (string $fileName): array => array_map(
            fn ($cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText(), $cue->getAlignment()],
            self::parse($fileName)->getCues()
        );

        $this->assertSame($cues("auto.en.json3"), $cues("auto.en.srv3"));
    }


    public function testStyledSrv3KeepsPositionsAndDecodesDoubleEscapedEntities(): void
    {
        $cues = self::parse("styled.en.srv3")->getCues();

        $this->assertSame([8, 2, 2, 7, 2], array_map(fn ($cue) => $cue->getAlignment()->value, $cues));
        $this->assertSame("<font color=\"#00ffff\"><i>Pier 3 'North'</i></font>", $cues[3]->getText());
    }


    public function testTranscriptDecodesDoubleEscapedEntities(): void
    {
        $cues = self::parse("transcript.en.srv1")->getCues();

        $this->assertSame("today we're planting tomatoes", $cues[1]->getText());
        $this->assertSame("in the garden bed &amp; the pots", $cues[2]->getText());
    }
}
