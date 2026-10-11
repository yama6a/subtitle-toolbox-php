<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssKaraokeTag;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\WriteOptions;

class WordHighlightTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";

    private const BEACH = "<00:00:00.000>The <00:00:00.240>beach <00:00:00.710>was <00:00:00.950>quiet.";


    private static function whisper(): Subtitle
    {
        return (new WhisperJsonParser())
            ->parse(file_get_contents(self::FILES . "whisper/real/openai_whisper_word_timestamps.json"), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
    }


    private static function expand(Subtitle $subtitle, WordHighlightOptions $options): Subtitle
    {
        WordHighlight::apply($subtitle, $options);

        return $subtitle;
    }


    public function testIssueExample(): void
    {
        $subtitle = TestSubtitles::fromCues([new SubtitleCue(0, 1.6, self::BEACH)]);
        $karaoke  = self::expand($subtitle, new WordHighlightOptions(style: "u"));

        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:00,240\n<u>The</u> beach was quiet.\n\n" .
            "2\n00:00:00,240 --> 00:00:00,710\nThe <u>beach</u> was quiet.\n\n" .
            "3\n00:00:00,710 --> 00:00:00,950\nThe beach <u>was</u> quiet.\n\n" .
            "4\n00:00:00,950 --> 00:00:01,600\nThe beach was <u>quiet.</u>\n",
            $karaoke->toString(Format::SubRip, new WriteOptions(bom: false))
        );
    }


    public function testKeepsTextThatLooksLikeATag(): void
    {
        $karaoke = self::expand(
            TestSubtitles::fromCues([new SubtitleCue(0, 1, "<00:00:00.000>a <00:00:00.500>< b > c")]),
            new WordHighlightOptions(style: "u")
        );

        $this->assertSame(["<u>a</u> < b > c", "a <u>< b > c</u>"], array_column(TestSubtitles::describe($karaoke), 2));
    }


    public function testApplyChangesTheInputAndReportsTheCueCounts(): void
    {
        $subtitle = TestSubtitles::fromCues([new SubtitleCue(0, 1.6, self::BEACH)]);

        $this->assertEquals(new WordHighlightReport(1, 4), WordHighlight::apply($subtitle, new WordHighlightOptions()));
        $this->assertCount(4, $subtitle->getCues());
    }


    public function testCloneKeepsTheOriginal(): void
    {
        $subtitle = TestSubtitles::fromCues([new SubtitleCue(0, 1.6, self::BEACH)]);
        $before   = $subtitle->toArray();

        WordHighlight::apply(clone $subtitle, new WordHighlightOptions());

        $this->assertSame($before, $subtitle->toArray());
    }


    public function testDefaultStyleIsUnderline(): void
    {
        $karaoke = self::expand(TestSubtitles::fromCues([new SubtitleCue(0, 1.6, self::BEACH)]), new WordHighlightOptions());

        $this->assertSame("<u>The</u> beach was quiet.", $karaoke->getCues()[0]->getText());
    }


    public function testCumulativeModeStylesAllWordsUpToTheActiveWord(): void
    {
        $karaoke = self::expand(
            TestSubtitles::fromCues([new SubtitleCue(0, 1.6, self::BEACH)]),
            new WordHighlightOptions(style: 'font color="#ffff00"', mode: WordHighlightMode::Cumulative)
        );

        $this->assertSame([
            '<font color="#ffff00">The</font> beach was quiet.',
            '<font color="#ffff00">The beach</font> was quiet.',
            '<font color="#ffff00">The beach was</font> quiet.',
            '<font color="#ffff00">The beach was quiet.</font>',
        ], array_column(TestSubtitles::describe($karaoke), 2));
    }


    public function testMaxWordsPerCueShowsAWindowAroundTheActiveWord(): void
    {
        $cue = new SubtitleCue(0, 5, "<00:00:00.000>One <00:00:01.000>two <00:00:02.000>three <00:00:03.000>four <00:00:04.000>five");

        $this->assertSame(
            ["<u>One</u> two three", "One <u>two</u> three", "two <u>three</u> four", "three <u>four</u> five", "three four <u>five</u>"],
            array_column(TestSubtitles::describe(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions(maxWordsPerCue: 3))), 2)
        );
        $this->assertSame(
            ["<u>One</u> two", "<u>two</u> three", "<u>three</u> four", "<u>four</u> five", "four <u>five</u>"],
            array_column(TestSubtitles::describe(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions(maxWordsPerCue: 2))), 2)
        );
        $this->assertSame(
            ["<u>One</u>", "<u>two</u>", "<u>three</u>", "<u>four</u>", "<u>five</u>"],
            array_column(TestSubtitles::describe(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions(maxWordsPerCue: 1))), 2)
        );
    }


    public function testWindowDropsLinesAndEmptyTagsOfHiddenWords(): void
    {
        $cue     = new SubtitleCue(2, 4, ["Hi, <i><00:00:02.500>the <00:00:03.000>train</i>", "<00:00:03.500>is late."]);
        $karaoke = self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions(maxWordsPerCue: 1));

        $this->assertSame([
            [2.0, 2.5, "Hi, <i>the</i>"],
            [2.5, 3.0, "Hi, <i><u>the</u></i>"],
            [3.0, 3.5, "<i><u>train</u></i>"],
            [3.5, 4.0, "<u>is late.</u>"],
        ], TestSubtitles::describe($karaoke));
    }


    public function testStyleNeverCrossesOtherTags(): void
    {
        $cue     = new SubtitleCue(0, 3, "<v Ann><00:00:00.000>Hi <b><00:00:01.000>there</b> <00:00:02.000>you");
        $karaoke = self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions(mode: WordHighlightMode::Cumulative));

        $this->assertSame([
            "<v Ann><u>Hi</u> <b>there</b> you",
            "<v Ann><u>Hi</u> <b><u>there</u></b> you",
            "<v Ann><u>Hi</u> <b><u>there</u></b> <u>you</u>",
        ], array_column(TestSubtitles::describe($karaoke), 2));
    }


    public function testCueWithoutWordTimestampsStaysAsItIs(): void
    {
        $cue = (new SubtitleCue(1, 2, ["<i>Plain</i> text", "second line"]))
            ->setIdentifier("intro")
            ->setAlignment(8)
            ->setFormatData("vtt", ["line" => "0"]);

        $karaoke = self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions());

        $this->assertCount(1, $karaoke->getCues());
        $this->assertEquals($cue, $karaoke->getCues()[0]);
        $this->assertNotSame($cue, $karaoke->getCues()[0]);
    }


    public function testResultHasNoWordTimestamps(): void
    {
        foreach ([new WordHighlightOptions(), new WordHighlightOptions(maxWordsPerCue: 2)] as $options) {
            foreach (self::expand(self::whisper(), $options)->getCues() as $cue) {
                $this->assertDoesNotMatchRegularExpression(Markup::WORD_TIMESTAMP_REGEX, $cue->getText());
            }
        }
    }


    public function testTextBeforeTheFirstTimestampGetsACueWithoutHighlight(): void
    {
        $cue = new SubtitleCue(1, 3, "So <00:00:02.000>the <00:00:02.500>end");

        $this->assertSame([
            [1.0, 2.0, "So the end"],
            [2.0, 2.5, "So <u>the</u> end"],
            [2.5, 3.0, "So the <u>end</u>"],
        ], TestSubtitles::describe(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions())));
    }


    public function testTimestampsOutsideTheCueAreClampedAndEmptyWordsSkipped(): void
    {
        $cue = new SubtitleCue(1, 3, "<00:00:00.500>Early <00:00:02.000>same <00:00:02.000>time <00:00:04.000>late");

        $this->assertSame([
            [1.0, 2.0, "<u>Early</u> same time late"],
            [2.0, 3.0, "Early same <u>time</u> late"],
        ], TestSubtitles::describe(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions())));
    }


    public function testCueWithoutDurationLosesItsTimestamps(): void
    {
        $cue = new SubtitleCue(2, 2, "<00:00:02.000>Too <00:00:02.000>short");

        $this->assertSame([[2.0, 2.0, "Too short"]],
                          TestSubtitles::describe(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions())));
    }


    public function testWordCuesKeepTheCueDataAndTheFirstKeepsTheIdentifier(): void
    {
        $cue = (new SubtitleCue(0, 1.6, self::BEACH))
            ->setIdentifier("intro")
            ->setAlignment(8)
            ->setForced(true)
            ->setFormatData("ass", ["fields" => ["Style" => "Karaoke"]]);

        $cues = array_values(self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions())->getCues());

        $this->assertSame(["intro", null, null, null], array_map(fn (SubtitleCue $cue): ?string => $cue->getIdentifier(), $cues));
        foreach ($cues as $wordCue) {
            $this->assertSame(8, $wordCue->getAlignment());
            $this->assertTrue($wordCue->isForced());
            $this->assertSame(["fields" => ["Style" => "Karaoke"]], $wordCue->findFormatData("ass"));
        }
    }


    public function testMetadataAndCommentsStayWithTheirCues(): void
    {
        $subtitle = TestSubtitles::fromCues([
            new SubtitleCue(0, 1.6, self::BEACH),
            new SubtitleCue(2, 3, "<00:00:02.000>Next <00:00:02.500>line")
        ]);
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, "Beach");
        $subtitle->addComment("Before the second cue", 1);
        $subtitle->addComment("At the end", 2);

        $karaoke = self::expand($subtitle, new WordHighlightOptions());

        $this->assertSame("Beach", $karaoke->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertEquals([
            new Comment("Before the second cue", 4),
            new Comment("At the end", 6),
        ], $karaoke->getComments());
    }


    public function testRightToLeftTextKeepsLogicalOrder(): void
    {
        $cue     = new SubtitleCue(0, 2, "<00:00:00.000>שלום <00:00:01.000>עולם");
        $karaoke = self::expand(TestSubtitles::fromCues([$cue]), new WordHighlightOptions());

        $this->assertSame(["<u>שלום</u> עולם", "שלום <u>עולם</u>"], array_column(TestSubtitles::describe($karaoke), 2));
    }


    public function testHebrewRealFileParsesAndRoundTrips(): void
    {
        $content  = file_get_contents(self::FILES . "karaoke/hebrew.lrc");
        $subtitle = Subtitle::fromString($content, Format::Lyrics);

        $this->assertSame([
            [1.0, 4.0, "<00:00:01.000>הרכבת <00:00:01.600>יוצאת <00:00:02.300>בשבע."],
            [4.0, 7.0, "<00:00:04.000>הלחם <00:00:04.550>מוכן <00:00:05.100>בשמונה."],
        ], TestSubtitles::describe($subtitle));
        $this->assertSame($content, $subtitle->toString(Format::Lyrics));
    }


    public static function realFiles(): array
    {
        $lrc = fn (string $file): Subtitle => Subtitle::fromString(file_get_contents(self::FILES . $file), Format::Lyrics);

        return [
            "Hebrew enhanced LRC" => [
                fn (): Subtitle => $lrc("karaoke/hebrew.lrc"),
                new WordHighlightOptions(),
                Format::SubRip,
                "hebrew_word.srt",
            ],
            "openai-whisper words" => [
                fn (): Subtitle => self::whisper(),
                new WordHighlightOptions(),
                Format::SubRip,
                "whisper_word.srt",
            ],
            "openai-whisper, one bold word" => [
                fn (): Subtitle => self::whisper(),
                new WordHighlightOptions(style: "b", maxWordsPerCue: 1),
                Format::WebVtt,
                "whisper_one_word.vtt",
            ],
            "enhanced LRC, cumulative" => [
                fn (): Subtitle => $lrc("lrc/real/handwritten-enhanced.lrc"),
                new WordHighlightOptions(style: 'font color="#ffff00"', mode: WordHighlightMode::Cumulative),
                Format::SubRip,
                "lrc_cumulative.srt",
            ],
            "Aegisub karaoke, 3 words" => [
                fn (): Subtitle => Subtitle::fromString(file_get_contents(self::FILES . "ass/real/own_aegisub.ass"), Format::Ass),
                new WordHighlightOptions(maxWordsPerCue: 3),
                Format::SubRip,
                "aegisub_window.srt",
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFile(\Closure $parse, WordHighlightOptions $options, Format $format, string $expected): void
    {
        $this->assertSame(
            file_get_contents(self::FILES . "karaoke/" . $expected),
            self::expand($parse(), $options)->toString($format)
        );
    }


    public function testAssFormatterWritesTheKaraokeTagOfTheOption(): void
    {
        $this->assertSame(
            file_get_contents(self::FILES . "karaoke/whisper_kf.ass"),
            self::whisper()->toString(Format::Ass, new WriteOptions(formats: [new AssWriteOptions(karaokeTag: AssKaraokeTag::Fill)]))
        );

        $subtitle = TestSubtitles::fromCues([new SubtitleCue(0, 1.6, "Oh <00:00:00.500>the <00:00:01.000>sea")]);
        $this->assertStringContainsString("{\\ko50}Oh {\\ko50}the {\\ko60}sea",
                                          $subtitle->toString(Format::Ass, new WriteOptions(formats: [new AssWriteOptions(karaokeTag: AssKaraokeTag::Outline)])));
        $this->assertStringContainsString("{\\k50}Oh {\\k50}the {\\k60}sea", $subtitle->toString(Format::Ass));
    }


    public function testAssFormatterKeepsTheTagsOfUnchangedCues(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "ass/real/own_aegisub.ass"), Format::Ass);

        $this->assertStringContainsString("{\\k40}The {\\k35}train {\\k50}leaves {\\kf60}at {\\ko45}noon",
                                          $subtitle->toString(Format::Ass, new WriteOptions(formats: [new AssWriteOptions(karaokeTag: AssKaraokeTag::Fill)])));
    }

}
