<?php

declare(strict_types=1);

namespace SubtitleToolbox\Chapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\OgmChaptersParser;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\YouTubeChaptersParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationViolation;

class ChapterFormatsTest extends TestCase
{
    public function testPodcastParserSortsChaptersAndDerivesTheEndTimes(): void
    {
        $json = '{"version": "1.2.0", "chapters": [{"startTime": 260, "title": "Progress <report>"}, ' .
                '{"startTime": 0, "title": "Intro", "endTime": 150.5}, {"startTime": 168}]}';

        $this->assertSame([[0.0, 150.5, "Intro"], [168.0, 260.0, ""], [260.0, 4980.0, "Progress &lt;report&gt;"]],
                          $this->describe((new PodcastChaptersParser())->parse($json, new ReadOptions(formats: [new ChapterReadOptions(mediaDuration: 4980)]))));
        $this->assertSame([260.0, 260.0, "Progress &lt;report&gt;"], $this->describe((new PodcastChaptersParser())->parse($json, new ReadOptions()))[2]);
    }


    public function testPodcastFormatterWritesCuesFromAnotherFormat(): void
    {
        $subtitle = $this->chapters([[0, 168, "Intro"], [168, 260.25, "<i>Hearing</i> aids"], [260.25, 300, "Progress report"]])
            ->setMetadata(Subtitle::METADATA_TITLE, "Episode 7");

        $this->assertSame(<<<'JSON'
            {
                "version": "1.2.0",
                "title": "Episode 7",
                "chapters": [
                    {
                        "startTime": 0,
                        "title": "Intro"
                    },
                    {
                        "startTime": 168,
                        "title": "Hearing aids"
                    },
                    {
                        "startTime": 260.25,
                        "endTime": 300,
                        "title": "Progress report"
                    }
                ]
            }

            JSON, $subtitle->toString(Format::PodcastChapters));
    }


    public static function badPodcastJson(): array
    {
        return [
            "not JSON"          => ["{", "The content is not valid JSON"],
            "no chapters"       => ['{"version": "1.2.0"}', "The JSON has no \"chapters\" list."],
            "chapters object"   => ['{"chapters": {"a": 1}}', "The JSON has no \"chapters\" list."],
            "start as a string" => ['{"chapters": [{"startTime": 0}, {"startTime": "1:00"}]}', "The field chapters[1].startTime must be a number."],
            "100000 hours"      => ['{"chapters": [{"startTime": 0}, {"startTime": 360000000}]}', "The field chapters[1].startTime is not below 100000 hours."],
            "end at 1e20"       => ['{"chapters": [{"startTime": 0, "endTime": 1e20}]}', "The field chapters[0].endTime is not below 100000 hours."],
        ];
    }


    #[DataProvider("badPodcastJson")]
    public function testPodcastParserThrowsForBadJson(string $json, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new PodcastChaptersParser())->parse($json, new ReadOptions());
    }


    public function testFfMetadataParserReadsTimesInTheOrderOfFfmpeg(): void
    {
        $content = ";FFMETADATA1\ntitle=Meetup\n\n[CHAPTER]\nSTART=0\nEND=60000000000\ntitle=Doors open\n" .
                   "[CHAPTER]\nTIMEBASE=1/1000\n;a comment\nEND=90000\ntitle=No start\n" .
                   "[CHAPTER]\nTIMEBASE=1/10\nSTART=1200\nno tag on this line\ntitle=No end\n" .
                   "[CHAPTER]\nTIMEBASE=1/1000\nSTART=130000\nEND=125000\ntitle=Ends early\n";
        $subtitle = (new FfMetadataChaptersParser())->parse($content, new ReadOptions(formats: [new ChapterReadOptions(mediaDuration: 200)]));

        $this->assertSame([[0.0, 60.0, "Doors open"], [60.0, 90.0, "No start"], [120.0, 130.0, "No end"], [130.0, 125.0, "Ends early"]],
                          $this->describe($subtitle));
        $this->assertSame(["timeBase" => "1/1000000000", "tags" => []], $subtitle->getCues()[0]->findFormatData("ffmeta-chapters"));
        $this->assertSame("Meetup", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
    }


    public function testFfMetadataEscapesRoundTrip(): void
    {
        $subtitle = $this->chapters([[0, 61.5, "a=b; c#d \\ e"], [61.5, 70, "first line\nsecond line"]]);
        $subtitle->getCues()[0]->setFormatData("ffmeta-chapters", ["timeBase" => "1/90000", "tags" => ["lang=x" => "en"]]);
        $output = $subtitle->toString(Format::FfMetadataChapters);

        $this->assertSame(";FFMETADATA1\n" .
                          "[CHAPTER]\nTIMEBASE=1/90000\nSTART=0\nEND=5535000\ntitle=a\\=b\; c\\#d \\\\ e\nlang\\=x=en\n" .
                          "[CHAPTER]\nTIMEBASE=1/1000\nSTART=61500\nEND=70000\ntitle=first line\\\nsecond line\n", $output);
        $parsed = (new FfMetadataChaptersParser())->parse($output, new ReadOptions());
        $this->assertSame($this->describe($subtitle), $this->describe($parsed));
        $this->assertSame($subtitle->getCues()[0]->getAllFormatData(), $parsed->getCues()[0]->getAllFormatData());
    }


    public function testFfMetadataFormatterWritesTheCurrentMetadata(): void
    {
        $subtitle = (new FfMetadataChaptersParser())->parse(";FFMETADATA1\nmajor_brand=isom\ntitle=Old\nartist=Jane Doe\n", new ReadOptions());
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, "New")->setMetadata(Subtitle::METADATA_ARTIST, null)
                 ->setMetadata(Subtitle::METADATA_ALBUM, "Notes");

        $this->assertSame(";FFMETADATA1\nmajor_brand=isom\ntitle=New\nalbum=Notes\n", $subtitle->toString(Format::FfMetadataChapters));
    }


    public static function badFfMetadata(): array
    {
        return [
            "no header"      => ["[CHAPTER]\nSTART=0\n", "The content does not start with the ;FFMETADATA header. (line 1)"],
            "zero time base" => [";FFMETADATA1\n\n[CHAPTER]\nTIMEBASE=1/0\nSTART=0\n", "The chapter time base 1/0 is not valid. (line 4)"],
            "100000 hours"   => [";FFMETADATA1\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=360000000000\n", "The time \"START=360000000000\" is not below 100000 hours. (line 4)"],
            "end of 1e20 ns" => [";FFMETADATA1\n[CHAPTER]\nSTART=0\nEND=100000000000000000000\n", "The time \"END=100000000000000000000\" is not below 100000 hours. (line 4)"],
        ];
    }


    #[DataProvider("badFfMetadata")]
    public function testFfMetadataParserThrowsForBadContent(string $content, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new FfMetadataChaptersParser())->parse($content, new ReadOptions());
    }


    public function testOgmParserAcceptsTheTimesOfMkvmerge(): void
    {
        $content = "  CHAPTER02 = 00:01:02,5\nCHAPTER02NAME= Second\n\nCHAPTER01=0:00:00.123456789\nCHAPTER01NAME=First\nCHAPTER03=01:00:00.000\n";

        $this->assertSame([[0.123, 62.5, "First"], [62.5, 62.5, "Second"]], $this->describe((new OgmChaptersParser())->parse($content, new ReadOptions())));
    }


    public static function badOgm(): array
    {
        return [
            "name first"      => ["CHAPTER01NAME=Intro\n", "The line \"CHAPTER01NAME=Intro\" is not a CHAPTERxx= line. (line 1)"],
            "no fraction"     => ["CHAPTER01=00:00:00\n", "The line \"CHAPTER01=00:00:00\" is not a CHAPTERxx= line. (line 1)"],
            "minute 60"       => ["CHAPTER01=00:60:00.000\n", "The line \"CHAPTER01=00:60:00.000\" has a minute or second above 59. (line 1)"],
            "100000 hours"    => ["CHAPTER01=100000:00:00.000\n", "The time \"CHAPTER01=100000:00:00.000\" is not below 100000 hours. (line 1)"],
            "two times"       => ["CHAPTER01=00:00:00.000\n\nCHAPTER02=00:01:00.000\n", "The line \"CHAPTER02=00:01:00.000\" is not a CHAPTERxxNAME= line. (line 3)"],
        ];
    }


    #[DataProvider("badOgm")]
    public function testOgmParserThrowsForBadLines(string $content, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new OgmChaptersParser())->parse($content, new ReadOptions());
    }


    public function testOgmFormatterNumbersWithTwoDigitsAtLeast(): void
    {
        $subtitle = $this->chapters(array_map(fn (int $i): array => [$i * 60, $i * 60 + 60, "<b>Part</b> &amp; $i"], range(0, 99)));
        $lines    = explode("\n", $subtitle->toString(Format::OgmChapters));

        $this->assertSame(["CHAPTER01=00:00:00.000", "CHAPTER01NAME=Part & 0"], array_slice($lines, 0, 2));
        $this->assertSame(["CHAPTER100=01:39:00.000", "CHAPTER100NAME=Part & 99", ""], array_slice($lines, 198));
    }


    public function testYouTubeParserReadsOnlyTheLinesThatStartOrEndWithATime(): void
    {
        $description = "Recorded live.\n0:00 Intro\n(2:48) Hearing aids\n[4:20] | Progress report\n" .
                       "Namespace \u{2014} 6:50\nThe big players: 1:31:50\n12:30pm meeting\n0:75 Bad seconds\n1:60:00 Bad minutes\n" .
                       "75:00 Long video\n1:2:03 Short minutes\n2:00:00\n99999999999999999999:00:00 Too late\n6000000:00 Too late\nSee https://example.com/a:b\n";

        $this->assertSame([
            [0.0, 168.0, "Intro"],
            [168.0, 260.0, "Hearing aids"],
            [260.0, 410.0, "Progress report"],
            [410.0, 3723.0, "Namespace"],
            [3723.0, 4500.0, "Short minutes"],
            [4500.0, 5510.0, "Long video"],
            [5510.0, 7200.0, "The big players"],
            [7200.0, 7200.0, ""],
        ], $this->describe((new YouTubeChaptersParser())->parse($description, new ReadOptions())));
    }


    public function testYouTubeFormatterWritesHoursOnlyFromOneHour(): void
    {
        $subtitle = $this->chapters([[0.9, 168, "Intro"], [3599.99, 3600, "<i>Last</i> minute"], [3600, 3700, ""], [36000, 36001, "Ten hours"]]);

        $this->assertSame("0:00 Intro\n59:59 Last minute\n1:00:00\n10:00:00 Ten hours\n", $subtitle->toString(Format::YouTubeChapters));
    }


    public function testCheckAcceptsAValidList(): void
    {
        $this->assertSame([], YouTubeChapters::check($this->chapters([[0, 10, "Intro"], [10, 20, "Middle"], [20, 20, "End"]])));
    }


    public function testCheckListsEveryBrokenRule(): void
    {
        $chapters = $this->chapters([[5, 9.5, "Intro"], [9.5, 30, "Middle"]]);

        $this->assertEquals([
            new ValidationViolation(0, ValidationRule::FirstChapterAtZero, 5.0, 0),
            new ValidationViolation(null, ValidationRule::MinChapters, 2, 3),
            new ValidationViolation(0, ValidationRule::MinDuration, 4.5, 10),
        ], YouTubeChapters::check($chapters));
    }


    public function testCheckTestsTheLastChapterOnlyWhenItsEndIsKnown(): void
    {
        $chapters = $this->chapters([[0.5, 10.5, "Intro"], [10.5, 20.5, "Middle"], [20.5, 25, "End"]]);

        $this->assertEquals([new ValidationViolation(2, ValidationRule::MinDuration, 4.5, 10)],
                            YouTubeChapters::check($chapters));
        $this->assertEquals([new ValidationViolation(null, ValidationRule::MinChapters, 0, 3)],
                            YouTubeChapters::check(new Subtitle()));
    }


    private function chapters(array $chapters): Subtitle
    {
        $subtitle = new Subtitle();
        $cues     = [];
        foreach ($chapters as [$start, $end, $text]) {
            $cues[] = new SubtitleCue($start, $end, $text);
        }
        $subtitle->addCues($cues);

        return $subtitle;
    }


    private function describe(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues());
    }
}
