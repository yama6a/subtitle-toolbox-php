<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;

class DcpParserTest extends TestCase
{
    use RealFiles;

    private const SMPTE = '<SubtitleReel xmlns="http://www.smpte-ra.org/schemas/428-7/2014/DCST"><EditRate>25 1</EditRate><TimeCodeRate>25</TimeCodeRate>' .
                          '<SubtitleList>%s</SubtitleList></SubtitleReel>';


    private static function realFilesDir(): string
    {
        return "dcp/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Dcp;
    }


    public static function realFiles(): array
    {
        return [
            "DCP-o-matic Interop" => [
                "dcpomatic_interop_shape.xml",
                3,
                [5.0, 7.5, "The ferry leaves at nine,\nfrom the north pier.", Alignment::BottomCenter],
                [11.0, 13.0, "HARBOUR MASTER", Alignment::TopLeft],
            ],
            "DCP-o-matic SMPTE" => [
                "dcpomatic_smpte_shape.xml",
                2,
                [5.0, 7.125, "Die Fähre fährt um neun,\nvom Nordkai.", Alignment::BottomCenter],
                [8.5, 10.75, "Karten <b>nur</b> am Kiosk.", Alignment::BottomCenter],
            ],
            "Monal Interop" => [
                "monal_interop_shape.xml",
                3,
                [51.0, 52.5, "Over here.", Alignment::BottomCenter],
                [94.0, 96.0, "<font color=\"#ffff00\">Keep the door shut.</font>", Alignment::BottomCenter],
            ],
            "Subtitle Edit SMPTE 2014" => [
                "subtitle_edit_smpte2014_shape.xml",
                2,
                [30.16, 33.84, "La lampe tourne encore.", Alignment::BottomCenter],
                [34.0, 36.48, "<i><u>Radio</u></i>", Alignment::TopCenter],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($file)->getCues();

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $this->describeCue($cues[0]));
        $this->assertSame($lastCue, $this->describeCue($cues[$cueCount - 1]));
    }


    /**
     * Adapted from testParsesDCSubtitles and testMultipleLines of mantas-done/subtitles, tests/formats/TtmlTest.php
     * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/formats/TtmlTest.php), MIT.
     * The last field of an Interop time counts ticks of 4 ms, as the CineCanvas specification says, not milliseconds.
     */
    public function testInteropTimesCountTicksOf4MsAndNestedFontsInherit(): void
    {
        $cues = $this->parseFile("monal_interop_shape.xml")->getCues();

        $this->assertSame([90.08, 93.124], [$cues[1]->getStart(), $cues[1]->getEnd()]);
        $this->assertSame(["<i>Every night at dusk</i>", "<i>the lamp still turns.</i>"], $cues[1]->getLines());
    }


    public function testReadsTheHeaderIntoMetadataAndFormatData(): void
    {
        $interop = $this->parseFile("dcpomatic_interop_shape.xml");
        $smpte   = $this->parseFile("subtitle_edit_smpte2014_shape.xml");

        $this->assertSame("Harbour Lights", $interop->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertNull($interop->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(["dialect" => "interop"], $interop->findFormatData("dcp"));
        $this->assertSame("fr", $smpte->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(
            ["dialect" => "smpte", "namespace" => "http://www.smpte-ra.org/schemas/428-7/2014/DCST", "timeCodeRate" => 25.0, "startTime" => "00:00:00:00"],
            $smpte->findFormatData("dcp")
        );
    }


    public function testKeepsTheTimesWhenTheStartTimeIsNotZero(): void
    {
        $content = str_replace("<SubtitleList>", "<StartTime>10:00:00:00</StartTime><SubtitleList>", self::SMPTE);
        $content = sprintf($content, '<Subtitle TimeIn="00:00:13:00" TimeOut="00:00:15:00"><Text Valign="bottom" Vposition="8">Wind</Text></Subtitle>');

        $subtitle = Subtitle::fromString($content);

        $this->assertEquals([new SubtitleCue(13, 15, "Wind")], $subtitle->getCues());
        $this->assertSame("10:00:00:00", $subtitle->findFormatData("dcp")["startTime"]);
    }


    public function testReadsTheEditRateWithoutATimeCodeRate(): void
    {
        $content = '<SubtitleReel xmlns="http://www.smpte-ra.org/schemas/428-7/2010/DCST"><EditRate>48 1</EditRate><SubtitleList>' .
                   '<Subtitle TimeIn="00:00:01:24" TimeOut="00:00:02:00"><Text Valign="center">Rain</Text></Subtitle></SubtitleList></SubtitleReel>';

        $cue = Subtitle::fromString($content)->getCues()[0];

        $this->assertSame([1.5, 2.0, Alignment::MiddleCenter], [$cue->getStart(), $cue->getEnd(), $cue->getAlignment()]);
    }


    public function testReadsInteropDecimalTimes(): void
    {
        $content = '<DCSubtitle Version="1.0"><Subtitle TimeIn="01:12:42.5" TimeOut="01:12:44.25"><Text VAlign="bottom" VPosition="8">Rain</Text></Subtitle></DCSubtitle>';

        $cue = Subtitle::fromString($content)->getCues()[0];

        $this->assertSame([4362.5, 4364.25], [$cue->getStart(), $cue->getEnd()]);
    }


    public function testStrictModeThrowsForAnImageSubtitle(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The subtitle at 00:00:01:00 is an image. The parser reads only text subtitles.");

        Subtitle::fromString(sprintf(self::SMPTE, '<Subtitle TimeIn="00:00:01:00" TimeOut="00:00:02:00"><Image Valign="bottom">urn:uuid:1</Image></Subtitle>'));
    }


    public function testLenientModeSkipsAnImageSubtitleAndABadTime(): void
    {
        $subtitles = '<Subtitle TimeIn="00:00:01:00" TimeOut="00:00:02:00"><Image Valign="bottom">urn:uuid:1</Image></Subtitle>' .
                     '<Subtitle TimeIn="00:00:03:25" TimeOut="00:00:04:00"><Text>Late</Text></Subtitle>' .
                     '<Subtitle TimeIn="00:00:05:00" TimeOut="00:00:06:00"><Text Valign="bottom" Vposition="8">Text</Text></Subtitle>';

        $subtitle = Subtitle::fromString(sprintf(self::SMPTE, $subtitles), Format::Dcp, new ReadOptions(lenient: true));

        $this->assertEquals([new SubtitleCue(5, 6, "Text")], $subtitle->getCues());
        $this->assertSame([ParseWarningAction::Skipped, ParseWarningAction::Skipped], array_map(fn ($warning) => $warning->action, $subtitle->getParseWarnings()));
        $this->assertStringContainsString("The time \"00:00:03:25\" is not a DCP time code at 25 units per second.", $subtitle->getParseWarnings()[1]->message);
    }


    public function testDetectsBothDialectsButNotTtml(): void
    {
        $this->assertSame(Format::Dcp, Format::detect("<?xml version=\"1.0\"?>\n<DCSubtitle Version=\"1.1\"></DCSubtitle>"));
        $this->assertSame(Format::Dcp, Format::detect("<dcst:SubtitleReel xmlns:dcst=\"http://www.smpte-ra.org/schemas/428-7/2007/DCST\"/>"));
        $this->assertSame(Format::Ttml, Format::detect(file_get_contents(__DIR__ . "/../files/ttml/real/bbc_ebu_tt_d.ttml")));
    }
}
