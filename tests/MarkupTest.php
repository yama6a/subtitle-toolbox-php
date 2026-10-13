<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkupTest extends TestCase
{
    #[DataProvider("rubyProvider")]
    public function testRubyAsTextWritesBaseAndAnnotation(string $text, string $expected): void
    {
        $this->assertSame($expected, Markup::rubyAsText($text));
    }


    public static function rubyProvider(): array
    {
        return [
            "one annotation"          => ["<ruby>漢<rt>kan</rt></ruby>", "漢 (kan)"],
            "two annotations"         => ["<ruby>漢<rt>kan</rt>字<rt>ji</rt></ruby>", "漢 (kan)字 (ji)"],
            "rp elements are dropped" => ["<ruby>漢<rp>(</rp><rt>kan</rt><rp>)</rp></ruby>", "漢 (kan)"],
            "rt end tag left out"     => ["<ruby>漢<rt>kan</ruby> end", "漢 (kan) end"],
            "class and space in base" => ["<ruby.big>ruby <rt>rubyText</rt></ruby>text", "ruby (rubyText)text"],
            "empty annotation"        => ["<ruby>漢<rt></rt></ruby>", "漢"],
            "no ruby"                 => ["<i>a</i> &lt;ruby&gt;", "<i>a</i> &lt;ruby&gt;"],
        ];
    }


    public function testStripAllTagsRemovesEveryTag(): void
    {
        $this->assertSame(
            "Fred: Hello world now",
            Markup::stripAllTags('<v Fred>Fred: <b>Hello</b> <font color="#ff0000">world</font> <00:01:02.500>now</v>')
        );
    }


    public function testStripAllTagsKeepsEscapedText(): void
    {
        $this->assertSame("&lt;b&gt;1 &amp; 2&lt;/b&gt;", Markup::stripAllTags("<i>&lt;b&gt;1 &amp; 2&lt;/b&gt;</i>"));
    }


    public function testKeepTagsKeepsOnlyTheGivenTags(): void
    {
        $this->assertSame(
            '<b>bold</b> <font color="#ff0000">red</font> struck',
            Markup::keepTags('<b>bold</b> <font color="#ff0000">red</font> <s>struck</s>', ["b", "font"])
        );
    }


    public function testKeepTagsWithCoreTagsKeepsCoreMarkup(): void
    {
        $text = '<v Fred><b>b</b><i>i</i><u>u</u><s>s</s><font color="#ff0000">c</font></v>';

        $this->assertSame($text . "x", Markup::keepTags($text . "<c.yellow>x</c>", Markup::CORE_TAGS));
    }


    public function testKeepTagsWithEmptyListStripsAllTags(): void
    {
        $this->assertSame("bold", Markup::keepTags("<b>bold</b>", []));
    }


    public function testStripAllTagsKeepsTextAfterQuotesInTags(): void
    {
        $this->assertSame("Hi.", Markup::stripAllTags("<v O'Neil>Hi.</v>"));
        $this->assertSame("We're out.", Markup::stripAllTags("<v O'Neil>We're out."));
        $this->assertSame("Two rolls.", Markup::stripAllTags('<v Mo "Baker>Two rolls.'));
        $this->assertSame('Take the "seeded" loaf.', Markup::stripAllTags('<v Sam "Ace" Reed>Take the "seeded" loaf.'));
        $this->assertSame("Red jam", Markup::stripAllTags("<font color='#ff0000'>Red</font> jam"));
        $this->assertSame("It's 'fine', \"really\"", Markup::stripAllTags("It's <i>'fine'</i>, \"really\""));
    }


    public function testStripAllTagsKeepsALessThanSignThatStartsNoTag(): void
    {
        $this->assertSame("I <3 bread", Markup::stripAllTags("I <3 bread"));
        $this->assertSame("a < b", Markup::stripAllTags("a < b"));
    }


    public function testKeepTagsKeepsTextAfterQuotesInTags(): void
    {
        $this->assertSame("<i>Hi.</i>", Markup::keepTags("<v O'Neil><i>Hi.</i>", ["i"]));
        $this->assertSame("<v O'Neil>We're out.", Markup::keepTags("<v O'Neil>We're out.", ["v"]));
        $this->assertSame('<v Mo "Baker>Two rolls.', Markup::keepTags('<v Mo "Baker>Two rolls.', Markup::CORE_TAGS));
        $this->assertSame("<font color='#ff0000'>Red</font> jam", Markup::keepTags("<font color='#ff0000'>Red</font> <s>jam</s>", ["font"]));
        $this->assertSame("It's x", Markup::keepTags("It's <c.loud>x</c>", ["i"]));
    }


    public function testKeepTagsComparesTagNamesWithoutCase(): void
    {
        $this->assertSame("<B>bold</B> <c.Loud>x</c.Loud> y", Markup::keepTags("<B>bold</B> <c.Loud>x</c.Loud> <i>y</i>", ["b", "C.loud"]));
    }


    public function testPlainLinesAndVisibleLengthKeepTextAfterQuotesInTags(): void
    {
        $this->assertSame(["We're out."], Markup::plainLines(["<v O'Neil>We're out.", "<i></i>"]));
        $this->assertSame(10, Markup::visibleLength("<v O'Neil>We're out."));
    }


    public function testDecodeEntities(): void
    {
        $this->assertSame("<b>1 & 2</b> \"it's\"", Markup::decodeEntities("&lt;b&gt;1 &amp; 2&lt;/b&gt; &quot;it&#39;s&quot;"));
        $this->assertSame("Caf\u{e9} \u{a0}", Markup::decodeEntities("Caf&eacute; &nbsp;"));
        $this->assertSame("plain & simple", Markup::decodeEntities("plain & simple"));
    }


    public function testStripThenDecodeKeepsEscapedTextAsText(): void
    {
        $this->assertSame("<b> is bold", Markup::decodeEntities(Markup::stripAllTags("<i>&lt;b&gt;</i> is bold")));
    }


    public function testEscapeText(): void
    {
        $this->assertSame("&lt;b&gt;1 &amp; 2&lt;/b&gt;", Markup::escapeText("<b>1 & 2</b>"));
        $this->assertSame("&amp;amp; stays text", Markup::escapeText("&amp; stays text"));
        $this->assertSame("\"it's\" Caf\u{e9}", Markup::escapeText("\"it's\" Caf\u{e9}"));
        $this->assertSame("", Markup::escapeText(""));
    }


    public function testEscapeTextKeepsLatin1Bytes(): void
    {
        $this->assertSame("caf\xE9 &amp; &lt;b&gt;", Markup::escapeText("caf\xE9 & <b>"));
    }


    public function testEscapeThenDecodeRoundTrips(): void
    {
        $text = "<b>1 & 2</b> &amp;";

        $this->assertSame($text, Markup::decodeEntities(Markup::stripAllTags(Markup::escapeText($text))));
    }


    public function testVisibleLengthLeavesOutTagsAndOuterSpacesAndCountsEntitiesAsOne(): void
    {
        $this->assertSame(11, Markup::visibleLength(" <i>Gr\u{fc}\u{df}e &amp; </i><00:00:01.000>Tee "));
        $this->assertSame(0, Markup::visibleLength("<b></b> "));
    }


    public function testCountCharactersCountsCharactersAsReadersSeeThemOrBytesOfInvalidUtf8(): void
    {
        $this->assertSame(4, Markup::countCharacters("Caf\u{e9}"));
        $this->assertSame(4, Markup::countCharacters("Cafe\u{301}"));
        $this->assertSame(3, Markup::countCharacters("\u{1F44D}\u{1F3FD} \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"));
        $this->assertSame(2, Markup::countCharacters("\u{202B}\u{05D0}\u{05D1}\u{202C}"));
        $this->assertSame(0, Markup::countCharacters("\u{200E}\u{200F}\u{202A}\u{202D}\u{202E}\u{2066}\u{2069}\u{200B}\u{FEFF}\u{00AD}"));
        $this->assertSame(2, Markup::countCharacters("\u{4f60}\u{597d}"));
        $this->assertSame(4, Markup::countCharacters("Caf\xe9"));
        $this->assertSame(0, Markup::countCharacters(""));
    }


    public function testWordTimestampRegexSplitsOnCoreWordTimestamps(): void
    {
        $this->assertSame(
            ["<b>One</b> ", "<00:00:01.500>", "two ", "<100:59:59.999>", ""],
            preg_split(Markup::WORD_TIMESTAMP_REGEX, "<b>One</b> <00:00:01.500>two <100:59:59.999>", -1, PREG_SPLIT_DELIM_CAPTURE)
        );
        $this->assertSame(0, preg_match(Markup::WORD_TIMESTAMP_REGEX, "<01:02.500> <00:60:00.000> <00:00:01.50> <0:00:01.500>"));
    }


    public function testCoreTimestampFormatsSecondsWithRoundedMilliseconds(): void
    {
        $this->assertSame("00:00:00.000", Markup::coreTimestamp(0.0));
        $this->assertSame("00:01:02.500", Markup::coreTimestamp(62.5));
        $this->assertSame("01:00:00.001", Markup::coreTimestamp(3600.0006));
        $this->assertSame("00:00:02.000", Markup::coreTimestamp(1.9996));
        $this->assertSame("100:00:00.000", Markup::coreTimestamp(360000.0));
    }


    public function testPlainLinesStripsDecodesTrimsAndDropsEmptyLines(): void
    {
        $this->assertSame(
            ["Tom & Jerry", "<not a tag>", "Caf\u{e9}"],
            Markup::plainLines([" <i>Tom &amp; Jerry</i> ", "<b></b>", "&lt;not a tag&gt;", "  ", "Caf&eacute;<00:00:01.000>"])
        );
        $this->assertSame([], Markup::plainLines([]));
    }


    public function testUnescapeTextDecodesOnlyTheEntitiesOfEscapeText(): void
    {
        $this->assertSame("<b> & > &eacute; &amp;", Markup::unescapeText("&lt;b&gt; &amp; &gt; &eacute; &amp;amp;"));
        $this->assertSame("1 < 2 & 3", Markup::unescapeText(Markup::escapeText("1 < 2 & 3")));
    }


    public function testEscapeTextLikeKeepsAmpersandsAndGreaterThanUnescapedWhereTheRawRunDoes(): void
    {
        $this->assertSame("Tom &amp; Jerry &gt; 2", Markup::escapeTextLike("Tom & Jerry > 2", "Tom &amp; Jerry &gt; 1"));
        $this->assertSame("Tom & Jerry > 2", Markup::escapeTextLike("Tom & Jerry > 2", "Tom & Jerry > 1"));
        $this->assertSame("&amp;eacute; & &lt;", Markup::escapeTextLike("&eacute; & <", "a & b"));
    }


    public function testSplitTagsPutsTextAtEvenAndTagsAtOddIndexes(): void
    {
        $this->assertSame(
            ["", "<i>", "Hello ", "<00:00:01.000>", "world", "</i>", " &lt;3"],
            Markup::splitTags("<i>Hello <00:00:01.000>world</i> &lt;3")
        );
        $this->assertSame(["a < b"], Markup::splitTags("a < b"));
    }


    public function testMapTextRunsMapsDecodedTextBetweenTagsAndMarksTheFirstAndLastRun(): void
    {
        $calls = [];
        $lines = Markup::mapTextRuns(["<i>a &amp; b</i> c", "<b></b>d"], function (string $text, bool $first, bool $last) use (&$calls): string {
            $calls[] = [$text, $first, $last];

            return strtoupper($text) . "<";
        });

        $this->assertSame(["<i>A &amp; B&lt;</i> C&lt;", "<b></b>D&lt;"], $lines);
        $this->assertSame([["a & b", true, false], [" c", false, true], ["d", true, true]], $calls);
    }


    public function testMapTextRunsKeepsUnchangedRunsAsWritten(): void
    {
        $this->assertSame(["<i>Tom & Jerry</i>"], Markup::mapTextRuns(["<i>Tom & Jerry</i>"], fn (string $text): string => $text));
    }


    public function testHasVisibleTextIgnoresTagsAndWhiteSpace(): void
    {
        $this->assertFalse(Markup::hasVisibleText(["<i> </i>", "", "<00:00:01.000>"]));
        $this->assertTrue(Markup::hasVisibleText(["<i></i>", "<b>&nbsp;</b>"]));
        $this->assertFalse(Markup::hasVisibleText([]));
    }


    public function testHasVisibleTextAndKeepTagsReadALessThanSignBeforeASpaceAsText(): void
    {
        $this->assertTrue(Markup::hasVisibleText(["a < b > c"]));
        $this->assertTrue(Markup::hasVisibleText(["< b>"]));
        $this->assertSame("a < b > c", Markup::keepTags("a < b > c", []));
        $this->assertSame("a < b > c", Markup::keepTags("a < b > c", ["b"]));
    }


    public function testPlainTextRemovesTagsAndDecodesEntitiesWithoutTrimming(): void
    {
        $this->assertSame(" Tom & Jerry <3 ", Markup::plainText(" <i>Tom &amp; Jerry</i> &lt;3<00:00:01.000> "));
    }


    public function testCharactersSplitsUtf8OrBytesOfInvalidUtf8(): void
    {
        $this->assertSame(["C", "a", "f", "é"], Markup::characters("Café"));
        $this->assertSame(["C", "a", "f", "\xE9"], Markup::characters("Caf\xE9"));
        $this->assertSame([], Markup::characters(""));
    }


    public function testWordsSplitsAtWhiteSpaceAlsoInInvalidUtf8(): void
    {
        $this->assertSame(["Hello,", "world!", "Bye"], Markup::words(" Hello,\tworld!\nBye "));
        $this->assertSame(["Caf\xE9", "au", "lait"], Markup::words("Caf\xE9 au  lait"));
        $this->assertSame([], Markup::words("  "));
    }


    public function testToSingleLineJoinsLinesWithOneSpace(): void
    {
        $this->assertSame("Line one Line two", Markup::toSingleLine("  Line one  \r\n  Line two\n"));
    }


    public function testOpenCoreTagsTracksTheCoreTagsThatStayOpen(): void
    {
        $open = Markup::openCoreTags('<I>a <font color="#ff0000">b <c.x>c</c> <b>d</b>');

        $this->assertSame([["name" => "i", "tag" => "<I>"], ["name" => "font", "tag" => '<font color="#ff0000">']], $open);
        $this->assertSame([["name" => "i", "tag" => "<I>"]], Markup::openCoreTags("e</font>", $open));
        $this->assertSame([], Markup::openCoreTags("</u>plain"));
    }


    public function testCloseCoreTagsClosesTheLastOpenedTagFirst(): void
    {
        $this->assertSame("</font></i>", Markup::closeCoreTags(Markup::openCoreTags('<i>a <font color="#ff0000">b')));
        $this->assertSame("", Markup::closeCoreTags([]));
    }


    public function testWordTimestampSecondsReadsCoreWordTimestamps(): void
    {
        $this->assertSame(3723.5, Markup::wordTimestampSeconds("<01:02:03.500>"));
        $this->assertSame(360000.0, Markup::wordTimestampSeconds("<100:00:00.000>"));
        $this->assertNull(Markup::wordTimestampSeconds("<00:60:00.000>"));
        $this->assertNull(Markup::wordTimestampSeconds("<i>"));
        $this->assertNull(Markup::wordTimestampSeconds("x<00:00:01.000>"));
    }


    public function testVoiceTagEscapesTheNameAndKeepsQuotes(): void
    {
        $this->assertSame("<v Ann &amp; Bo>", Markup::voiceTag("Ann & Bo"));
        $this->assertSame("<v.loud O'Neil \"Jr\" &lt;3>", Markup::voiceTag("O'Neil \"Jr\" <3", ".loud"));
    }


    public function testInsertWordTimestampsEscapesTextAndTimesTheWordsItFinds(): void
    {
        $this->assertSame(
            "<00:00:01.000>Tom &amp; <00:00:01.500>Tom <00:00:02.250>left",
            Markup::insertWordTimestamps("Tom & Tom left", [["Tom", 1.0], ["Tom", 1.5], ["", 2.0], ["missing", 2.1], ["left", 2.25]])
        );
        $this->assertSame("a &lt; b", Markup::insertWordTimestamps("a < b", [["a", null], ["b", null]]));
    }


    public function testTagNameIsTheFirstWordInLowerCase(): void
    {
        $this->assertSame("font", Markup::tagName(' FONT color="yellow"'));
        $this->assertSame("c.yellow", Markup::tagName("c.yellow"));
    }


    public function testNamedTagRegexMatchesOpeningAndClosingTagsOfTheNamesOnly(): void
    {
        preg_match_all(Markup::namedTagRegex(["b", "c"]), "<B>a</b> <c.yellow>b</c> <br> <bold>c</bold>", $tags, PREG_SET_ORDER);

        $this->assertSame([["<B>", "", "B"], ["</b>", "/", "b"], ["<c.yellow>", "", "c"], ["</c>", "/", "c"]], $tags);
    }
}
