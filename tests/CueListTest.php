<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class CueListTest extends TestCase
{
    public function testMovingTheChangedCuesGivesTheOrderOfAStableSort(): void
    {
        mt_srand(712);
        for ($round = 0; $round < 300; $round++) {
            $cues = [];
            for ($index = 0; $index < mt_rand(1, 40); $index++) {
                $cues[] = new SubtitleCue(mt_rand(0, 20), 30, "cue$index");
            }
            $cues  = CueList::inStartOrder($cues);
            $moved = new \SplObjectStorage();
            for ($edit = 0; $edit < mt_rand(1, 6); $edit++) {
                $cue = $cues[mt_rand(0, count($cues) - 1)];
                if (!isset($moved[$cue])) {
                    $moved[$cue] = $cue->getStart();
                }
                $cue->setStart(mt_rand(0, 20));
            }

            $this->assertSame(CueList::inStartOrder($cues), CueList::withCuesMoved($cues, $moved) ?? $cues, "Round $round.");
        }
    }


    public function testCuesThatStayBetweenTheirNeighboursMoveNothing(): void
    {
        $cues                          = [new SubtitleCue(1, 2), new SubtitleCue(3, 4), new SubtitleCue(5, 6)];
        $moved                         = new \SplObjectStorage();
        $moved[$cues[1]]               = 3.0;
        $moved[new SubtitleCue(9, 10)] = 9.0;
        $cues[1]->setStart(4.5);

        $this->assertNull(CueList::withCuesMoved($cues, $moved));
    }
}
