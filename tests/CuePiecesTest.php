<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Resegmenting\CuePiece;
use SubtitleToolbox\Resegmenting\CuePieces;

class CuePiecesTest extends TestCase
{
    /** @return list<array{string, string, ?float}> the text, the separator and the time of each piece */
    private static function describe(CuePieces $pieces): array
    {
        return array_map(fn (CuePiece $piece): array => [$piece->text, $piece->separator, $piece->time], $pieces->pieces);
    }


    public function testWordTimestampInsideAWordStartsAPiece(): void
    {
        $pieces = CuePieces::of(new SubtitleCue(1, 3, "<00:00:01.000>Wel<00:00:01.400>come home"));

        $this->assertSame([
            ["<00:00:01.000>Wel", "", 1.0],
            ["<00:00:01.400>come", "", 1.4],
            ["home", " ", null],
        ], self::describe($pieces));
        $this->assertSame([0, 3, 8, 12], $pieces->positions());
        $this->assertSame([1.0, 1.4, 2.289, 3.0], $pieces->times(1, 3));
        $this->assertSame([2, 2], [$pieces->breakRank(1), $pieces->breakRank(2)]);
        $this->assertSame("<00:00:01.400>come home", $pieces->text(1, 3));
    }


    public function testFullStopBeforeALowerCaseWordEndsNoSentence(): void
    {
        $pieces = CuePieces::of(new SubtitleCue(1, 3, "<i>Hi.</i> there"));

        $this->assertSame([["<i>Hi.</i>", "", null], ["there", " ", null]], self::describe($pieces));
        $this->assertFalse($pieces->endsSentence(0));
        $this->assertSame(2, $pieces->breakRank(1));
        $this->assertSame(["<i>Hi.</i>", "there"], [$pieces->text(0, 1), $pieces->text(1, 2)]);
    }


    public function testTextClosesAndOpensTheTagsAroundABreak(): void
    {
        $pieces = CuePieces::of(new SubtitleCue(1, 3, "<i>Hi. There</i>"));

        $this->assertTrue($pieces->endsSentence(0));
        $this->assertSame(0, $pieces->breakRank(1));
        $this->assertSame(["<i>Hi.</i>", "<i>There</i>"], [$pieces->text(0, 1), $pieces->text(1, 2)]);
    }


    public function testJapaneseTextSplitsAfterTheIdeographicComma(): void
    {
        $pieces = CuePieces::of(new SubtitleCue(1, 3, "はい、そうです"));

        $this->assertSame([["はい、", "", null], ["そうです", "", null]], self::describe($pieces));
        $this->assertSame([0, 3, 7], $pieces->positions());
        $this->assertSame([1.0, 1.857, 3.0], $pieces->times(1, 3));
        $this->assertSame(1, $pieces->breakRank(1));
    }


    public function testCueWithoutTextHasNoPieces(): void
    {
        $this->assertSame(0, CuePieces::of(new SubtitleCue(1, 3, ""))->count());
    }
}
