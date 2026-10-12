<?php

declare(strict_types=1);

// Times 1,000 calls of findCuesAt() on 100, 2,000 and 20,000 cues, first alone and then each after a setEnd() call.
// Usage: php tests/bench/cue_lookup.php

use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require __DIR__ . "/../../vendor/autoload.php";

const LOOKUPS = 1000;

function buildSubtitle(int $cueCount): Subtitle
{
    $source   = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/real/language_subtitles_dots_tester.srt"), Format::SubRip);
    $subtitle = new Subtitle();
    // Adding with a sort per cue, as the parsers do, takes minutes at 20,000 cues, so this sorts once.
    $cues = [];
    for ($copy = 0; count($cues) < $cueCount; $copy++) {
        foreach ($source->getCues() as $cue) {
            if (count($cues) < $cueCount) {
                $cues[] = new SubtitleCue($cue->getStart() + $copy * 10, $cue->getEnd() + $copy * 10, $cue->getLines());
            }
        }
    }

    return $subtitle->addCues($cues);
}


function milliseconds(callable $run): float
{
    $started = hrtime(true);
    $run();

    return (hrtime(true) - $started) / 1e6;
}


printf("%-8s %16s %16s\n", "cues", "lookups (ms)", "edit+lookup (ms)");
foreach ([100, 2000, 20000] as $cueCount) {
    $subtitle = buildSubtitle($cueCount);
    $last     = $subtitle->getCues()[$cueCount - 1]->getEnd();

    mt_srand(185);
    $times = [];
    for ($lookup = 0; $lookup < LOOKUPS; $lookup++) {
        $times[] = mt_rand(0, (int)($last * 1000)) / 1000;
    }

    $lookups = milliseconds(function () use ($subtitle, $times): void {
        foreach ($times as $time) {
            $subtitle->findCuesAt($time);
        }
    });

    $cues  = $subtitle->getCues();
    $edits = milliseconds(function () use ($subtitle, $cues, $times, $cueCount): void {
        foreach ($times as $lookup => $time) {
            $cue = $cues[($lookup * 7) % $cueCount];
            $cue->setEnd($cue->getEnd() + ($lookup % 2 === 0 ? 0.001 : -0.001));
            $subtitle->findCuesAt($time);
        }
    });

    printf("%-8d %16.1f %16.1f\n", $cueCount, $lookups, $edits);
}
