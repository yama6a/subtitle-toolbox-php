<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SubtitleCueTest extends TestCase
{
    public function testConstructorSetsAttributes()
    {
        $cue = new SubtitleCue(
            $start = 0.1,
            $end = 0.33,
            $text = (
                ($line1 = "this is a line") .
                "\n" .
                ($line2 = "with a linebreak")
            )
        );

        $this->assertSame(0.1, $cue->getStart());
        $this->assertSame(0.33, $cue->getEnd());
        $this->assertSame($text, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testGetAndSetStart()
    {
        $cue = new SubtitleCue();
        $cue->setStart($start = 123.456);

        $this->assertSame($start, $cue->getStart());
    }


    public function testGetAndSetEnd()
    {
        $cue = new SubtitleCue();
        $cue->setEnd($end = 123.456);

        $this->assertSame($end, $cue->getEnd());
    }


    public function testSetLinesWithWindowsStringAndTooManySpacesAndTabs()
    {
        $cue = new SubtitleCue();
        $cue->setLines(
            $text = (
                ($line1 = "this is a       line") .
                "\r\n\r\n" .
                ($line2 = "with a \t\t   linebreak")
            )
        );

        $this->assertNotEquals($text, $cue->getText());
        $this->assertSame(StringHelpers::cleanString($text), $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertNotEquals($line1, $cue->getLines()[0]);
        $this->assertNotEquals($line2, $cue->getLines()[1]);
        $this->assertSame(StringHelpers::cleanString($line1), $cue->getLines()[0]);
        $this->assertSame(StringHelpers::cleanString($line2), $cue->getLines()[1]);
    }


    public function testSetLinesWithArray()
    {
        $cue = new SubtitleCue();
        $cue->setLines($lines = [$line1 = "this is a line", $line2 = "with a linebreak"]);

        $this->assertSame($line1 . "\n" . $line2, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testSetLinesFromString()
    {
        $cue = new SubtitleCue();
        $cue->setLines(
            $text = (
                ($line1 = "this is a line") .
                "\n" .
                ($line2 = "with a linebreak")
            )
        );

        $this->assertSame($text, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testSetLinesFromArray()
    {
        $cue = new SubtitleCue();
        $cue->setLines($lines = [$line1 = "this is a line", $line2 = "with a linebreak"]);

        $this->assertSame($line1 . "\n" . $line2, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testAddLine()
    {
        $cue = new SubtitleCue();
        $this->assertSame(0, count($cue->getLines()));

        $cue->addLine($line1 = "first line");
        $cue->addLine($line2 = "second line");

        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testSetLinesRejectsOtherTypes(): void
    {
        $this->expectException(\TypeError::class);
        (new SubtitleCue())->setLines(123.456);
    }


    public function testGetTextOfCueWithoutLinesIsEmpty(): void
    {
        $this->assertSame("", (new SubtitleCue())->getText());
        $this->assertSame("", (new SubtitleCue(0, 1, ["", " "]))->getText());
    }


    public function testIdentifierIsNullByDefault(): void
    {
        $this->assertNull((new SubtitleCue())->getIdentifier());
    }


    public function testGetAndSetIdentifier(): void
    {
        $cue = (new SubtitleCue())->setIdentifier("intro");
        $this->assertSame("intro", $cue->getIdentifier());

        $cue->setIdentifier(null);
        $this->assertNull($cue->getIdentifier());
    }


    public function testAlignmentIsBottomCenterByDefault(): void
    {
        $this->assertSame(Alignment::BottomCenter, (new SubtitleCue())->getAlignment());
    }


    public function testGetAndSetAlignment(): void
    {
        $cue = new SubtitleCue();

        foreach (Alignment::cases() as $alignment) {
            $this->assertSame($alignment, $cue->setAlignment($alignment)->getAlignment());
        }
    }


    public function testFormatDataIsEmptyByDefault(): void
    {
        $this->assertSame([], (new SubtitleCue())->findFormatData("ass"));
    }


    public function testSetAndFindFormatDataPerFormat(): void
    {
        $object = (new SubtitleCue())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("vtt", ["region" => "top"]);

        $this->assertSame(["style" => "Default", "marginV" => 10], $object->findFormatData("ass"));
        $this->assertSame(["region" => "top"], $object->findFormatData("vtt"));
        $this->assertSame([], $object->findFormatData("srt"));
    }


    public function testSettingFormatDataReplacesPreviousData(): void
    {
        $object = (new SubtitleCue())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("ass", ["style" => "Sign"]);

        $this->assertSame(["style" => "Sign"], $object->findFormatData("ass"));

        $object->setFormatData("ass", []);
        $this->assertSame([], $object->findFormatData("ass"));
    }


    public function testGetAllFormatDataReturnsEveryFormat(): void
    {
        $object = (new SubtitleCue())
            ->setFormatData("ass", ["style" => "Default"])
            ->setFormatData("vtt", ["region" => "top"])
            ->setFormatData("vtt", []);

        $this->assertSame(["ass" => ["style" => "Default"]], $object->getAllFormatData());
        $this->assertSame([], (new SubtitleCue())->getAllFormatData());
    }


    public function testSetFormatDataRejectsAFieldThatAFormatterReadsWithTheWrongShape(): void
    {
        $cue = (new SubtitleCue())->setFormatData("srt", ["coordinates" => ["x1" => 1, "x2" => 2, "y1" => 3, "y2" => 4]]);
        $this->assertSame(["x1" => 1, "x2" => 2, "y1" => 3, "y2" => 4], $cue->findFormatData("srt")["coordinates"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The field formatData.srt.coordinates.x2 is missing.");

        $cue->setFormatData("srt", ["coordinates" => ["x1" => 1]]);
    }


    public function testMapTimesMovesStartEndAndWordTimestampsAndStopsAtZero(): void
    {
        $cue = CueList::mapTimes(new SubtitleCue(1, 3, "One <00:00:01.000>two <00:00:02.500>three"), fn (float $time): float => $time * 2 - 3);

        $this->assertSame([0.0, 3.0, ["One <00:00:00.000>two <00:00:02.000>three"]], [$cue->getStart(), $cue->getEnd(), $cue->getLines()]);
    }
}
