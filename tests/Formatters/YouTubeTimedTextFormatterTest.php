<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

class YouTubeTimedTextFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("youtube") as $file) {
            $cases["$file default"] = [$file, new ReadOptions()];
            $cases["$file words"]   = [$file, self::withWords()];
        }

        return $cases;
    }


    private static function withWords(): ReadOptions
    {
        return new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]);
    }


    private static function read(string $file, ?ReadOptions $options = null): Subtitle
    {
        return TestFiles::parse("youtube/real/$file", Format::YouTubeTimedText, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): string
    {
        return (new YouTubeTimedTextFormatter())->format($subtitle);
    }


    /**
     * The XML variants give the bytes of the source. blank-lines.en.srv3 has white space before the XML declaration,
     * which the parser skips. json3 gives the same JSON with the same key order, without the indents of YouTube.
     */
    #[DataProvider("realFiles")]
    public function testARoundTripGivesTheSource(string $file, ReadOptions $options): void
    {
        $source = file_get_contents(TestFiles::DIR . "youtube/real/$file");
        $output = self::write(self::read($file, $options));
        if (str_ends_with($file, ".json3")) {
            $this->assertSame(json_encode(json_decode($source)), json_encode(json_decode($output)));

            return;
        }

        $this->assertSame(ltrim($source), $output);
    }


    public function testAnEditedTextLosesItsEscapedEntitiesAndTheOthersKeepThem(): void
    {
        $subtitle = self::read("transcript.en.srv1");
        $subtitle->getCues()[0]->setLines("hello all and welcome back");

        $this->assertStringStartsWith(
            '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="0.16" dur="4.4">hello all and welcome back</text>' .
            '<text start="2.96" dur="4.48">today we&amp;#39;re planting tomatoes</text>',
            self::write($subtitle)
        );
    }


    public function testAMovedCueKeepsItsDurationAndAChangedEndGetsANewOne(): void
    {
        $subtitle = self::read("missing-d.en.srv2");
        $subtitle->getCues()[0]->setStart(1.5)->setEnd(3.5);
        $subtitle->getCues()[1]->setEnd(6.0);

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<timedtext>\n<text t=\"1500\" d=\"2000\">the market opens at eight</text>\n" .
            "<text t=\"4000\" d=\"2000\">bring a basket</text>\n<text t=\"6500\" d=\"0\">and some coins</text>\n" .
            "<text t=\"8000\">we close at noon</text>\n</timedtext>\n",
            self::write($subtitle)
        );
    }


    public function testCuesFromAnotherFormatGiveTimesFromTheCues(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1.5, 4, ["<i>Fish &amp; chips</i>", "today"]), new SubtitleCue(5, 7.25, "Done")]);
        $subtitle->setFormatData("youtube", ["format" => "srv1"]);

        $this->assertSame(
            '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="1.5" dur="2.5">Fish &amp; chips' . "\n" .
            'today</text><text start="5" dur="2.25">Done</text></transcript>',
            self::write($subtitle)
        );
    }


    public function testANewStyleGetsANewPenAndAnEqualStyleSharesThePen(): void
    {
        $subtitle = self::read("styled.en.srv3");
        $cues     = $subtitle->getCues();
        $cues[1]->setLines("<i>The ferry</i> leaves at <u>noon</u>.");
        $cues[2]->setLines(str_replace("four euros", "five euros", $cues[2]->getLines()));

        $srv3 = self::write($subtitle);

        $this->assertStringContainsString("<pen id=\"4\" i=\"1\"/>\n<pen id=\"5\" u=\"1\"/>\n", $srv3);
        $this->assertStringContainsString('<p t="2500" d="3000" wp="2" ws="1"><s p="4">The ferry</s> leaves at <s p="5">noon</s>.</p>', $srv3);
        $this->assertStringContainsString('<p t="5500" d="3200" wp="2" ws="1"><s p="1">Tickets cost </s><s p="2">five euros</s><s p="1">.</s></p>', $srv3);
        $this->assertStringContainsString('<s p="3">Pier 3 &amp;#39;North&amp;#39;</s>', $srv3);
    }


    public function testANewAlignmentGetsAWindowPosition(): void
    {
        $subtitle = self::read("styled.en.srv3");
        $subtitle->getCues()[4]->setAlignment(Alignment::TopRight);
        $subtitle->getCues()[1]->setAlignment(Alignment::TopLeft);

        $srv3 = self::write($subtitle);

        $this->assertStringContainsString("<wp id=\"3\" ap=\"0\" ah=\"0\" av=\"0\"/>\n<wp id=\"4\" ap=\"2\" ah=\"100\" av=\"0\"/>\n", $srv3);
        $this->assertStringContainsString('<p t="2500" d="3000" p="1" wp="3" ws="1">', $srv3);
        $this->assertStringContainsString("<p t=\"11500\" d=\"3000\" wp=\"4\">Please keep\nyour ticket.</p>", $srv3);
    }


    public function testAnEditedWordKeepsTheOtherWordsAndTheAppendEvents(): void
    {
        $subtitle = self::read("auto.en.srv3");
        $cue      = $subtitle->getCues()[1];
        $cue->setLines(str_replace("plant", "sow", $cue->getLines()));
        $subtitle->shift(1.0);

        $srv3 = self::write($subtitle);

        $this->assertStringContainsString('<p t="3950" d="1610" w="1" a="1">' . "\n</p>\n" .
            '<p t="3960" d="4480" w="1"><s ac="0">today</s><s t="320" ac="0"> we</s><s t="480"> sow</s><s t="800" ac="0"> tomatoes</s></p>', $srv3);
    }


    public function testAWordChangedWithoutWordTimestampsKeepsTheOffsetOfItsSourceSegment(): void
    {
        $subtitle = self::read("auto.en.srv3", new ReadOptions());
        $subtitle->getCues()[1]->setLines("today we sow tomatoes");

        $this->assertStringContainsString(
            '<p t="2960" d="4480" w="1"><s ac="0">today</s><s t="320" ac="0"> we</s><s t="480"> sow</s><s t="800" ac="0"> tomatoes</s></p>',
            self::write($subtitle)
        );
    }


    public function testCuesFromAnotherFormatGiveSrv3WithPensAndWordTimes(): void
    {
        $subtitle = (new Subtitle())->addCues([
            new SubtitleCue(1, 2, "<i>Hi</i> <00:00:01.500>there"),
            (new SubtitleCue(3, 4, ['<font color="red">Red</font>', "two"]))->setAlignment(Alignment::TopLeft),
        ]);

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?><timedtext format=\"3\">\n<head>\n<pen id=\"1\" i=\"1\"/>\n<pen id=\"2\" fc=\"#FF0000\"/>\n" .
            "<wp id=\"1\" ap=\"0\" ah=\"0\" av=\"0\"/>\n</head>\n<body>\n<p t=\"1000\" d=\"1000\"><s p=\"1\">Hi</s><s t=\"500\"> there</s></p>\n" .
            "<p t=\"3000\" d=\"1000\" wp=\"1\"><s p=\"2\">Red</s>\ntwo</p>\n</body>\n</timedtext>\n",
            self::write($subtitle)
        );
    }


    public function testAJson3EditGetsPensAndAWindowPositionAsInSrv3(): void
    {
        $subtitle = self::read("manual.en.json3");
        $subtitle->getCues()[0]->setLines('Welcome to the <font color="#ff0000"><b>bakery</b></font> tour.')->setAlignment(Alignment::TopCenter);

        $json = json_decode(self::write($subtitle), true);

        $this->assertSame([[], ["bAttr" => 1, "fcForeColor" => 0xFF0000]], $json["pens"]);
        $this->assertSame([[], ["apPoint" => 1, "ahHorPos" => 50, "avVerPos" => 0]], $json["wpWinPositions"]);
        $this->assertSame(
            ["tStartMs" => 1200, "dDurationMs" => 2300, "wpWinPosId" => 1, "segs" => [["utf8" => "Welcome to the "], ["utf8" => "bakery", "pPenId" => 1], ["utf8" => " tour."]]],
            $json["events"][0]
        );
    }


    public function testCuesFromAnotherFormatGiveJson3WhenTheFormatDataSaysSo(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 2, "<i>Hi</i> <00:00:01.500>there")]);
        $subtitle->setFormatData("youtube", ["format" => "json3"]);

        $this->assertSame(
            '{"wireMagic":"pb3","pens":[{},{"iAttr":1}],"wsWinStyles":[{}],"wpWinPositions":[{}],' .
            '"events":[{"tStartMs":1000,"dDurationMs":1000,"segs":[{"utf8":"Hi","pPenId":1},{"utf8":" there","tOffsetMs":500}]}]}',
            self::write($subtitle)
        );
    }
}
