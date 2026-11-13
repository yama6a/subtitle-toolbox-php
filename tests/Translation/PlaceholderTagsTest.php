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


    public function testASelfClosingTagGetsASinglePlaceholder(): void
    {
        $encoded = PlaceholderTags::encode("Hello <br/>world<br/>");

        $this->assertSame("Hello <x1/>world<x2/>", $encoded->text);
        $this->assertEquals([1 => new TagPair("<br/>", null), 2 => new TagPair("<br/>", null)], $encoded->tags);
        $this->assertSame("Hallo <br/>Welt<br/>", PlaceholderTags::restore(PlaceholderTags::tokenize("Hallo <x1/>Welt<x2/>"), $encoded->tags));
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
