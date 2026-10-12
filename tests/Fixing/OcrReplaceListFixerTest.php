<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use PHPUnit\Framework\TestCase;

class OcrReplaceListFixerTest extends TestCase
{
    public function testWholeWordKeepsThePunctuationOnBothSides(): void
    {
        $list = new OcrReplaceList(wholeWords: ["celd" => "cold", "-oD." => "-on."]);

        $this->assertSame(["\"(cold),\" -on. (cold!)"],
                          OcrReplaceListFixer::replaceList(["\"(celd),\" -oD. (celd!)"], $list, false));
    }


    public function testPartialLineNeedsABoundaryAtAMultibyteCharacter(): void
    {
        $list = new OcrReplaceList(partialLines: ["*'*." => "p.m."]);

        $this->assertSame(["At 5 p.m.\u{266A} and é*'*."],
                          OcrReplaceListFixer::replaceList(["At 5 *'*.\u{266A} and é*'*."], $list, false));
    }


    public function testEndLineAddsNoFullStopWhenTheNextCueContinues(): void
    {
        $list = new OcrReplaceList(endLines: [" deck'" => " deck.", " c0ld." => " cold."]);

        $this->assertSame(["On the deck'", "On the deck."], OcrReplaceListFixer::replaceList(["On the deck'", "On the deck'"], $list, false));
        $this->assertSame(["On the deck'"], OcrReplaceListFixer::replaceList(["On the deck'"], $list, true));
        $this->assertSame(["So cold."], OcrReplaceListFixer::replaceList(["So c0ld."], $list, true));
    }
}
