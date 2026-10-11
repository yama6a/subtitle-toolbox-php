<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class MarkupColorsTest extends TestCase
{
    public function testFontColorReadsTheColorAsWrittenWithAnyQuotesAndCase(): void
    {
        $this->assertSame("#FF0000", MarkupColors::fontColor(" color=\"#FF0000\""));
        $this->assertSame("#FF0000", MarkupColors::fontColor(" color='#FF0000'"));
        $this->assertSame("red", MarkupColors::fontColor("<font color=red>"));
        $this->assertSame(" #ff0000 ", MarkupColors::fontColor(" face=\"Arial\" COLOR = \" #ff0000 \""));
        $this->assertSame("&quot;x", MarkupColors::fontColor(" color='&quot;x'"));
        $this->assertNull(MarkupColors::fontColor(" face=\"Arial\""));
        $this->assertNull(MarkupColors::fontColor(" bgcolor=\"#ff0000\""));
    }


    public function testBgrAndRgbSwapTheFirstAndLastByte(): void
    {
        $this->assertSame("FF8000", MarkupColors::bgrToRgb("0080FF"));
        $this->assertSame("0080ff", MarkupColors::rgbToBgr("ff8000"));
        $this->assertSame("123456", MarkupColors::rgbToBgr(MarkupColors::bgrToRgb("123456")));
    }


    public function testColorHexReadsEveryColorSpellingAsLowerCaseRrggbb(): void
    {
        $this->assertSame("#ffff00", MarkupColors::colorHex("#FF0"));
        $this->assertSame("#ffff00", MarkupColors::colorHex(" Yellow "));
        $this->assertSame("#ffff00", MarkupColors::colorHex("#FFFF0080"));
        $this->assertSame("#ffff00", MarkupColors::colorHex("&#35;ff0"));
        $this->assertSame("#ff8000", MarkupColors::colorHex("rgb( 255 , 128 , 0 )"));
        $this->assertSame("#ff8000", MarkupColors::colorHex("RGBA(255,128,0,0.5)"));
        $this->assertSame("#00ffff", MarkupColors::colorHex("aqua"));
        $this->assertNull(MarkupColors::colorHex("rgb(256,0,0)"));
        $this->assertNull(MarkupColors::colorHex("transparent"));
        $this->assertNull(MarkupColors::colorHex("#ff00"));
        $this->assertNull(MarkupColors::colorHex(""));
    }


    public function testColorHexWithAlphaKeepsTheAlphaChannel(): void
    {
        $this->assertSame("#ff000080", MarkupColors::colorHexWithAlpha("#FF000080"));
        $this->assertSame("#ff000080", MarkupColors::colorHexWithAlpha("rgba(255, 0, 0, 0.5)"));
        $this->assertSame("#ff000080", MarkupColors::colorHexWithAlpha("rgba(255,0,0,50%)"));
        $this->assertSame("#ff000080", MarkupColors::colorHexWithAlpha("RGBA(255,0,0,128)"));
        $this->assertSame("#00000000", MarkupColors::colorHexWithAlpha("rgba(0,0,0,0)"));
        $this->assertSame("#ff0000", MarkupColors::colorHexWithAlpha("#ff0000zz"));
        $this->assertSame("#ff0000", MarkupColors::colorHexWithAlpha("red"));
        $this->assertNull(MarkupColors::colorHexWithAlpha("rgba(255,0,0,1.5)"));
    }


    public function testTtmlColorKeepsAValidTtmlColorAndConvertsOtherColors(): void
    {
        $this->assertSame("rgba(0,0,0,0)", MarkupColors::ttmlColor("rgba(0,0,0,0)"));
        $this->assertSame("#FFFF0080", MarkupColors::ttmlColor("#FFFF0080"));
        $this->assertSame("transparent", MarkupColors::ttmlColor("Transparent"));
        $this->assertSame("yellow", MarkupColors::ttmlColor("Yellow"));
        $this->assertSame("#ff000080", MarkupColors::ttmlColor("rgba(255, 0, 0, 0.5)"));
        $this->assertSame("#ffff00", MarkupColors::ttmlColor("#FF0"));
        $this->assertNull(MarkupColors::ttmlColor("grey"));
    }
}
