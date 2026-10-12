<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRules;

class CueListSpeedTest extends TestCase
{
    private const CUE_COUNT = 20000;


    // A sort after each added cue takes minutes here.
    public function testAddCueInALoopAddsTwentyThousandCuesUnderFiveSeconds(): void
    {
        $start    = microtime(true);
        $subtitle = $this->repeatHarbourTour(self::CUE_COUNT);

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertCount(self::CUE_COUNT, $subtitle);
        $this->assertCount(self::CUE_COUNT, $subtitle->getComments());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testParsesTwentyThousandNoteBlocksUnderFiveSeconds(): void
    {
        $content = $this->repeatHarbourTour(self::CUE_COUNT)->toString(Format::WebVtt);

        $start    = microtime(true);
        $subtitle = Subtitle::fromString($content, Format::WebVtt);

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertCount(self::CUE_COUNT, $subtitle->getComments());
        $this->assertSame($content, $subtitle->toString(Format::WebVtt));
    }


    public function testRemoveCueFiveHundredTimesUnderFiveSeconds(): void
    {
        $subtitle = $this->repeatHarbourTour(5000);
        $expected = array_slice($subtitle->getCues(), 500);

        $start = microtime(true);
        for ($removed = 0; $removed < 500; $removed++) {
            $subtitle->removeCue(0);
        }

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertSame($expected, $subtitle->getCues());
        $this->assertSame([0, 0], array_map(fn (Comment $comment): int => $comment->beforeCueIndex, array_slice($subtitle->getComments(), 0, 2)));
        $this->assertSame(range(1, 4499), array_map(fn (Comment $comment): int => $comment->beforeCueIndex, array_slice($subtitle->getComments(), 501)));
    }


    // A filter of all cues and comments after each join takes about 20 s here.
    public function testMergeShortCuesJoinsTwentyThousandCuesUnderTenSeconds(): void
    {
        $subtitle = $this->withACommentBeforeEachCue(array_map(fn (int $index): SubtitleCue => new SubtitleCue($index * 0.6, $index * 0.6 + 0.5, "word$index"), range(0, self::CUE_COUNT - 1)));

        $start = microtime(true);
        $subtitle->mergeShortCues();

        $this->assertLessThan(10, microtime(true) - $start);
        $this->assertCount(self::CUE_COUNT / 2, $subtitle);
        $this->assertSame(["word0 word1"], $subtitle->getCues()[0]->getLines());
        $this->assertSame([0, 0, 1, 1], array_map(fn (Comment $comment): int => $comment->beforeCueIndex, array_slice($subtitle->getComments(), 0, 4)));
    }


    public function testRemoveDuplicateCuesAndMergeSameTimeCuesJoinTwentyThousandCuesUnderFiveSeconds(): void
    {
        $twice      = fn (int $index): SubtitleCue => new SubtitleCue(intdiv($index, 2) * 2, intdiv($index, 2) * 2 + 1, "text" . intdiv($index, 2));
        $duplicates = $this->withACommentBeforeEachCue(array_map($twice, range(0, self::CUE_COUNT - 1)));
        $sameTimes  = $this->withACommentBeforeEachCue(array_map(fn (int $index): SubtitleCue => $twice($index)->setLines("line$index"), range(0, self::CUE_COUNT - 1)));

        $start = microtime(true);
        $duplicates->removeDuplicateCues();
        $sameTimes->mergeSameTimeCues();

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertSame(["text0", "text1"], TestSubtitles::texts(array_slice($duplicates->getCues(), 0, 2)));
        $this->assertSame(["line0", "line1"], $sameTimes->getCues()[0]->getLines());
        foreach ([$duplicates, $sameTimes] as $subtitle) {
            $this->assertCount(self::CUE_COUNT / 2, $subtitle);
            $this->assertSame([0, 0, 1, 1], array_map(fn (Comment $comment): int => $comment->beforeCueIndex, array_slice($subtitle->getComments(), 0, 4)));
        }
    }


    /**
     * @param list<SubtitleCue> $cues
     */
    private function withACommentBeforeEachCue(array $cues): Subtitle
    {
        $subtitle = (new Subtitle())->addCues($cues);
        foreach (array_keys($cues) as $index) {
            $subtitle->addComment("note $index", $index);
        }

        return $subtitle;
    }


    /**
     * Repeats the cues and the comments of harbour_tour.vtt every 30 s, with one addCue() and addComment() call for each.
     */
    private function repeatHarbourTour(int $cueCount): Subtitle
    {
        $harbour  = Subtitle::load(__DIR__ . "/files/editing/harbour_tour.vtt", Format::WebVtt);
        $cues     = $harbour->getCues();
        $comments = $harbour->getComments();
        $subtitle = new Subtitle();
        for ($index = 0; $index < $cueCount; $index++) {
            $cue    = $cues[$index % count($cues)];
            $offset = intdiv($index, count($cues)) * 30;
            $subtitle->addCue(new SubtitleCue($cue->getStart() + $offset, $cue->getEnd() + $offset, $cue->getLines()));
            $subtitle->addComment($comments[$index % count($comments)]->text, $index);
        }

        return $subtitle;
    }
}
