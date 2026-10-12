<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SentenceCaserTest extends TestCase
{
    public function testSentenceEndInOneRunCapitalizesTheNextRun(): void
    {
        $caser = new SentenceCaser(false);
        $cue   = new SubtitleCue(0, 1, ["<i>GO NOW.</i> THIS ONE, <b>OK</b>"]);

        $this->assertSame(["Go now.", " This one, ", "ok"],
                          [$caser->run("GO NOW.", $cue, true), $caser->run(" THIS ONE, ", $cue, false), $caser->run("OK", $cue, false)]);
    }


    /**
     * @return array<string, array{float, string}>
     */
    public static function gaps(): array
    {
        return [
            "gap of 1.9 s" => [2.9, "the bus"],
            "gap of 2 s"   => [3.0, "The bus"],
        ];
    }


    #[DataProvider("gaps")]
    public function testCueAfterAGapOf2SecondsStartsASentence(float $start, string $expected): void
    {
        $caser = new SentenceCaser(false);
        $caser->run("WAIT FOR", new SubtitleCue(0, 1, ["WAIT FOR"]), true);

        $this->assertSame($expected, $caser->run("THE BUS", new SubtitleCue($start, 4, ["THE BUS"]), true));
    }


    public function testTurkicCaseKeepsTheDotOfI(): void
    {
        $this->assertSame("İstanbul kapı", (new SentenceCaser(true))->run("İSTANBUL KAPI", new SubtitleCue(0, 1, ["İSTANBUL KAPI"]), true));
        $this->assertSame("Istanbul kapi", (new SentenceCaser(false))->run("ISTANBUL KAPI", new SubtitleCue(0, 1, ["ISTANBUL KAPI"]), true));
    }
}
