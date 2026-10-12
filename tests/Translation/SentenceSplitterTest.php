<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use PHPUnit\Framework\TestCase;

class SentenceSplitterTest extends TestCase
{
    /**
     * @param list<int>           $weights
     * @param array<int, TagPair> $tags
     * @return list<string>
     */
    private static function pieces(string $translation, array $weights, array $tags = []): array
    {
        return array_map(fn (array $piece): string => PlaceholderTags::restore($piece, $tags),
                         SentenceSplitter::split(PlaceholderTags::tokenize($translation), $weights));
    }


    public function testChineseTextWithoutSpacesIsCutBetweenCharactersAndKeepsItsTags(): void
    {
        $this->assertSame(["<i>我们明天</i>", "<i>早上去北京</i>"], self::pieces("<x1>我们明天早上去北京</x1>", [3, 3], [1 => new TagPair("<i>", "</i>")]));
    }


    public function testWeightsThatAskForMoreCutsThanSpacesLeaveTheLastPieceEmpty(): void
    {
        $this->assertSame(["Bonjour", "tout", ""], self::pieces("Bonjour tout", [1, 1, 1]));
    }
}
