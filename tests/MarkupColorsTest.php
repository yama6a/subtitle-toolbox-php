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
}
