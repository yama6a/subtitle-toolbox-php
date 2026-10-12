<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\UnsupportedFormatException;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\Validation\ValidationRules;

class SubtitleTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructorMakesArrayOutOfCues()
    {
        $subtitle = new Subtitle();

        $this->assertIsArray($subtitle->getCues());
    }


    public function testAddRemoveAndGetCuesWithOddSpacing()
    {
        $subtitle = new Subtitle();

        $this->assertSame(0, count($subtitle->getCues()));

        $subtitle->addCue(new SubtitleCue(1.2, 3.4, "line1\r\nline2"));
        $subtitle->addCue(new SubtitleCue(5.6, 7.89, "line3  \r\r\n\n\r\n\t\tline4"));

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame(1.2, $subtitle->getCues()[0]->getStart());
        $this->assertSame(3.4, $subtitle->getCues()[0]->getEnd());
        $this->assertSame("line1\nline2", $subtitle->getCues()[0]->getText());
        $this->assertSame(5.6, $subtitle->getCues()[1]->getStart());
        $this->assertSame(7.89, $subtitle->getCues()[1]->getEnd());
        $this->assertSame("line3\nline4", $subtitle->getCues()[1]->getText());
    }


    public function testRemovingInexistendCueThrowsException()
    {
        $subtitle = new Subtitle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("the cue does not exist");
        $subtitle->removeCue(123);

    }


    public function testAddCueTriggersReIndex()
    {
        $subtitle = new Subtitle();
        $subtitle->addCue($cue1 = new SubtitleCue(1, 2, "text1"));
        $subtitle->addCue($cue2 = new SubtitleCue(5, 6, "text2"));
        $subtitle->addCue($cue3 = new SubtitleCue(3, 4, "text3"));

        $this->assertSame($cue1, $subtitle->getCues()[0]);
        $this->assertSame($cue3, $subtitle->getCues()[1]);
        $this->assertSame($cue2, $subtitle->getCues()[2]);
    }


    public function testAddCuesSortsAllCuesAndKeepsCommentsWithTheirCues(): void
    {
        $subtitle = (new Subtitle())->addCue($late = new SubtitleCue(8, 9, "late"))->addComment("before late", 0);

        $subtitle->addCues((function () use (&$early, &$middle): \Generator {
            yield $middle = new SubtitleCue(5, 6, "middle");
            yield $early = new SubtitleCue(1, 2, "early");
        })());

        $this->assertSame([$early, $middle, $late], $subtitle->getCues());
        $this->assertEquals([new Comment("before late", 2)], $subtitle->getComments());
    }


    public function testCommentAfterTheLastCueStaysLastWhenACueIsAddedFirst(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 2, "one"), new SubtitleCue(3, 4, "three"), new SubtitleCue(5, 6, "five")]);
        $subtitle->addComment("x", 3);

        $subtitle->addCue(new SubtitleCue(0, 0.5, "zero"));

        $this->assertEquals([new Comment("x", 4)], $subtitle->getComments());
    }


    public function testCommentAfterTheLastCueStaysLastWhenANewCueSortsLast(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 2, "one"), new SubtitleCue(3, 4, "three")])->addComment("x", 2);

        $subtitle->addCue(new SubtitleCue(5, 6, "five"));

        $this->assertEquals([new Comment("x", 3)], $subtitle->getComments());
    }


    public function testAddCueSortsCuesThatAStartTimeEditLeftOutOfOrder(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/files/editing/harbour_tour.vtt", Format::WebVtt);
        $subtitle->getCues()[3]->setStart(19)->setEnd(20);

        $subtitle->addCue(new SubtitleCue(25, 26, "Thank you for joining the tour."));

        $this->assertSame([1.0, 4.0, 6.5, 15.0, 18.0, 19.0, 25.0], array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $subtitle->getCues()));
        $this->assertSame(["The guide speaks from here", 5], [$subtitle->getComments()[3]->text, $subtitle->getComments()[3]->beforeCueIndex]);
    }


    public function testRemoveCueSortsCuesThatAStartTimeEditLeftOutOfOrder(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 2], [3, 4], [5, 6]]);
        $subtitle->getCues()[0]->setStart(10)->setEnd(11);

        $subtitle->removeCue(1);

        $this->assertSame(["text2", "text0"], TestSubtitles::texts($subtitle));
    }


    public function testRemoveCueNumbersTheCuesFromZeroAgain(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 2, "one"), new SubtitleCue(3, 4, "two"), new SubtitleCue(5, 6, "three")]);

        $subtitle->removeCue(1);

        $this->assertSame([0, 1], array_keys($subtitle->getCues()));
        $this->assertSame("three", $subtitle->getCues()[1]->getText());
    }


    public function testValidateWithTheStructureRulesFindsEachStructureProblem(): void
    {
        $rules    = ValidationRules::structure();
        $problems = fn (Subtitle $subtitle): array => array_map(
            fn (ValidationViolation $result): array => [$result->cueIndex, $result->rule, $result->value],
            $subtitle->validate($rules)
        );

        $subtitle = new Subtitle();
        $this->assertSame([[null, ValidationRule::RequireCues, 0]], $problems($subtitle));

        $subtitle->addCues([new SubtitleCue(1, 2, "text1"), new SubtitleCue(3, 4, "text2"), new SubtitleCue(5, 6, "text3")]);
        $subtitle->getCues()[1]->setStart(5)->setEnd(6);
        $subtitle->getCues()[2]->setStart(3)->setEnd(4);
        $this->assertSame([
            [2, ValidationRule::NoUnsortedCues, 2.0],
            [2, ValidationRule::NoOverlap, 3.0],
        ], $problems($subtitle));

        $subtitle->reIndexCues();
        $this->assertSame([], $problems($subtitle));

        $subtitle->getCues()[1]->setEnd(5.5);
        $this->assertSame([[2, ValidationRule::NoOverlap, 0.5]], $problems($subtitle));
        $subtitle->getCues()[1]->setEnd(4);

        $subtitle->removeCue(1);
        $this->assertSame([], $problems($subtitle));

        $subtitle->addCue(new SubtitleCue(9, 1, "text4"));
        $this->assertSame([[2, ValidationRule::NoNegativeDuration, -8.0]], $problems($subtitle));

        $subtitle->removeCue(2);
        $this->assertSame([], $problems($subtitle));
    }


    public function testFromStringThrowsForAFormatWithoutParser(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage("The format txt can be written but not read.");
        Subtitle::fromString("text", Format::PlainText);
    }


    public function testToStringThrowsForAFormatWithoutFormatter(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage("The format vobsub can be read but not written.");
        (new Subtitle())->toString(Format::VobSub);
    }


    public function testFromStringAutoDetectFormatSkipsFormatsThatAreNotAutoDetected(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        Subtitle::fromStringAutoDetectFormat(file_get_contents(__DIR__ . "/files/chapters/ffmetadata/real/m4b_audiobook.ffmeta"));
    }


    public function testFromStringAutoDetectFormatConvertsTheEncoding(): void
    {
        $content = file_get_contents(__DIR__ . "/files/encoding/french-windows-1252.srt");

        $this->assertEquals(
            Subtitle::fromString($content, Format::SubRip, new ReadOptions(encoding: "Windows-1252")),
            Subtitle::fromStringAutoDetectFormat($content, new ReadOptions(encoding: "Windows-1252"))
        );
    }


    public function testReIndexSortsStartsLessThanOneSecondApart(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1.5, 2, "later"));
        $subtitle->addCue(new SubtitleCue(1.2, 1.4, "earlier"));

        $this->assertSame("earlier", $subtitle->getCues()[0]->getText());
        $this->assertSame("later", $subtitle->getCues()[1]->getText());
    }


    public function testFormattersNumberCuesFromOneAfterRemovalWithoutReIndex(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->removeCue(0);

        $this->assertSame(
            "\u{feff}1\n00:00:03,000 --> 00:00:04,000\nsecond\n",
            $subtitle->toString(Format::SubRip)
        );
        $this->assertSame(
            "\u{feff}WEBVTT\n\n1\n00:00:03.000 --> 00:00:04.000\nsecond\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testMetadataIsEmptyByDefault(): void
    {
        $subtitle = new Subtitle();

        $this->assertSame([], $subtitle->getAllMetadata());
        $this->assertNull($subtitle->findMetadata(Subtitle::METADATA_TITLE));
    }


    public function testSetAndFindMetadata(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Yesterday")
            ->setMetadata(Subtitle::METADATA_ARTIST, "The Beatles")
            ->setMetadata("custom", "");

        $this->assertSame("Yesterday", $subtitle->findMetadata("title"));
        $this->assertSame("", $subtitle->findMetadata("custom"));
        $this->assertSame(
            ["title" => "Yesterday", "artist" => "The Beatles", "custom" => ""],
            $subtitle->getAllMetadata()
        );
    }


    public function testSettingMetadataOverwritesValue(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "de");

        $this->assertSame(["language" => "de"], $subtitle->getAllMetadata());
    }


    public function testSettingMetadataToNullRemovesKey(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Jane Doe")
            ->setMetadata(Subtitle::METADATA_ALBUM, "Help!")
            ->setMetadata(Subtitle::METADATA_AUTHOR, null)
            ->setMetadata("missing", null);

        $this->assertNull($subtitle->findMetadata("author"));
        $this->assertSame(["album" => "Help!"], $subtitle->getAllMetadata());
    }


    public function testCommentsAreEmptyByDefault(): void
    {
        $this->assertSame([], (new Subtitle())->getComments());
    }


    public function testAddCommentKeepsCommentsInCueOrder(): void
    {
        $subtitle = (new Subtitle())
            ->addComment("after last cue", 2)
            ->addComment("first before cue 0", 0)
            ->addComment("second before cue 0", 0)
            ->addComment("before cue 1", 1);

        $this->assertEquals(
            [
                new Comment("first before cue 0", 0),
                new Comment("second before cue 0", 0),
                new Comment("before cue 1", 1),
                new Comment("after last cue", 2),
            ],
            $subtitle->getComments()
        );
    }


    public function testAddCommentWithNegativeIndexThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must not be negative");
        (new Subtitle())->addComment("text", -1);
    }


    public function testReIndexKeepsEachCommentBeforeItsCue(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 2, "late"), new SubtitleCue(3, 4, "early")]);
        $subtitle->addComment("before late", 0);
        $subtitle->addComment("before early", 1);
        $subtitle->addComment("at the end", 2);
        $subtitle->getCues()[0]->setStart(5)->setEnd(6);
        $subtitle->getCues()[1]->setStart(1)->setEnd(2);

        $subtitle->reIndexCues();

        $this->assertSame("early", $subtitle->getCues()[0]->getText());
        $this->assertEquals(
            [
                new Comment("before early", 0),
                new Comment("before late", 1),
                new Comment("at the end", 2),
            ],
            $subtitle->getComments()
        );
    }


    public function testCommentBeforeRemovedCueMovesToNextCue(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->addCue(new SubtitleCue(5, 6, "third"));
        $subtitle->addComment("before second", 1);
        $subtitle->addComment("before third", 2);

        $subtitle->removeCue(1);

        $this->assertEquals(
            [
                new Comment("before second", 1),
                new Comment("before third", 1),
            ],
            $subtitle->getComments()
        );
    }


    public function testCommentBeforeRemovedLastCueMovesToEnd(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->addComment("before second", 1);

        $subtitle->removeCue(1);

        $this->assertEquals([new Comment("before second", 1)], $subtitle->getComments());
    }


    public function testFormatDataIsEmptyByDefault(): void
    {
        $this->assertSame([], (new Subtitle())->findFormatData("ass"));
    }


    public function testSetAndFindFormatDataPerFormat(): void
    {
        $object = (new Subtitle())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("vtt", ["region" => "top"]);

        $this->assertSame(["style" => "Default", "marginV" => 10], $object->findFormatData("ass"));
        $this->assertSame(["region" => "top"], $object->findFormatData("vtt"));
        $this->assertSame([], $object->findFormatData("srt"));
    }


    public function testSettingFormatDataReplacesPreviousData(): void
    {
        $object = (new Subtitle())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("ass", ["style" => "Sign"]);

        $this->assertSame(["style" => "Sign"], $object->findFormatData("ass"));

        $object->setFormatData("ass", []);
        $this->assertSame([], $object->findFormatData("ass"));
    }


    public function testSetFormatDataRejectsAFieldThatAFormatterReadsWithTheWrongType(): void
    {
        $subtitle = (new Subtitle())->setFormatData("scc", ["dropFrame" => true, "note" => 5]);
        $this->assertSame(["dropFrame" => true, "note" => 5], $subtitle->findFormatData("scc"));

        $this->expectException(\SubtitleToolbox\Exceptions\InvalidArgumentException::class);
        $this->expectExceptionMessage("The field formatData.scc.dropFrame must be a boolean.");

        $subtitle->setFormatData("scc", ["dropFrame" => "yes"]);
    }
}
