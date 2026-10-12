<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class CueLookupTest extends TestCase
{
    private function parseSigns(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(__DIR__ . "/files/ass/real/own_signs_crlf.ass"), Format::Ass);
    }


    private function parseHarbourTour(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(__DIR__ . "/files/editing/harbour_tour.vtt"), Format::WebVtt);
    }


    /**
     * Keeps the cues in the order of $times, so that the cue list is not sorted by start time.
     */
    private function makeUnsortedSubtitle(array $times): Subtitle
    {
        return TestSubtitles::inGivenOrder(array_map(fn (array $time, int $index): array => [$time[0], $time[1], "text$index"], $times, array_keys($times)));
    }


    /**
     * A cue every 2.5 s for 2 s, a 30 s sign every 100 cues and one cue from start to end, 10,101 cues in total.
     */
    private function makeLargeSubtitle(): Subtitle
    {
        $cues = [new SubtitleCue(0, 25000, "background")];
        for ($cue = 0; $cue < 10000; $cue++) {
            $cues[] = new SubtitleCue($cue * 2.5, $cue * 2.5 + 2, "line $cue");
            if ($cue % 100 === 0) {
                $cues[] = new SubtitleCue($cue * 2.5 + 1, $cue * 2.5 + 31, "sign $cue");
            }
        }

        return (new Subtitle())->addCues($cues);
    }


    private function scanCuesAt(Subtitle $subtitle, float $time): array
    {
        return array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool =>
            $cue->getStart() <= $time && $time < $cue->getEnd());
    }


    public function testCountAndIterateRealFile(): void
    {
        $subtitle = $this->parseSigns();

        $this->assertCount(7, $subtitle);
        $this->assertSame(7, count($subtitle));
        $this->assertSame($subtitle->getCues(), iterator_to_array($subtitle));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_keys(iterator_to_array($subtitle)));
    }


    public function testCountEmptySubtitle(): void
    {
        $subtitle = new Subtitle();

        $this->assertCount(0, $subtitle);
        $this->assertSame([], $subtitle->findCuesAt(1));
        $this->assertNull($subtitle->findCueIndexAt(1));
        $this->assertSame([], $subtitle->findCuesBetween(0, 10));
    }


    public function testFindCuesAtFindsOverlappingCuesInRealFile(): void
    {
        $subtitle = $this->parseSigns();

        $this->assertSame([1, 2, 3], array_keys($subtitle->findCuesAt(5)));
        $this->assertSame([2, 3, 4], array_keys($subtitle->findCuesAt(8.5)));
        $this->assertSame("SUNNY\n25 °C", $subtitle->findCuesAt(8.5)[2]->getText());
        $this->assertSame(2, $subtitle->findCueIndexAt(8.5));
    }


    public function testCueIsOnScreenFromStartUntilBeforeEnd(): void
    {
        $subtitle = $this->parseHarbourTour();

        $this->assertSame([0], array_keys($subtitle->findCuesAt(1)));
        $this->assertSame([1], array_keys($subtitle->findCuesAt(4)));
        $this->assertSame([], $subtitle->findCuesAt(0.999));
        $this->assertSame([], $subtitle->findCuesAt(6.2));
        $this->assertNull($subtitle->findCueIndexAt(6.2));
        $this->assertNull($subtitle->findCueIndexAt(20));
        $this->assertSame(5, $subtitle->findCueIndexAt(19.999));
    }


    public function testZeroLengthCueIsNeverOnScreen(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1], [1, 2]]);

        $this->assertSame([1], array_keys($subtitle->findCuesAt(1)));
    }


    public function testFindCuesBetweenReturnsOverlappingCuesUncut(): void
    {
        $subtitle = $this->parseHarbourTour();
        $cues     = $subtitle->findCuesBetween(5, 13);

        $this->assertSame([1, 2], array_keys($cues));
        $this->assertSame($subtitle->getCues()[1], $cues[1]);
        $this->assertSame(4.0, $cues[1]->getStart());
        $this->assertSame(12.5, $cues[2]->getEnd());
        $this->assertSame([3, 4], array_keys($subtitle->findCuesBetween(13, 17)));
        $this->assertSame([], $subtitle->findCuesBetween(17, 18));
    }


    public function testFindCuesBetweenRejectsReversedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The range start 10 must not be after the range end 5.");

        (new Subtitle())->findCuesBetween(10, 5);
    }


    public function testCuesOutOfOrderUseLinearScan(): void
    {
        $subtitle = $this->makeUnsortedSubtitle([[5, 9], [1, 4], [3, 6]]);

        $this->assertSame([0, 2], array_keys($subtitle->findCuesAt(5)));
        $this->assertSame(1, $subtitle->findCueIndexAt(3.5));
        $this->assertSame([1, 2], array_keys($subtitle->findCuesBetween(2, 5)));
    }


    public function testLookupSeesTimeChangesOfCues(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertSame(0, $subtitle->findCueIndexAt(2));

        $subtitle->shift(10);
        $this->assertNull($subtitle->findCueIndexAt(2));
        $this->assertSame(0, $subtitle->findCueIndexAt(12));

        $subtitle->getCues()[5]->setStart(1)->setEnd(3);
        $this->assertSame(0, $subtitle->findCueIndexAt(2));

        $subtitle->removeCue(0);
        $this->assertNull($subtitle->findCueIndexAt(2));
    }


    public function testLookupSeesSetStartAfterLookup(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertSame(0, $subtitle->findCueIndexAt(2));
        $this->assertSame([0, 1], array_keys($subtitle->findCuesBetween(1, 5)));

        $subtitle->getCues()[0]->setStart(2.5);

        $this->assertNull($subtitle->findCueIndexAt(2));
        $this->assertSame(0, $subtitle->findCueIndexAt(2.5));
        $this->assertSame([], $subtitle->findCuesBetween(1, 2.5));
        $this->assertSame([0], array_keys($subtitle->findCuesBetween(1, 2.6)));
    }


    public function testLookupSeesSetStartOnLargeSubtitle(): void
    {
        $subtitle = $this->makeLargeSubtitle();
        $this->assertSame(["background", "line 4000", "sign 4000"], TestSubtitles::texts($subtitle->findCuesAt(10001.5)));

        $sign = array_key_first($subtitle->findCues(fn (SubtitleCue $cue): bool => $cue->getText() === "sign 4000"));
        $subtitle->getCues()[$sign]->setStart(10001.6);

        $this->assertSame(["background", "line 4000"], TestSubtitles::texts($subtitle->findCuesAt(10001.5)));
        $this->assertSame(["background", "line 4000", "sign 4000"], TestSubtitles::texts($subtitle->findCuesAt(10001.6)));
    }


    public function testLookupSeesCueAddedAfterLookup(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertNull($subtitle->findCueIndexAt(30));

        $subtitle->addCue(new SubtitleCue(29, 31, "late"));

        $this->assertSame(6, $subtitle->findCueIndexAt(30));
    }


    public function testLookupSeesCueRemovedAfterLookup(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertSame(0, $subtitle->findCueIndexAt(2));

        $subtitle->removeCue(0);

        $this->assertNull($subtitle->findCueIndexAt(2));
        $this->assertSame(0, $subtitle->findCueIndexAt(5));
    }


    public function testLookupOnLargeSubtitleMatchesLinearScan(): void
    {
        $subtitle = $this->makeLargeSubtitle();

        $this->assertCount(10101, $subtitle);
        $this->assertSame(["background", "line 4000", "sign 4000"], TestSubtitles::texts($subtitle->findCuesAt(10001.5)));
        $this->assertSame(["background", "sign 4000", "line 4011"], TestSubtitles::texts($subtitle->findCuesAt(10027.5)));
        $this->assertSame(["background", "line 9999"], TestSubtitles::texts($subtitle->findCuesAt(24997.5)));
        $this->assertSame(["background"], TestSubtitles::texts($subtitle->findCuesAt(24999.9)));
        $this->assertSame([], $subtitle->findCuesAt(25000));

        mt_srand(81);
        for ($sample = 0; $sample < 200; $sample++) {
            $time = mt_rand(-100, 2510000) / 100;
            $this->assertSame(array_keys($this->scanCuesAt($subtitle, $time)), array_keys($subtitle->findCuesAt($time)));
        }
    }


    public function testFindCuesBetweenOnLargeSubtitle(): void
    {
        $subtitle = $this->makeLargeSubtitle();

        $this->assertSame(
            ["background", "line 3999", "line 4000", "sign 4000"],
            TestSubtitles::texts($subtitle->findCuesBetween(9999, 10002.5))
        );
    }


    public function testFindCuesKeepsIndexes(): void
    {
        $subtitle = $this->parseHarbourTour();

        $cues = $subtitle->findCues(fn (SubtitleCue $cue): bool => str_contains($cue->getText(), "harbour"));

        $this->assertSame([0, 1], array_keys($cues));
        $this->assertCount(6, $subtitle);
    }


    public function testFilterCuesRemovesCuesAndKeepsComments(): void
    {
        $subtitle = $this->parseHarbourTour();

        $result = $subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => str_contains($cue->getText(), "Guide"));

        $this->assertSame($subtitle, $result);
        $this->assertSame([0, 1, 2, 3], array_keys($subtitle->getCues()));
        $this->assertEquals(
            [
                new Comment("Recorded on the north pier", 0),
                new Comment("Segment two starts here", 1),
                new Comment("The guide speaks from here", 3),
                new Comment("The boat turns left here", 3),
                new Comment("End of recording", 4),
            ],
            $subtitle->getComments()
        );
        $this->assertStringContainsString(
            "NOTE The boat turns left here\n\n4\n00:00:18.000 --> 00:00:20.000",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testFilterCuesByDuration(): void
    {
        $subtitle = $this->parseSigns();

        $subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => $cue->getEnd() - $cue->getStart() < 3.5);

        $this->assertSame([[5.0, 9.0], [5.0, 9.0]], array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()],
            $subtitle->getCues()
        ));
    }


    public function testAnUnserializedSubtitleInANewProcessFindsTheCuesAtTheirNewTimes(): void
    {
        $autoload = var_export(__DIR__ . "/../vendor/autoload.php", true);
        $fixture  = var_export(__DIR__ . "/files/profanity/keys.srt", true);
        $saved    = $this->runPhp("require $autoload;
            \$subtitle = SubtitleToolbox\\Subtitle::load($fixture, SubtitleToolbox\\Format::SubRip);
            \$subtitle->findCuesAt(1.5);
            echo base64_encode(serialize(\$subtitle));");

        $found = $this->runPhp("require $autoload;
            \$subtitle = unserialize(base64_decode('$saved'));
            foreach (\$subtitle->getCues() as \$cue) {
                \$cue->setEnd(\$cue->getEnd() + 100)->setStart(\$cue->getStart() + 100);
            }
            echo json_encode([array_keys(\$subtitle->findCuesAt(1.5)), array_keys(\$subtitle->findCuesAt(101.5))]);");

        $this->assertSame("[[],[0]]", $found);
    }


    public function testLookupsAfterEachTimeEditMatchAScanOfAllCues(): void
    {
        mt_srand(934);
        $times = [];
        for ($index = 0; $index < 200; $index++) {
            $times[] = [mt_rand(0, 4000) / 10, mt_rand(0, 4000) / 10];
        }
        $subtitle = TestSubtitles::fromCues(array_map(fn (array $time): array => [min($time), max($time) + 0.5, "cue"], $times));

        for ($edit = 0; $edit < 400; $edit++) {
            $cue = $subtitle->getCues()[mt_rand(0, 199)];
            if ($edit % 3 === 0) {
                $cue->setStart(max(0, $cue->getStart() + mt_rand(-50, 50) / 10));
            }
            $cue->setEnd($cue->getStart() + mt_rand(1, 300) / 10);

            $time     = mt_rand(0, 4000) / 10;
            $expected = array_filter($subtitle->getCues(), fn (SubtitleCue $other): bool => $other->getStart() <= $time && $other->getEnd() > $time);
            $this->assertSame(array_keys($expected), array_keys($subtitle->findCuesAt($time)), "Edit $edit at $time.");
        }
    }


    private function runPhp(string $code): string
    {
        $process = proc_open([PHP_BINARY, "-r", $code], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        $stdout  = stream_get_contents($pipes[1]);
        $stderr  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame([0, ""], [proc_close($process), $stderr]);

        return $stdout;
    }
}
