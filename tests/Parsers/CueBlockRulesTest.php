<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;

class CueBlockRulesTest extends TestCase
{
    public function testSplitsABlockOfTwoCuesWithoutAnEmptyLine(): void
    {
        $block = self::firstBlock("missing_empty_line.srt");

        self::assertSame(
            [
                0 => ["1", "00:00:01,000 --> 00:00:02,500", "The wind is cold today."],
                3 => ["2", "00:00:03,000 --> 00:00:05,000", "Snow falls in the hills."],
                6 => ["00:00:05,500 --> 00:00:07,000", "The roads are closed."],
            ],
            self::subRipRules()->splitAtTimingLines($block)
        );
    }


    public function testKeepsTheCueNumberWithTheTimingLineAfterIt(): void
    {
        $block = ["7", "00:00:01,000 --> 00:00:02,000", "Hello", "8", "00:00:03,000 --> 00:00:04,000", "World"];

        self::assertSame([0, 3], array_keys(self::subRipRules()->splitAtTimingLines($block)));
    }


    public function testANumericLineRightBeforeATimingLineStartsTheNextCue(): void
    {
        $block = ["1", "00:00:01,000 --> 00:00:02,000", "42", "00:00:03,000 --> 00:00:04,000", "Text"];

        self::assertSame(
            [
                0 => ["1", "00:00:01,000 --> 00:00:02,000"],
                2 => ["42", "00:00:03,000 --> 00:00:04,000", "Text"],
            ],
            self::subRipRules()->splitAtTimingLines($block)
        );
    }


    public function testKeepsANumericTextLineAfterATimingLine(): void
    {
        $block = ["1", "00:00:01,000 --> 00:00:02,000", "42", "Text"];

        self::assertSame([0 => $block], self::subRipRules()->splitAtTimingLines($block));
    }


    public function testSplitsTwoTimingLinesInARow(): void
    {
        $block = ["1", "00:00:01,000 --> 00:00:02,000", "00:00:03,000 --> 00:00:04,000", "Text"];

        self::assertSame(
            [
                0 => ["1", "00:00:01,000 --> 00:00:02,000"],
                2 => ["00:00:03,000 --> 00:00:04,000", "Text"],
            ],
            self::subRipRules()->splitAtTimingLines($block)
        );
    }


    public function testWebVttRulesHaveNoCueNumbers(): void
    {
        $block = ["00:00:01.000 --> 00:00:02.500", "The wind is cold today.", "2", "00:00:03.000 --> 00:00:05.000", "Snow"];

        self::assertSame(
            [
                0 => ["00:00:01.000 --> 00:00:02.500", "The wind is cold today.", "2"],
                3 => ["00:00:03.000 --> 00:00:05.000", "Snow"],
            ],
            self::webVttRules()->splitAtTimingLines($block)
        );
        self::assertTrue(self::webVttRules()->isCueTextBlock(["2"]));
        self::assertFalse(self::subRipRules()->isCueTextBlock(["2"]));
    }


    public function testFindsCueTextBlocks(): void
    {
        $rules = self::subRipRules();

        self::assertTrue($rules->isCueTextBlock(["Tickets are sold at the door."]));
        self::assertFalse($rules->isCueTextBlock(["00:00:04,000 --> 00:00:06,000", "Text"]));
        self::assertFalse($rules->isCueTextBlock(["00:00:04 -> 00:00:06"]));
        self::assertFalse($rules->isCueTextBlock(["00 :00:04,000 to 00:00:06,000"]));
        self::assertTrue($rules->isCueTextBlock(["Text", "3"]));
    }


    public function testFindsATimingLine(): void
    {
        self::assertTrue(self::subRipRules()->hasTimingLine(["1", "00:00:01,000 --> 00:00:02,000"]));
        self::assertFalse(self::subRipRules()->hasTimingLine(["1", "Text"]));
    }


    private static function subRipRules(): CueBlockRules
    {
        return new CueBlockRules(fn (string $line): bool => preg_match("/^\d+:\d\d:\d\d,\d+ --> /", $line) === 1, true);
    }


    private static function webVttRules(): CueBlockRules
    {
        return new CueBlockRules(fn (string $line): bool => str_contains($line, "-->"), false);
    }


    /**
     * @return list<string>
     */
    private static function firstBlock(string $fixture): array
    {
        $content = (string) file_get_contents(__DIR__ . "/../files/lenient/$fixture");

        return explode("\n", explode("\n\n", $content)[0]);
    }
}
