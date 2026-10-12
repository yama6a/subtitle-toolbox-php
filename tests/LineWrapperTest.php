<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class LineWrapperTest extends TestCase
{
    public function testWrapsIntoLinesOfEqualLength(): void
    {
        $this->assertSame(["The bakery opens", "at seven tomorrow."],
                          LineWrapper::wrap(["The bakery opens at seven tomorrow."], 20, 2));
    }


    public function testUsesTheFewestLinesThatFit(): void
    {
        $this->assertSame(["one two", "three four"], LineWrapper::wrap(["one two three four"], 10, 3));
    }


    public function testBreaksCjkTextWithoutSpacesBetweenCharacters(): void
    {
        $this->assertSame(["東京は今日とても", "暑いですね本当に"], LineWrapper::wrap(["東京は今日とても暑いですね本当に"], 10, 2));
        $this->assertSame(["我们明天早上八点在火车站见面，", "然后一起坐车去北京看望奶奶。"],
                          LineWrapper::wrap(["我们明天早上八点在火车站见面，然后一起坐车去北京看望奶奶。"], 16, 2));
    }


    public function testPrefersABreakAfterCjkPunctuationToEqualLines(): void
    {
        $this->assertSame(["今天的会议改到下午三点，", "请大家提前十分钟到三楼的会议室。"],
                          LineWrapper::wrap(["今天的会议改到下午三点，请大家提前十分钟到三楼的会议室。"], 16, 2));
        $this->assertSame(["朝ごはんが一日続く　", "公園はまだ温かい"], LineWrapper::wrap(["朝ごはんが一日続く　公園はまだ温かい"], 10, 2));
    }


    public function testNeverStartsALineWithClosingPunctuationSmallKanaOrTheLongVowelMark(): void
    {
        $this->assertSame(["スーパー", "マーケットへ"], LineWrapper::wrap(["スーパーマーケットへ"], 5, 2));
        $this->assertSame(["ちょっ", "と"], LineWrapper::wrap(["ちょっと"], 3, 2));
        $this->assertSame(["你好", "吗？"], LineWrapper::wrap(["你好吗？"], 3, 2));
        $this->assertSame(["「あ」"], LineWrapper::wrap(["「あ」"], 1, 3));
    }


    public function testNeverEndsALineWithOpeningPunctuation(): void
    {
        $this->assertSame(["我们一起读", "《红楼梦》好吗"], LineWrapper::wrap(["我们一起读《红楼梦》好吗"], 6, 2));
        $this->assertSame(["彼女は", "「はい」"], LineWrapper::wrap(["彼女は「はい」"], 3, 2));
    }


    public function testKeepsLinesOfOnlyPunctuationWhole(): void
    {
        $this->assertSame(["！？！？……"], LineWrapper::wrap(["！？！？……"], 2, 3));
        $this->assertSame(["。。。。"], LineWrapper::wrap(["。。。。"], 2, 2));
        $this->assertSame(["「」"], LineWrapper::wrap(["「」"], 1, 2));
    }


    public function testBreaksLatinWordsInCjkTextOnlyAtSpaces(): void
    {
        $this->assertSame(["明天的会议用Zoom开，", "链接我稍后发到群里。"], LineWrapper::wrap(["明天的会议用Zoom开，链接我稍后发到群里。"], 12, 2));
        $this->assertSame(["我们用WhatsApp联系吧"], LineWrapper::wrap(["我们用WhatsApp联系吧"], 2, 1));
        $this->assertSame(["我们", "用WhatsApp联", "系吧"], LineWrapper::wrap(["我们用WhatsApp联系吧"], 2, 3));
        $this->assertSame(["新しい Windows", "の設定が分からない"], LineWrapper::wrap(["新しい Windows の設定が分からない"], 14, 2));
    }


    public function testBreaksKoreanTextOnlyAtSpaces(): void
    {
        $this->assertSame(["내일 아침 여덟 시에", "기차역에서 만나요"], LineWrapper::wrap(["내일 아침 여덟 시에 기차역에서 만나요"], 12, 2));
        $this->assertSame(["기차역에서만나요기차역에서만나요"], LineWrapper::wrap(["기차역에서만나요기차역에서만나요"], 8, 2));
    }


    public function testKeepsTagsWithTheirCharactersAtABreakBetweenCjkCharacters(): void
    {
        $this->assertSame(["<i>东京</i>", "<b>大阪</b>"], LineWrapper::wrap(["<i>东京</i><b>大阪</b>"], 2, 2));
        $this->assertSame(["<i>我们明天早上八点在火车站见面，</i>", "<i>然后一起坐车去北京。</i>"],
                          LineWrapper::wrap(["<i>我们明天早上八点在火车站见面，然后一起坐车去北京。</i>"], 16, 2));
    }


    public function testJoinsCjkLinesWithoutASpace(): void
    {
        $this->assertSame(["这个周末我们一家人打算开车去海", "边度假，顺便看看那边的老朋友。"],
                          LineWrapper::wrap(["这个周末我们一家人打算开车去海边度假，", "顺便看看那边的老朋友。"], 16, 2));
        $this->assertSame(["我们明天早上八点在火车站见面，然后一起坐车去北京。", "- 好的。"],
                          LineWrapper::unwrapTurns(["我们明天早上八点在火车站见面，", "然后一起坐车去北京。", "- 好的。"]));
        $this->assertSame(["用Zoom 开会", "東京 Tokyo"], [...LineWrapper::unwrapTurns(["用Zoom", "开会"]), ...LineWrapper::unwrapTurns(["東京", "Tokyo"])]);
    }


    public function testBreaksCjkTextAtSpaces(): void
    {
        $this->assertSame(["東京は 今日とても", "暑いですね 本当に"], LineWrapper::wrap(["東京は 今日とても 暑いですね 本当に"], 10, 2));
        $this->assertSame(18, LineWrapper::visibleCharacters(["東京は 今日とても", "暑いですね 本当に"]));
    }


    public function testKeepsAWordLongerThanTheLineWhole(): void
    {
        $lines = LineWrapper::wrap(["Supercalifragilisticexpialidocious is long"], 10, 2);

        $this->assertSame(["Supercalifragilisticexpialidocious", "is long"], $lines);
        $this->assertFalse(LineWrapper::fits($lines, 10, 2));
        $this->assertNull(LineWrapper::wrapToFit(["Supercalifragilisticexpialidocious is long"], 10, 2));
    }


    public function testClosesAndReopensItalicTagsAtTheLineBreak(): void
    {
        $this->assertSame(["<i>Are you coming</i>", "<i>to the party?</i>"],
                          LineWrapper::wrap(["<i>Are you coming to the party?</i>"], 16, 2));
    }


    public function testTagsCountNoCharactersAndEntitiesCountOne(): void
    {
        $this->assertSame([["text" => "<i>Tom", "length" => 3], ["text" => "&amp;</i>", "length" => 1], ["text" => "Jerry", "length" => 5]],
                          LineWrapper::measuredWords("<i>Tom &amp;</i> Jerry"));
        $this->assertTrue(LineWrapper::fits(['<font color="#ffff00">Tom &amp; Jerry</font>'], 11, 1));
        $this->assertFalse(LineWrapper::fits(['<font color="#ffff00">Tom &amp; Jerry</font>'], 10, 1));
        $this->assertSame(4, LineWrapper::visibleCharacters(["<i>ab</i>", "c&amp;"]));
    }


    public function testJoinsDialogueLinesOnlyWhenAskedToKeepThem(): void
    {
        $lines = ["- Are you coming?", "- Yes, I am on my way now."];

        $this->assertSame($lines, LineWrapper::wrap($lines, 30, 2, true));
        $this->assertSame(["- Are you coming? -", "Yes, I am on my way now."], LineWrapper::wrap($lines, 30, 2));
    }


    public function testWrapToFitReturnsNullWhenDialogueLinesBreakALimit(): void
    {
        $this->assertSame(["- Hi.", "- Yes?"], LineWrapper::wrapToFit(["- Hi.", "- Yes?"], 20, 2));
        $this->assertNull(LineWrapper::wrapToFit(["- Are you coming?", "- Yes, I am on my way now."], 20, 2));
        $this->assertNull(LineWrapper::wrapToFit(["- Hi.", "- Yes?", "- No."], 20, 2));
    }


    public function testJoinsContinuedLinesBeforeWrapping(): void
    {
        $this->assertSame(["- We left early", "and came back late."],
                          LineWrapper::wrapToFit(["- We left", "early and came back late."], 20, 2));
    }


    public function testReturnsNoLinesForEmptyText(): void
    {
        $this->assertSame([], LineWrapper::wrap(["", " "], 20, 2));
        $this->assertSame([], LineWrapper::wrapToFit([""], 20, 2));
    }


    public function testCharactersPerSecondIsInfiniteWithoutDuration(): void
    {
        $this->assertSame(10.0, LineWrapper::charactersPerSecond(20, 2.0));
        $this->assertSame(INF, LineWrapper::charactersPerSecond(20, 0.0));
        $this->assertSame(INF, LineWrapper::charactersPerSecond(20, -1.0));
    }


    public function testVisibleLineLengthsSkipLinesWithoutVisibleText(): void
    {
        $this->assertSame([5, 3], LineWrapper::visibleLineLengths(["<i>Hello</i>", "<b></b>", "", "a b"]));
    }


    public function testFitsCharactersPerSecond(): void
    {
        $this->assertTrue(LineWrapper::fitsCharactersPerSecond(["<i>Hello</i>"], 1.0, null));
        $this->assertTrue(LineWrapper::fitsCharactersPerSecond(["<i>Hello</i>"], 1.0, 5.0));
        $this->assertFalse(LineWrapper::fitsCharactersPerSecond(["<i>Hello</i>"], 1.0, 4.9));
        $this->assertTrue(LineWrapper::fitsCharactersPerSecond(["<i></i>"], 0.0, 1.0));
        $this->assertFalse(LineWrapper::fitsCharactersPerSecond(["Hi"], 0.0, 1000.0));
    }
}
