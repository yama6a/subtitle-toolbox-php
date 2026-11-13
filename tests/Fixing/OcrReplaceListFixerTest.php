<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use PHPUnit\Framework\Attributes\DataProvider;
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


    public function testAnEntryWithAnEmptySearchTextDoesNothing(): void
    {
        $list = new OcrReplaceList(endLines: ["" => "!"], beginLines: ["" => ">> "]);

        $this->assertSame(["Hello. Hi"], OcrReplaceListFixer::replaceList(["Hello. Hi"], $list, false));
    }


    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function dotNetSubstitutions(): array
    {
        return [
            "name without a group is literal" => ["x", '[${name}]', "axc", 'a[${name}]c'],
            "name of a group"                 => ["(?<n>q)(z)?", '${n}|$2', "qz", "q|z"],
            "text before the match"           => ["b", '[$`]', "abc", "a[a]c"],
            "text after the match"            => ["b", "[$']", "abc", "a[c]c"],
            "whole subject"                   => ["b", '[$_]', "abc", "a[abc]c"],
            "last group"                      => ["(b)(x)?", '[$+]', "abc", "a[b]c"],
            "escaped dollar and backslash"    => ["b", '[$$][\\]', "abc", 'a[$][\\]c'],
        ];
    }


    #[DataProvider("dotNetSubstitutions")]
    public function testRegularExpressionsRunTheDotNetSubstitutions(string $find, string $replaceWith, string $text, string $expected): void
    {
        $xml  = "<ReplaceList><RegularExpressions><RegEx find=\"" . htmlspecialchars($find) . "\" replaceWith=\"" . htmlspecialchars($replaceWith) . "\" /></RegularExpressions></ReplaceList>";
        $list = OcrReplaceList::fromSubtitleEditXml($xml);

        $this->assertSame([$expected], OcrReplaceListFixer::replaceList([$text], $list, false));
    }
}
