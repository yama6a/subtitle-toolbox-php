<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class AssTextMarkupTest extends TestCase
{
    private const STYLES = [
        ["Name" => "Default", "Bold" => "0", "Italic" => "0"],
        ["Name" => "Sign", "Bold" => "-1", "Italic" => "0"],
    ];


    public static function tagRules(): array
    {
        return [
            "bold on and off"              => ["{\\b1}bold{\\b0} plain", false, ["<b>bold</b> plain"], null],
            "italic inside bold"           => ["{\\b1}a {\\i1}b{\\b0} c{\\i0}", false, ["<b>a <i>b</i></b><i> c</i>"], null],
            "primary color and reset"      => ["{\\1c&H0000FF&}red{\\c} plain", false, ["<font color=\"#ff0000\">red</font> plain"], null],
            "color replaces color"         => ["{\\c&HFF0000}blue{\\1c&H00FF00&}green", false,
                                               ["<font color=\"#0000ff\">blue</font><font color=\"#00ff00\">green</font>"], null],
            "reset to the event style"     => ["{\\i1}a{\\r}b", false, ["<i>a</i>b"], null],
            "reset to a named style"       => ["a{\\rSign}b{\\r}c", false, ["a<b>b</b>c"], null],
            "karaoke times"                => ["{\\k50}one {\\kf25}two {\\ko10}three", false,
                                               ["<00:00:01.000>one <00:00:01.500>two <00:00:01.750>three"], null],
            "drawing text is dropped"      => ["{\\p1}m 0 0 l 10 0{\\p0}after", false, ["after"], null],
            "hard line break"              => ["one\\Ntwo", false, ["one", "two"], null],
            "soft line break"              => ["one\\ntwo", false, ["one two"], null],
            "soft line break, WrapStyle 2" => ["one\\ntwo", true, ["one", "two"], null],
            "hard space"                   => ["a\\hb", false, ["a\u{00A0}b"], null],
            "first alignment tag wins"     => ["{\\an8}top{\\a1}", false, ["top"], 8],
            "unknown tags are dropped"     => ["{\\pos(10,10)\\fad(100,200)}text", false, ["text"], null],
        ];
    }


    #[DataProvider("tagRules")]
    public function testConvertsTheTagRule(string $text, bool $softBreakIsHard, array $lines, ?int $alignment): void
    {
        $markup = new AssTextMarkup(self::STYLES, fn (float $seconds): float => $seconds);

        $this->assertSame([$lines, $alignment], $markup->convert($text, 1.0, $softBreakIsHard, 3, self::STYLES[0]));
    }


    public function testTheStyleFlagsOpenAtTheStart(): void
    {
        $markup = new AssTextMarkup(self::STYLES, fn (float $seconds): float => $seconds);

        $this->assertSame([["<b>sign</b>"], null], $markup->convert("sign", 1.0, false, 3, self::STYLES[1]));
    }


    public function testAKaraokeTimeGoesThroughTheBound(): void
    {
        $markup = new AssTextMarkup(self::STYLES, function (float $seconds, string $text, ?int $lineNumber): float {
            throw new ParsingException("The time \"$text\" is not below 100000 hours.", $lineNumber);
        });

        $this->expectExceptionMessage("The time \"\\k50\" is not below 100000 hours. (line 3)");
        $markup->convert("{\\k50}one", 1.0, false, 3, null);
    }
}
