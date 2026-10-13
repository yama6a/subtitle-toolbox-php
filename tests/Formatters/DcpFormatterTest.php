<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
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
