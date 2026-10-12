<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class RenamedMethodsTest extends TestCase
{
    public function testFindMethodsReturnNullWhenThereIsNoValue(): void
    {
        $this->assertNull((new Subtitle())->findFormat());
        $this->assertNull((new SubtitleCue(0, 1, "text"))->findIdentifier());
        $this->assertNull(Format::tryFromPath("movie.unknown"));
        $this->assertSame(Format::SubRip, Format::tryFromPath("movie.srt"));
    }


    public function testOldNamesAreGone(): void
    {
        $this->assertFalse(method_exists(Subtitle::class, "getFormat"));
        $this->assertFalse(method_exists(SubtitleCue::class, "getIdentifier"));
        $this->assertFalse(method_exists(Format::class, "fromPath"));
    }
}
