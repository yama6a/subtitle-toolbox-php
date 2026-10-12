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


    public function testEditMethodsHaveTheNewNames(): void
    {
        $this->assertFalse(method_exists(Subtitle::class, "merge"));
        $this->assertTrue(method_exists(Subtitle::class, "append"));
        $this->assertSame(["seconds", "from", "to"], array_map(fn ($parameter) => $parameter->name, (new \ReflectionMethod(Subtitle::class, "shift"))->getParameters()));
        $this->assertSame("timeTolerance", (new \ReflectionMethod(Subtitle::class, "mergeSameTimeCues"))->getParameters()[0]->name);
        $this->assertTrue(property_exists(Dual\DualSubtitleOptions::class, "timeTolerance"));
        $this->assertTrue(property_exists(Diff\SubtitleDiffOptions::class, "timeTolerance"));
    }
}
