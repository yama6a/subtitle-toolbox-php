<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\CuePosition;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class DcpFormatterTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/dcp/real/";


    public function testWritesSmpte2014At24FpsForANewFile(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Harbour")
            ->setFormatData("dcp", ["header" => ["IssueDate" => "2026-10-12T00:00:00"]])
            ->addCue(new SubtitleCue(5, 7.125, ["The ferry leaves at nine,", "from the <i>north</i> pier."]))
            ->addCue((new SubtitleCue(8.5, 10, "HARBOUR"))->setAlignment(Alignment::TopLeft));

        $output = $subtitle->toString(Format::Dcp);

        $this->assertStringStartsWith(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            "<SubtitleReel xmlns=\"http://www.smpte-ra.org/schemas/428-7/2014/DCST\" xmlns:xs=\"http://www.w3.org/2001/XMLSchema\">\n",
            $output
        );
        $this->assertStringContainsString("  <ContentTitleText>Harbour</ContentTitleText>\n  <IssueDate>2026-10-12T00:00:00</IssueDate>\n", $output);
        $this->assertStringContainsString(
            "      <Subtitle SpotNumber=\"1\" TimeIn=\"00:00:05:00\" TimeOut=\"00:00:07:03\" FadeUpTime=\"00:00:00:00\" FadeDownTime=\"00:00:00:00\">\n" .
            "        <Text Valign=\"bottom\" Vposition=\"15\">The ferry leaves at nine,</Text>\n" .
            "        <Text Valign=\"bottom\" Vposition=\"8\">from the <Font Italic=\"yes\">north</Font> pier.</Text>\n" .
            "      </Subtitle>\n",
            $output
        );
        $this->assertStringContainsString("<Text Halign=\"left\" Valign=\"top\" Vposition=\"8\">HARBOUR</Text>", $output);
        $this->assertSame($output, $subtitle->toString(Format::Dcp));
    }


    public function testReadsBackWhatItWrites(): void
    {
        $subtitle = (new Subtitle())
            ->addCue((new SubtitleCue(1, 2, ["<b>Bold</b> and <u>under</u>", "<font color=\"#ffff00\">yellow</font>"]))->setAlignment(Alignment::MiddleRight));

        $read = Subtitle::fromString($subtitle->toString(Format::Dcp))->getCues()[0];

        $this->assertSame(["<b>Bold</b> and <u>under</u>", "<font color=\"#ffff00\">yellow</font>"], $read->getLines());
        $this->assertSame(Alignment::MiddleRight, $read->getAlignment());
    }


    public function testKeepsTheInteropDialectWithTicksAndTheFontAroundTheSubtitles(): void
    {
        $subtitle = Subtitle::load(self::FILES . "monal_interop_shape.xml");
        $subtitle->getCues()[0]->setEnd(52.6);

        $output = $subtitle->toString(Format::Dcp);

        $this->assertStringContainsString("<DCSubtitle Version=\"1.0\">\n  <SubtitleID>", $output);
        $this->assertStringContainsString("TimeIn=\"00:00:51:000\" TimeOut=\"00:00:52:150\"", $output);
        $this->assertStringContainsString("<Text HAlign=\"center\" HPosition=\"0.0\" VAlign=\"bottom\" VPosition=\"14\"><Font Italic=\"yes\">Every night at dusk</Font></Text>", $output);
    }


    public function testWritesPositionsFromTheAlignmentWhenItChanges(): void
    {
        $subtitle = Subtitle::load(self::FILES . "dcpomatic_interop_shape.xml");
        $subtitle->getCues()[0]->setAlignment(Alignment::MiddleCenter);

        $this->assertStringContainsString(
            "<Text VAlign=\"center\" VPosition=\"-3.5\">The ferry leaves at nine,</Text>\n      <Text VAlign=\"center\" VPosition=\"3.5\">from the north pier.</Text>",
            $subtitle->toString(Format::Dcp)
        );
    }


    public function testWritesThePositionOfACueFromAnotherFormat(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000 line:80%,end\nhi\n", Format::WebVtt);

        $this->assertStringContainsString("<Text Valign=\"bottom\" Vposition=\"20\">hi</Text>", $subtitle->toString(Format::Dcp));
    }


    /**
     * @param list<string> $lines
     * @param list<string> $texts
     */
    #[DataProvider('positions')]
    public function testPutsTheFirstLineAtThePositionAndTheNextLinesBelowIt(Alignment $alignment, CuePosition $position, array $lines, array $texts): void
    {
        $cue      = (new SubtitleCue(1, 2, $lines))->setAlignment($alignment)->setPosition($position);
        $subtitle = (new Subtitle())->addCue($cue);

        $output = $subtitle->toString(Format::Dcp);
        foreach ($texts as $text) {
            $this->assertStringContainsString($text, $output);
        }
        $read = Subtitle::fromString($output, Format::Dcp)->getCues()[0];
        $this->assertSame([$alignment, $position->x, $position->y], [$read->getAlignment(), $read->findPosition()?->x, $read->findPosition()?->y]);
    }


    /**
     * @return array<string, array{Alignment, CuePosition, list<string>, list<string>}>
     */
    public static function positions(): array
    {
        return [
            "top left"      => [Alignment::TopLeft, new CuePosition(20, 5), ["a", "b"], [
                "<Text Halign=\"left\" Hposition=\"20\" Valign=\"top\" Vposition=\"5\">a</Text>",
                "<Text Halign=\"left\" Hposition=\"20\" Valign=\"top\" Vposition=\"12\">b</Text>",
            ]],
            "bottom right"  => [Alignment::BottomRight, new CuePosition(85, 80), ["a", "b"], [
                "<Text Halign=\"right\" Hposition=\"15\" Valign=\"bottom\" Vposition=\"20\">a</Text>",
                "<Text Halign=\"right\" Hposition=\"15\" Valign=\"bottom\" Vposition=\"13\">b</Text>",
            ]],
            "middle center" => [Alignment::MiddleCenter, new CuePosition(60, 40), ["a"], [
                "<Text Hposition=\"10\" Valign=\"center\" Vposition=\"-10\">a</Text>",
            ]],
            "a fraction"    => [Alignment::BottomCenter, new CuePosition(50, 84.667), ["a"], [
                "<Text Valign=\"bottom\" Vposition=\"15.333\">a</Text>",
            ]],
        ];
    }


    public function testWritesInteropNamesForThePosition(): void
    {
        $subtitle = Subtitle::load(self::FILES . "dcpomatic_interop_shape.xml");
        $subtitle->getCues()[0]->setAlignment(Alignment::BottomLeft)->setPosition(new CuePosition(5, 80));

        $this->assertStringContainsString(
            "<Text HAlign=\"left\" HPosition=\"5\" VAlign=\"bottom\" VPosition=\"20\">The ferry leaves at nine,</Text>",
            $subtitle->toString(Format::Dcp)
        );
    }


    public function testIgnoresAPositionOutsideTheCellOfTheAlignment(): void
    {
        $subtitle = (new Subtitle())->addCue((new SubtitleCue(1, 2, "a"))->setPosition(new CuePosition(50, 10)));

        $this->assertStringContainsString("<Text Valign=\"bottom\" Vposition=\"8\">a</Text>", $subtitle->toString(Format::Dcp));
    }


    public function testStoredTextAttributesWinOverThePosition(): void
    {
        $subtitle = Subtitle::load(self::FILES . "monal_interop_shape.xml");
        $subtitle->getCues()[0]->setPosition(new CuePosition(50, 70));

        $this->assertStringContainsString("<Text HAlign=\"center\" HPosition=\"0.0\" VAlign=\"bottom\" VPosition=\"7\">", $subtitle->toString(Format::Dcp));
    }


    public function testAStoredSubtitleWithoutFadeTimesGetsTheDefaults(): void
    {
        $subtitle = Subtitle::fromString(
            "<DCSubtitle Version=\"1.0\"><Subtitle TimeIn=\"00:00:01:000\" TimeOut=\"00:00:02:000\">" .
            "<Text VAlign=\"bottom\" VPosition=\"8\">x</Text></Subtitle></DCSubtitle>",
            Format::Dcp
        );

        $this->assertStringContainsString(
            "<Subtitle TimeIn=\"00:00:01:000\" TimeOut=\"00:00:02:000\" FadeUpTime=\"20\" FadeDownTime=\"20\" SpotNumber=\"1\">",
            $subtitle->toString(Format::Dcp)
        );
    }


    public function testATimeJustBelowTheLimitReadsBack(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(359999999.99, 359999999.999, "x"));
        $output   = $subtitle->toString(Format::Dcp);

        $this->assertStringContainsString("TimeOut=\"99999:59:59:23\"", $output);
        $this->assertCount(1, Subtitle::fromString($output, Format::Dcp)->getCues());
    }


    public function testWhiteTextInsideAColoredFontKeepsItsColor(): void
    {
        $subtitle = Subtitle::fromString(
            "<DCSubtitle Version=\"1.0\"><Font Color=\"FFEBEBEB\"><Subtitle TimeIn=\"00:00:01:000\" TimeOut=\"00:00:02:000\">" .
            "<Text VAlign=\"bottom\" VPosition=\"8\"><Font Color=\"FFFFFFFF\">white</Font></Text></Subtitle></Font></DCSubtitle>",
            Format::Dcp
        );

        $this->assertStringContainsString("<Font Color=\"FFFFFFFF\">white</Font>", $subtitle->toString(Format::Dcp));
    }


    public function testWritesStylesAgainstTheFontAroundTheSubtitles(): void
    {
        $subtitle = Subtitle::fromString(
            "<DCSubtitle Version=\"1.0\"><Font Italic=\"yes\" Color=\"FFEBEBEB\"><Subtitle TimeIn=\"00:00:01:000\" TimeOut=\"00:00:02:000\">" .
            "<Text VAlign=\"bottom\" VPosition=\"8\">Sung</Text></Subtitle></Font></DCSubtitle>"
        );
        $subtitle->getCues()[0]->setLines(["<font color=\"#ebebeb\"><i>Sung</i> spoken</font>"]);

        $this->assertStringContainsString("<Text VAlign=\"bottom\" VPosition=\"8\">Sung<Font Italic=\"no\"> spoken</Font></Text>", $subtitle->toString(Format::Dcp));
    }
}
