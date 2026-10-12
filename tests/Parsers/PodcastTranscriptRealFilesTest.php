<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\PodcastTranscriptWriteOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class PodcastTranscriptRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/podcast/real/";


    public static function realFiles(): array
    {
        return [
            "spec example shape with word segments" => [
                "spec_word_segments.json",
                4,
                [0.5, 3.0, "<v Marta>The bakery opens at seven."],
                [8.25, 11.5, "<v Marta>The rest of the year it closes."],
            ],
            "podcast-transcript-convert from SRT" => [
                "podcast_transcript_convert_from_srt.json",
                6,
                [0.0, 2.76, "<v Marta>This week we look at how to keep"],
                [25.8, 28.125, "<v Marta>No. The fridge makes it stale faster \u{2013} use a bread box."],
            ],
            "podcast-transcript-convert from HTML, without end times" => [
                "podcast_transcript_convert_from_html.json",
                4,
                [0.0, 7.0, "<v Speaker 1>The library opens a new reading room on Friday."],
                [19.0, 24.0, "<v Speaker 2>That sounds good. Thank you."],
            ],
        ];
    }


    private static function parse(string $fileName): Subtitle
    {
        return (new PodcastTranscriptParser())->parse(file_get_contents(self::DIR . $fileName), new ReadOptions());
    }



    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = TestSubtitles::describe(self::parse($fileName));

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $cues[0]);
        $this->assertSame($lastCue, $cues[count($cues) - 1]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileRoundTripsThroughTheFormatter(string $fileName): void
    {
        $subtitle = self::parse($fileName);
        $json     = $subtitle->toString(Format::PodcastTranscript, new WriteOptions(formats: [new PodcastTranscriptWriteOptions(prettyPrint: true)]));
        $again    = (new PodcastTranscriptParser())->parse($json, new ReadOptions());

        $this->assertSame(TestSubtitles::describe($subtitle), TestSubtitles::describe($again));
        $this->assertSame($subtitle->findFormatData("podcast-transcript"), $again->findFormatData("podcast-transcript"));
    }


    public function testWordSegmentsRoundTripWithTheirStartTimes(): void
    {
        $content  = file_get_contents(self::DIR . "spec_word_segments.json");
        $original = json_decode($content, true)["segments"];
        $json     = (new PodcastTranscriptParser())->parse($content, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]))
            ->toString(Format::PodcastTranscript, new WriteOptions(formats: [new PodcastTranscriptWriteOptions(wordSegments: true)]));
        $written  = json_decode($json, true)["segments"];

        $this->assertSame(
            array_map(fn (array $segment): array => [$segment["speaker"], (float)$segment["startTime"], trim($segment["body"])], $original),
            array_map(fn (array $segment): array => [$segment["speaker"], $segment["startTime"], $segment["body"]], $written)
        );
        $this->assertSame([1.0, 3.0], [$written[0]["endTime"], $written[4]["endTime"]]);
    }


    public function testHtmlConverterFileKeepsItsMetadata(): void
    {
        $subtitle = self::parse("podcast_transcript_convert_from_html.json");

        $this->assertSame(["version" => "1.0.0", "metadata" => ["title" => "Library news", "episode" => 4]], $subtitle->findFormatData("podcast-transcript"));
    }
}
