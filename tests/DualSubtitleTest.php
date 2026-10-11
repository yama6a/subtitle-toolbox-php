<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class DualSubtitleTest extends TestCase
{
    private const DIR = __DIR__ . "/files/dual/";


    private function parseFixture(string $fileName): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::DIR . $fileName), Format::SubRip);
    }


    public function testFixturesParse(): void
    {
        $english = $this->parseFixture("station_en.srt");
        $german  = $this->parseFixture("station_de.srt");

        $this->assertCount(5, $english->getCues());
        $this->assertSame([1.0, 4.0, "The train to Hamburg leaves from platform 4.", Alignment::BottomCenter],
                          TestSubtitles::describeWithAlignment($english)[0]);
        $this->assertSame([16.0, 19.0, "Take an umbrella with you.", Alignment::BottomCenter], TestSubtitles::describeWithAlignment($english)[4]);

        $this->assertCount(6, $german->getCues());
        $this->assertSame([1.2, 3.9, "Der Zug nach Hamburg fährt von Gleis 4.", Alignment::BottomCenter], TestSubtitles::describeWithAlignment($german)[0]);
        $this->assertSame([19.2, 21.0, "Gute Reise!", Alignment::BottomCenter], TestSubtitles::describeWithAlignment($german)[5]);
    }


    public function testFixturesRoundTrip(): void
    {
        foreach (["station_en.srt", "station_de.srt"] as $fileName) {
            $subtitle = $this->parseFixture($fileName);
            $again    = Subtitle::fromString($subtitle->toString(Format::SubRip), Format::SubRip);

            $this->assertSame(TestSubtitles::describeWithAlignment($subtitle), TestSubtitles::describeWithAlignment($again), $fileName);
        }
    }


    public static function provideExpectedFiles(): array
    {
        $stack     = new DualSubtitleOptions(secondaryStyle: "i");
        $topBottom = new DualSubtitleOptions(mode: DualSubtitleMode::TopBottom,
                                             secondaryStyle: 'font color="#ffff00"');

        return [
            "stack SubRip"          => [$stack, Format::SubRip, "station_stack.srt"],
            "stack WebVTT"          => [$stack, Format::WebVtt, "station_stack.vtt"],
            "stack ASS"             => [$stack, Format::Ass, "station_stack.ass"],
            "top and bottom SubRip" => [$topBottom, Format::SubRip, "station_top_bottom.srt"],
            "top and bottom WebVTT" => [$topBottom, Format::WebVtt, "station_top_bottom.vtt"],
            "top and bottom ASS"    => [$topBottom, Format::Ass, "station_top_bottom.ass"],
        ];
    }


    #[DataProvider("provideExpectedFiles")]
    public function testMergeFixturesMatchesExpectedFile(DualSubtitleOptions $options, Format $format,
                                                         string $expectedFile): void
    {
        $dual = DualSubtitle::fromPair($this->parseFixture("station_en.srt"), $this->parseFixture("station_de.srt"),
                                    $options);

        $this->assertSame(file_get_contents(self::DIR . $expectedFile), $dual->toString($format));
    }


    public function testMergeChangesNeitherInput(): void
    {
        $english       = $this->parseFixture("station_en.srt");
        $german        = $this->parseFixture("station_de.srt");
        $englishBefore = TestSubtitles::describeWithAlignment($english);
        $germanBefore  = TestSubtitles::describeWithAlignment($german);

        DualSubtitle::fromPair($english, $german, new DualSubtitleOptions(secondaryStyle: "i"));
        DualSubtitle::fromPair($english, $german, new DualSubtitleOptions(mode: DualSubtitleMode::TopBottom));

        $this->assertSame($englishBefore, TestSubtitles::describeWithAlignment($english));
        $this->assertSame($germanBefore, TestSubtitles::describeWithAlignment($german));
    }


    public function testStackJoinsTheIssueExample(): void
    {
        $english = TestSubtitles::fromCues([[1, 4, "Where are you going?"]]);
        $german  = TestSubtitles::fromCues([[1.2, 3.9, "Wohin gehst du?"]]);

        $dual = DualSubtitle::fromPair($english, $german, new DualSubtitleOptions(secondaryStyle: "i"));

        $this->assertSame([[1.0, 4.0, "Where are you going?\n<i>Wohin gehst du?</i>", Alignment::BottomCenter]],
                          TestSubtitles::describeWithAlignment($dual));
    }


    public function testStackPicksTheEarlierPrimaryCueOnEqualOverlap(): void
    {
        $primary   = TestSubtitles::fromCues([[0, 2, "first"], [2, 4, "second"]]);
        $secondary = TestSubtitles::fromCues([[1.5, 2.5, "between"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame([[0.0, 2.5, "first\nbetween", Alignment::BottomCenter], [2.0, 4.0, "second", Alignment::BottomCenter]],
                          TestSubtitles::describeWithAlignment($dual));
    }


    public function testStackKeepsTouchingCuesApart(): void
    {
        $primary   = TestSubtitles::fromCues([[0, 2, "first"]]);
        $secondary = TestSubtitles::fromCues([[2, 3, "after"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame([[0.0, 2.0, "first", Alignment::BottomCenter], [2.0, 3.0, "after", Alignment::BottomCenter]], TestSubtitles::describeWithAlignment($dual));
    }


    public function testTopBottomUsesTheAlignmentOfTheOptions(): void
    {
        $primary   = TestSubtitles::fromCues([[0, 2, "first"]]);
        $secondary = TestSubtitles::fromCues([[0, 2, "erste"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleMode::TopBottom,
            secondaryAlignment: Alignment::TopRight,
        ));

        $this->assertSame([[0.0, 2.0, "first", Alignment::BottomCenter], [0.0, 2.0, "erste", Alignment::TopRight]], TestSubtitles::describeWithAlignment($dual));
    }


    public function testTopBottomSnapsUpToTheTolerance(): void
    {
        $primary   = TestSubtitles::fromCues([[1, 4, "first"]]);
        $secondary = TestSubtitles::fromCues([[1.25, 3.7, "erste"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleMode::TopBottom,
        ));
        $noSnap = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleMode::TopBottom,
            snapTolerance: 0,
        ));

        $this->assertSame([1.0, 3.7], [$dual->getCues()[1]->getStart(), $dual->getCues()[1]->getEnd()]);
        $this->assertSame([1.25, 3.7], [$noSnap->getCues()[1]->getStart(), $noSnap->getCues()[1]->getEnd()]);
    }


    public function testTopBottomKeepsTimesWhenSnappingWouldHideTheCue(): void
    {
        $primary   = TestSubtitles::fromCues([[1, 4, "first"]]);
        $secondary = TestSubtitles::fromCues([[3.9, 4.1, "kurz"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleMode::TopBottom,
        ));

        $this->assertSame([3.9, 4.1, "kurz", Alignment::TopCenter], TestSubtitles::describeWithAlignment($dual)[1]);
    }


    public function testMetadataCommentsAndFormatDataComeFromThePrimary(): void
    {
        $primary = TestSubtitles::fromCues([[0, 2, "first"], [3, 5, "second"]])
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setMetadata(Subtitle::METADATA_TITLE, "Station")
            ->setFormatData("vtt", ["header" => "Kind: captions"])
            ->addComment("before second", 1)
            ->addComment("at the end", 2);
        $secondary = TestSubtitles::fromCues([[0.5, 1, "vorher"], [2.2, 2.8, "dazwischen"]])
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "de")
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Somebody")
            ->setFormatData("ass", ["styles" => []])
            ->addComment("secondary comment", 0);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame(["language" => "en+de", "title" => "Station"], $dual->getAllMetadata());
        $this->assertSame(["header" => "Kind: captions"], $dual->findFormatData("vtt"));
        $this->assertSame([], $dual->findFormatData("ass"));
        $this->assertEquals([new Comment("before second", 2),
                           new Comment("at the end", 3)], $dual->getComments());
        $this->assertSame([[0.0, 2.0, "first\nvorher", Alignment::BottomCenter], [2.2, 2.8, "dazwischen", Alignment::BottomCenter], [3.0, 5.0, "second", Alignment::BottomCenter]],
                          TestSubtitles::describeWithAlignment($dual));
    }


    public function testLanguageStaysWhenOnlyThePrimaryHasOne(): void
    {
        $primary   = TestSubtitles::fromCues([[0, 2, "first"]])->setMetadata(Subtitle::METADATA_LANGUAGE, "en");
        $secondary = TestSubtitles::fromCues([[0, 2, "erste"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame("en", $dual->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testSecondaryCuesLoseIdentifierAndFormatData(): void
    {
        $primary = TestSubtitles::fromCues([[0, 2, "first"]]);
        $cue     = (new SubtitleCue(5, 6, "später"))->setIdentifier("7")->setFormatData("ass", ["style" => "Sign"]);
        $secondary = (new Subtitle())->addCue($cue);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions());

        $this->assertNull($dual->getCues()[1]->getIdentifier());
        $this->assertSame([], $dual->getCues()[1]->findFormatData("ass"));
    }


    public function testEmptyPrimaryKeepsItsCommentsAfterTheLastCue(): void
    {
        $primary   = (new Subtitle())->addComment("only a note", 0);
        $secondary = TestSubtitles::fromCues([[0, 2, "erste"]]);

        $dual = DualSubtitle::fromPair($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame([[0.0, 2.0, "erste", Alignment::BottomCenter]], TestSubtitles::describeWithAlignment($dual));
        $this->assertEquals([new Comment("only a note", 1)], $dual->getComments());
        $this->assertSame([], $primary->getCues());
    }


    public static function provideInvalidOptions(): array
    {
        return [
            "negative tolerance" => [["snapTolerance" => -0.1]],
            "unknown tag"        => [["secondaryStyle" => "c.yellow"]],
        ];
    }


    #[DataProvider("provideInvalidOptions")]
    public function testOptionsRejectInvalidValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DualSubtitleOptions(...$arguments);
    }
}
