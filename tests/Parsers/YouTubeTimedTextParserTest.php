<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class YouTubeTimedTextParserTest extends TestCase
{
    private const JSON3 = '{"events": [{"tStartMs": 1200, "dDurationMs": 2300, "segs": [{"utf8": "Hello"}, {"utf8": " world", "tOffsetMs": 400}]}]}';

    private const SRV3 = '<timedtext format="3"><body><p t="1200" d="2300">Hello<s t="400"> world</s></p></body></timedtext>';

    private const SRV1 = '<transcript><text start="1.2" dur="2.3">Hello world</text></transcript>';


    public static function shapes(): array
    {
        return [
            "json3" => [self::JSON3, "<00:00:01.200>Hello <00:00:01.600>world"],
            "srv3"  => [self::SRV3, "<00:00:01.200>Hello <00:00:01.600>world"],
            "srv1"  => [self::SRV1, "Hello world"],
        ];
    }


    #[DataProvider("shapes")]
    public function testEveryShapeGivesTheSameCue(string $content): void
    {
        $cues = Subtitle::fromStringAutoDetectFormat($content)->getCues();

        $this->assertCount(1, $cues);
        $this->assertSame([1.2, 3.5, "Hello world"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
    }


    #[DataProvider("shapes")]
    public function testWordTimestampsOption(string $content, string $expected): void
    {
        $parser = new YouTubeTimedTextParser();

        $this->assertSame($expected, $parser->parse($content, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]))->getCues()[0]->getText());
    }


    public function testSkipsAppendEventsAndEndsRollingCuesAtTheNextCueOfTheirWindow(): void
    {
        $json = '{"events": [
            {"tStartMs": 0, "dDurationMs": 9000, "id": 1, "wpWinPosId": 1},
            {"tStartMs": 100, "dDurationMs": 4000, "wWinId": 1, "segs": [{"utf8": "one"}]},
            {"tStartMs": 2000, "dDurationMs": 2100, "wWinId": 1, "aAppend": 1, "segs": [{"utf8": "\n"}]},
            {"tStartMs": 2010, "dDurationMs": 4000, "wWinId": 1, "segs": [{"utf8": "two"}]},
            {"tStartMs": 3000, "dDurationMs": 1000, "segs": [{"utf8": "sign"}]}
        ]}';

        $cues = (new YouTubeTimedTextParser())->parse($json, new ReadOptions())->getCues();

        $this->assertSame(
            [[0.1, 2.01, "one"], [2.01, 6.01, "two"], [3.0, 4.0, "sign"]],
            array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $cues)
        );
    }


    public function testKeepsOverlappingCuesOutsideAWindow(): void
    {
        $cues = (new YouTubeTimedTextParser())->parse(
            '<timedtext format="3"><body><p t="0" d="3000">top</p><p t="1000" d="3000">bottom</p></body></timedtext>'
        , new ReadOptions())->getCues();

        $this->assertSame([3.0, 4.0], [$cues[0]->getEnd(), $cues[1]->getEnd()]);
    }


    public function testDecodesHtmlEntitiesInTheXmlFormats(): void
    {
        $subtitle = (new YouTubeTimedTextParser())->parse(
            '<transcript><text start="0" dur="1">It&amp;#39;s &amp;quot;fish &amp;amp; chips&amp;quot; &lt;3</text></transcript>'
        , new ReadOptions());

        $this->assertSame("It's \"fish &amp; chips\" &lt;3", $subtitle->getCues()[0]->getText());
    }


    public function testDoesNotDecodeEntitiesInJson3(): void
    {
        $subtitle = (new YouTubeTimedTextParser())->parse('{"events": [{"tStartMs": 0, "dDurationMs": 1, "segs": [{"utf8": "&amp; <b>"}]}]}', new ReadOptions());

        $this->assertSame("&amp;amp; &lt;b&gt;", $subtitle->getCues()[0]->getText());
    }


    public function testSrv3WindowPositionsAndPens(): void
    {
        $srv3 = '<?xml version="1.0" encoding="utf-8" ?><timedtext format="3"><head>' .
                '<pen id="1" fc="#FF0000" b="1" et="3"/><pen id="2" i="1" u="1"/>' .
                '<wp id="0"/><wp id="1" ap="0" ah="0" av="0"/><wp id="2" ap="5" ah="100" av="50"/><wp id="3" ah="50" av="50"/>' .
                '</head><body>' .
                '<p t="0" d="1000" wp="1" p="1">red</p>' .
                '<p t="1000" d="1000" wp="2"><s p="2">styled</s> plain</p>' .
                '<p t="2000" d="1000" wp="3">no anchor</p>' .
                '</body></timedtext>';

        $subtitle = (new YouTubeTimedTextParser())->parse($srv3, new ReadOptions());
        $cues     = $subtitle->getCues();

        $this->assertSame(
            [[Alignment::TopLeft, '<font color="#ff0000"><b>red</b></font>'], [Alignment::MiddleRight, "<i><u>styled</u></i> plain"], [Alignment::BottomCenter, "no anchor"]],
            array_map(fn ($cue) => [$cue->getAlignment(), $cue->getText()], $cues)
        );
        $this->assertSame(["wp" => "1", "p" => "1"], $cues[0]->findFormatData("youtube"));
        $this->assertSame(["wp" => "2", "segments" => [["p" => "2"], []]], $cues[1]->findFormatData("youtube"));
        $this->assertSame(["id" => "1", "fc" => "#FF0000", "b" => "1", "et" => "3"], $subtitle->findFormatData("youtube")["pen"][0]);
    }


    public function testJson3WindowPositionsAndPens(): void
    {
        $json = '{"wireMagic": "pb3", "pens": [{}, {"bAttr": 1, "fcForeColor": 65280, "foForeAlpha": 254}],
                  "wpWinPositions": [{}, {"apPoint": 8, "ahHorPos": 100, "avVerPos": 100}],
                  "events": [{"tStartMs": 0, "dDurationMs": 1000, "wpWinPosId": 1, "segs": [{"utf8": "green", "pPenId": 1}, {"utf8": " text"}]}]}';

        $subtitle = (new YouTubeTimedTextParser())->parse($json, new ReadOptions());
        $cue      = $subtitle->getCues()[0];

        $this->assertSame(Alignment::BottomRight, $cue->getAlignment());
        $this->assertSame('<font color="#00ff00"><b>green</b></font> text', $cue->getText());
        $this->assertSame(["wpWinPosId" => 1, "segments" => [["pPenId" => 1], []]], $cue->findFormatData("youtube"));
        $this->assertSame("pb3", $subtitle->findFormatData("youtube")["wireMagic"]);
        $this->assertSame("json3", $subtitle->findFormatData("youtube")["format"]);
    }


    public function testWordTimestampsKeepLineBreaksAndSkipCuesWithoutWordTimes(): void
    {
        $json   = '{"events": [{"tStartMs": 0, "dDurationMs": 3000, "segs": [{"utf8": "one"}, {"utf8": "\ntwo", "tOffsetMs": 1500}]},
                               {"tStartMs": 3000, "dDurationMs": 1000, "segs": [{"utf8": "[Music]"}]}]}';

        $cues = (new YouTubeTimedTextParser())->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]))->getCues();

        $this->assertSame(["<00:00:00.000>one", "<00:00:01.500>two"], $cues[0]->getLines());
        $this->assertSame("[Music]", $cues[1]->getText());
    }


    public function testReadsSrv2(): void
    {
        $subtitle = (new YouTubeTimedTextParser())->parse('<?xml version="1.0"?><timedtext><text t="1200" d="2300">Hello world</text></timedtext>', new ReadOptions());

        $this->assertSame([1.2, 3.5, "Hello world"], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd(), $subtitle->getCues()[0]->getText()]);
        $this->assertSame(["format" => "srv2"], $subtitle->findFormatData("youtube"));
    }


    public static function filesWithoutDuration(): array
    {
        return [
            "srv1" => ["missing-dur.en.srv1"],
            "srv2" => ["missing-d.en.srv2"],
            "srv3" => ["missing-d.en.srv3"],
        ];
    }


    #[DataProvider("filesWithoutDuration")]
    public function testTextWithoutDurationEndsAtTheNextStartOrAfterLastCueDuration(string $file): void
    {
        $content  = file_get_contents(__DIR__ . "/../files/youtube/real/" . $file);
        $describe = fn (Subtitle $subtitle): array => array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues());

        $this->assertSame([[1.0, 3.0], [4.0, 6.5], [6.5, 6.5], [8.0, 13.0]], $describe((new YouTubeTimedTextParser())->parse($content, new ReadOptions())));
        $this->assertSame([[1.0, 3.0], [4.0, 6.5], [6.5, 6.5], [8.0, 10.5]], $describe((new YouTubeTimedTextParser())->parse($content, new ReadOptions(lastCueDuration: 2.5))));
    }


    public function testStrictModeThrowsWithTheLineNumber(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage('The <p> element has no valid "t" attribute. (line 3)');

        (new YouTubeTimedTextParser())->parse("<timedtext format=\"3\"><body>\n<p t=\"0\" d=\"1\">a</p>\n<p t=\"soon\">b</p>\n</body></timedtext>", new ReadOptions());
    }


    public function testLenientModeSkipsBrokenEvents(): void
    {
        $subtitle = (new YouTubeTimedTextParser())->parse('{"events": [{"tStartMs": "0", "segs": [{"utf8": "a"}]}, {"tStartMs": 1000, "dDurationMs": 1000, "segs": [{"utf8": "b"}]}]}', new ReadOptions(lenient: true));

        $this->assertSame("b", $subtitle->getCues()[0]->getText());
        $this->assertCount(1, $subtitle->getParseWarnings());
        $this->assertStringContainsString("events[0].tStartMs", $subtitle->getParseWarnings()[0]->message);
    }
}
