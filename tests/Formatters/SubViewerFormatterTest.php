<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class SubViewerFormatterTest extends TestCase
{
    public function testWritesSubViewer2ByDefault(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Bakery")
            ->addCue(new SubtitleCue(1.5, 4.004, ["<b>Fresh bread</b>", "every day"]))
            ->addCue(new SubtitleCue(3725.006, 3727.995, "Closed on Sunday"));

        $this->assertSame(
            "[INFORMATION]\n[TITLE]Bakery\n[AUTHOR]\n[SOURCE]\n[PRG]\n[FILEPATH]\n[DELAY]0\n[CD TRACK]0\n[COMMENT]\n" .
            "[END INFORMATION]\n[SUBTITLE]\n" .
            "00:00:01.50,00:00:04.00\nFresh bread[br]every day\n\n" .
            "01:02:05.01,01:02:08.00\nClosed on Sunday\n",
            $subtitle->toString(Format::SubViewer)
        );
    }


    public function testWritesSubViewer1WithTheVersionOption(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Jane Doe")
            ->addCue(new SubtitleCue(1.5, 4.4, ["Fresh bread", "every day"]));

        $this->assertSame(
            "[TITLE]\n[AUTHOR]\nJane Doe\n[SOURCE]\n[PRG]\n[FILEPATH]\n[DELAY]\n0\n[CD TRACK]\n0\n[BEGIN]\n" .
            "******** START SCRIPT ********\n" .
            "[00:00:02]\nFresh bread|every day\n[00:00:04]\n\n" .
            "[end]\n******** END SCRIPT ********\n",
            $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V1)]))
        );
    }


    public function testStripsMarkupDecodesEntitiesAndSkipsCuesWithoutText(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Tea &amp; cake &lt;3</i>"]))
            ->addCue(new SubtitleCue(3, 4, ["<i> </i>"]));

        $this->assertStringEndsWith(
            "[SUBTITLE]\n00:00:01.00,00:00:02.00\nTea & cake <3\n",
            $subtitle->toString(Format::SubViewer)
        );
    }


    public function testSubViewer1WritesDelayZeroBecauseTheParserAppliedIt(): void
    {
        $subtitle = Subtitle::fromString("[DELAY]\n3\n******** START SCRIPT ********\n[00:00:01]\nHello\n[00:00:02]\n", Format::SubViewer);

        $this->assertStringStartsWith(
            "[TITLE]\n[AUTHOR]\n[DELAY]\n0\n******** START SCRIPT ********\n[00:00:04]\nHello\n[00:00:05]\n",
            $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V1)]))
        );
    }


    public function testWritesTheStoredSubViewer1Version(): void
    {
        $subtitle = Subtitle::fromString("[TITLE]\nBakery\n******** START SCRIPT ********\n[00:00:01]\nHello\n[00:00:02]\n", Format::SubViewer);

        $this->assertSame(
            "[TITLE]\nBakery\n[AUTHOR]\n******** START SCRIPT ********\n[00:00:01]\nHello\n[00:00:02]\n\n[end]\n******** END SCRIPT ********\n",
            $subtitle->toString(Format::SubViewer)
        );
    }


    public function testTheVersionOptionWinsOverTheStoredVersion(): void
    {
        $subtitle = Subtitle::fromString("[TITLE]\nBakery\n******** START SCRIPT ********\n[00:00:01]\nHello\n[00:00:02]\n", Format::SubViewer);

        $this->assertStringEndsWith(
            "[SUBTITLE]\n00:00:01.00,00:00:02.00\nHello\n",
            $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V2)]))
        );
    }


    public function testWritesSubViewer2ForASubtitleOfAnotherFormat(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000\nHello\n", Format::SubRip);

        $this->assertStringStartsWith("[INFORMATION]\n", $subtitle->toString(Format::SubViewer));
    }


    public function testWritesSubViewer2ForAnUnknownStoredVersion(): void
    {
        $subtitle = (new Subtitle())
            ->setFormatData("subviewer", ["version" => 3])
            ->addCue(new SubtitleCue(1, 2, "Hello"));

        $this->assertStringStartsWith("[INFORMATION]\n", $subtitle->toString(Format::SubViewer));
    }


    public function testALineFeedAndABarInsideALineReadBackInSubViewer1(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["a\nb", "Rock | Pop"]))
            ->addCue(new SubtitleCue(3, 4, ["c"]));
        $output = $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V1)]));

        $cues = Subtitle::fromString($output, Format::SubViewer)->getCues();
        $this->assertCount(2, $cues);
        $this->assertSame(["a", "b", "Rock \u{00A6} Pop"], $cues[0]->getLines());
    }


    public function testABrTagInsideALineReadsBackAsText(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["a[br]b", "c"]));

        $this->assertSame(["a[\u{2060}br]b", "c"], Subtitle::fromString($subtitle->toString(Format::SubViewer), Format::SubViewer)->getCues()[0]->getLines());
    }


    /**
     * @return array<string, array{string, SubViewerVersion, string}>
     */
    public static function structureLines(): array
    {
        $joiner = "\u{2060}";

        return [
            "version 1 time line"         => ["[00:00:03]", SubViewerVersion::V1, "{$joiner}[00:00:03]"],
            "version 2 timing line"       => ["00:00:03.50,00:00:03.80", SubViewerVersion::V2, "{$joiner}00:00:03.50,{$joiner}00:00:03.80"],
            "version 2 one time field"    => ["x,1:30:00", SubViewerVersion::V2, "x,{$joiner}1:30:00"],
            "version 2 style line"        => ["[COLF]&HFFFFFF", SubViewerVersion::V2, "{$joiner}[COLF]&HFFFFFF"],
            "version 2 start script line" => ["******** START SCRIPT ********", SubViewerVersion::V2, "{$joiner}******** START SCRIPT ********"],
        ];
    }


    #[DataProvider("structureLines")]
    public function testATextLineInTheShapeOfStructureReadsBackAsText(string $line, SubViewerVersion $version, string $expected): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, [$line]))
            ->addCue(new SubtitleCue(5, 6, ["c"]));
        $output = $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: $version)]));

        $cues = Subtitle::fromString($output, Format::SubViewer)->getCues();
        $this->assertCount(2, $cues);
        $this->assertSame($expected, Markup::decodeEntities($cues[0]->getText()));
    }


    public function testHeaderValuesAndKeysThatTheParserReadsDifferentlyAreRepaired(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "A\nB")
            ->setFormatData("subviewer", ["header" => [
                "lower" => "x", "COLF" => "y", "INFORMATION" => "z", "" => "x", "A]" => "x", " B" => "x",
                "SOURCE" => "a [FONT] b", "PRG" => "x,1:30:00", "FILEPATH" => "a\r\nb",
            ]])
            ->addCue(new SubtitleCue(0, 1, "x"));

        $back = Subtitle::fromString($subtitle->toString(Format::SubViewer), Format::SubViewer);

        $this->assertSame("A B", $back->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame(["SOURCE" => "", "PRG" => "", "FILEPATH" => "a  b"], $back->findFormatData("subviewer")["header"]);
        $this->assertNull($back->findFormatData("subviewer")["style"] ?? null);
    }


    public function testSubViewer1HeaderValuesThatLookLikeStructureStayOnTheTagLine(): void
    {
        $subtitle = (new Subtitle())
            ->setFormatData("subviewer", ["version" => 1, "header" => ["SOURCE" => "[x]y", "PRG" => "******** START SCRIPT ********", "FILEPATH" => "a\nb"]])
            ->addCue(new SubtitleCue(0, 1, "x"));

        $back = Subtitle::fromString($subtitle->toString(Format::SubViewer), Format::SubViewer);

        $this->assertSame(["SOURCE" => "[x]y", "PRG" => "******** START SCRIPT ********", "FILEPATH" => "a b"], $back->findFormatData("subviewer")["header"]);
        $this->assertCount(1, $back->getCues());
    }
}
