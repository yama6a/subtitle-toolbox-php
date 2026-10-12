<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use PHPUnit\Framework\TestCase;

class PlaceholderTagsTest extends TestCase
{
    public function testANestedPairGetsTwoPairsOfPlaceholdersAndComesBack(): void
    {
        $encoded = PlaceholderTags::encode("<i>Run, <b>now</b>!</i>");

        $this->assertSame("<x1>Run, <x2>now</x2>!</x1>", $encoded->text);
        $this->assertEquals([1 => new TagPair("<i>", "</i>"), 2 => new TagPair("<b>", "</b>")], $encoded->tags);
        $this->assertTrue(PlaceholderTags::keepsPlaceholders("<x1>Lauf, <x2>jetzt</x2>!</x1>", $encoded->tags));
        $this->assertSame("<i>Lauf, <b>jetzt</b>!</i>",
                          PlaceholderTags::restore(PlaceholderTags::tokenize("<x1>Lauf, <x2>jetzt</x2>!</x1>"), $encoded->tags));
    }


    public function testASelfClosingTagAndWordTimestampsGetSinglePlaceholders(): void
    {
        $encoded = PlaceholderTags::encode("<00:00:01.000>Hello <00:00:01.500>world<br/>");

        $this->assertSame("<x1/>Hello <x2/>world<x3/>", $encoded->text);
        $this->assertEquals([1 => new TagPair("<00:00:01.000>", null), 2 => new TagPair("<00:00:01.500>", null), 3 => new TagPair("<br/>", null)],
                            $encoded->tags);
        $this->assertSame("<00:00:01.000>Hallo <00:00:01.500>Welt<br/>",
                          PlaceholderTags::restore(PlaceholderTags::tokenize("<x1/>Hallo <x2/>Welt<x3/>"), $encoded->tags));
    }


    public function testAMissingOrSwappedPlaceholderIsNotKept(): void
    {
        $tags = PlaceholderTags::encode("<i>Run</i>")->tags;

        $this->assertFalse(PlaceholderTags::keepsPlaceholders("Lauf", $tags));
        $this->assertFalse(PlaceholderTags::keepsPlaceholders("</x1>Lauf<x1>", $tags));
        $this->assertFalse(PlaceholderTags::keepsPlaceholders("<x1>Lauf< /x1>", $tags));
        $this->assertSame("Lauf", PlaceholderTags::remove("<x1>Lauf< /x1>"));
    }
}
