<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Resegmenting\ResegmentReport;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class ResegmenterTest extends TestCase
{
    private const FILES = __DIR__ . "/files/resegmenting/";

    private const LONG_TENSOR_SENTENCE = "The tensor operators are optimized heavily for Apple silicon CPUs. Depending on the computation " .
                                         "size, Arm Neon SIMD instrisics or CBLAS Accelerate framework routines are used.";


    private static function apply(Subtitle $subtitle, ResegmentOptions $options): Subtitle
    {
        Resegmenter::apply($subtitle, $options);

        return $subtitle;
    }


    private function split(array $cues, ?ResegmentOptions $options = null): array
    {
        return TestSubtitles::describe(self::apply(TestSubtitles::fromCues($cues), $options ?? new ResegmentOptions(ResegmentMode::SplitLong)));
    }


    private function resegment(array $cues, ?ResegmentOptions $options = null): array
    {
        return TestSubtitles::describe(self::apply(TestSubtitles::fromCues($cues), $options ?? new ResegmentOptions(ResegmentMode::ByWords)));
    }


    private function parseWhisperFixture(): Subtitle
    {
        return (new WhisperJsonParser())
            ->parse(file_get_contents(self::FILES . "own_whisper_long_segments.json"), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
    }


    public function testSplitLongBreaksAtSentenceAndClauseEnds(): void
    {
        $cues = $this->split([[0, 11.05, self::LONG_TENSOR_SENTENCE]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 42, maxLinesPerCue: 2)));

        $this->assertSame([
            [0.0, 4.231, "The tensor operators are optimized\nheavily for Apple silicon CPUs."],
            [4.231, 6.441, "Depending on the computation size,"],
            [6.441, 11.05, "Arm Neon SIMD instrisics or CBLAS\nAccelerate framework routines are used."],
        ], $cues);
    }


    public function testRealWhisperFileSplitLongCues(): void
    {
        $subtitle = $this->parseWhisperFixture();
        $this->assertCount(4, $subtitle->getCues());

        $this->assertEquals(new ResegmentReport(4, 6), Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong)));

        $cues = TestSubtitles::describe($subtitle);
        $this->assertCount(6, $cues);
        $this->assertSame([0.0, 2.55, "<00:00:00.000>Welcome <00:00:00.570>to <00:00:00.830>the <00:00:01.150>city " .
                                      "<00:00:01.530>library <00:00:02.100>tour."], $cues[0]);
        $this->assertSame(2.55, $cues[1][0]);
        $this->assertSame(8.55, $cues[1][1]);
        $this->assertSame([20.2, 24.0, "<00:00:20.200>Any <00:00:20.520>questions <00:00:21.230>before <00:00:21.750>we " .
                                       "<00:00:22.000>start? <00:00:22.520>Then <00:00:22.910>let <00:00:23.230>us " .
                                       "<00:00:23.480>begin."], $cues[5]);
        $this->assertStringEqualsFile(self::FILES . "own_whisper_long_segments_split.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testRealWhisperFileResegmentByWords(): void
    {
        $subtitle = self::apply($this->parseWhisperFixture(), new ResegmentOptions(ResegmentMode::ByWords));

        $cues = TestSubtitles::describe($subtitle);
        $this->assertCount(6, $cues);
        $this->assertSame([12.4, 18.2], array_slice($cues[3], 0, 2));
        $this->assertSame("The cafe on the ground floor opens at\nnine and closes at six every weekday.",
                          Markup::stripAllTags($cues[3][2]));
        $this->assertSame([22.52, 24.0, "<00:00:22.520>Then <00:00:22.910>let <00:00:23.230>us <00:00:23.480>begin."], $cues[5]);
        $this->assertStringEqualsFile(self::FILES . "own_whisper_long_segments_resegmented.srt",
                                      $subtitle->toString(Format::SubRip));
    }


    public function testWordTimestampsStayInTheCueThatHoldsTheWord(): void
    {
        foreach ([ResegmentMode::SplitLong, ResegmentMode::ByWords] as $mode) {
            $method   = $mode->name;
            $subtitle = self::apply($this->parseWhisperFixture(), new ResegmentOptions($mode, limits: new CueLimits(maxCharactersPerLine: 20, maxLinesPerCue: 1)));
            foreach ($subtitle->getCues() as $cue) {
                preg_match_all('/<(\d{2}):(\d{2}):(\d{2}\.\d{3})>/', $cue->getText(), $matches, PREG_SET_ORDER);
                $this->assertNotEmpty($matches);
                $this->assertSame(sprintf("<%s>", Markup::coreTimestamp($cue->getStart())), $matches[0][0], $method);
                foreach ($matches as [, $hours, $minutes, $seconds]) {
                    $time = $hours * 3600 + $minutes * 60 + (float) $seconds;
                    $this->assertGreaterThanOrEqual($cue->getStart(), $time, $method);
                    $this->assertLessThan($cue->getEnd(), $time, $method);
                }
            }
        }
    }


    // A copy of the new cue list after each cue takes about 16 s here.
    public function testFortyThousandCuesThatFitStayUnchangedUnderEightSeconds(): void
    {
        $bakery = Subtitle::fromString(file_get_contents(__DIR__ . "/files/statistics/own_bakery.srt"), Format::SubRip);
        $cues   = [];
        for ($copy = 0; $copy < 10000; $copy++) {
            foreach ($bakery->getCues() as $cue) {
                $cues[] = new SubtitleCue($cue->getStart() + 12 * $copy, $cue->getEnd() + 12 * $copy, $cue->getLines());
            }
        }
        $subtitle = TestSubtitles::fromCues($cues);
        $expected = $subtitle->toString(Format::SubRip);

        $start  = microtime(true);
        $report = Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong));

        $this->assertLessThan(8, microtime(true) - $start);
        $this->assertEquals(new ResegmentReport(40000, 40000), $report);
        $this->assertSame($expected, $subtitle->toString(Format::SubRip));
    }


    public function testCueThatFitsStaysUnchanged(): void
    {
        $text = "Short line\nthat fits.";

        $this->assertSame([[1.0, 3.0, $text]], $this->split([[1, 3, $text]]));
    }


    public function testBreakPointsPreferSentenceEndThenClauseEndThenMiddleSpace(): void
    {
        $options = new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1, minDuration: 0));

        $this->assertSame(
            ["One two three four five.", "Six seven eight nine ten"],
            array_column($this->split([[0, 10, "One two three four five. Six seven eight nine ten"]], $options), 2)
        );
        $this->assertSame(
            ["One two three four five six,", "seven eight nine ten eleven"],
            array_column($this->split([[0, 10, "One two three four five six, seven eight nine ten eleven"]], $options), 2)
        );
        $this->assertSame(
            ["One two three four five six", "seven eight nine ten eleven"],
            array_column($this->split([[0, 10, "One two three four five six seven eight nine ten eleven"]], $options), 2)
        );
    }


    public function testClauseEndsIncludeSemicolonColonAndDashes(): void
    {
        $options = new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1, minDuration: 0));

        foreach (["one;" => "one;", "one:" => "one:", "one\u{2014}" => "one\u{2014}", "one -" => "one -"] as $end => $expected) {
            $this->assertSame(
                ["Aaaa bbbb cccc dddd $expected", "eeee ffff gggg hhhh iiii jjjj"],
                array_column($this->split([[0, 10, "Aaaa bbbb cccc dddd $end eeee ffff gggg hhhh iiii jjjj"]], $options), 2),
                $end
            );
        }
    }


    public function testFullStopBeforeLowerCaseWordEndsNoSentence(): void
    {
        $options = new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1, minDuration: 0));

        $this->assertSame(
            ["Bring a tool e.g. a hammer and", "nails for the roof of the shed"],
            array_column($this->split([[0, 10, "Bring a tool e.g. a hammer and nails for the roof of the shed"]], $options), 2)
        );
    }


    public function testSplitsUntilEachPartFits(): void
    {
        $cues = $this->split([[0, 9, "One. Two. Three. Four. Five. Six."]],
                             new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 10, maxLinesPerCue: 1, minDuration: 0)));

        $this->assertSame(["One. Two.", "Three.", "Four.", "Five. Six."], array_column($cues, 2));
    }


    public function testTimeSplitsInProportionToTheVisibleCharacters(): void
    {
        $cues = $this->split([[10, 20, "<i>Aaaa bbbb.</i> Cccc dddd eeee ffff gggg."]],
                             new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1)));

        $this->assertSame([[10.0, 13.056, "<i>Aaaa bbbb.</i>"], [13.056, 20.0, "Cccc dddd eeee ffff gggg."]], $cues);
    }


    public function testMinDurationBlocksABreak(): void
    {
        $text = "Yes. Then we walk along the river to the old mill and back.";

        $this->assertSame(
            ["Yes.", "Then we walk along the river to the old mill and back."],
            array_column($this->split([[0, 12, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 60, maxLinesPerCue: 1, maxDuration: 11))), 2)
        );
        $this->assertSame(
            ["Yes. Then we walk along the", "river to the old mill and back."],
            array_column($this->split([[0, 6.5, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 60, maxLinesPerCue: 1, maxDuration: 6))), 2)
        );
        $this->assertSame([[0.0, 1.5, $text]],
                          $this->split([[0, 1.5, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 30, maxLinesPerCue: 1))));
    }


    public function testMaxDurationAndMaxCharactersPerSecond(): void
    {
        $text = "We meet at the north gate. Then we walk to the lake.";

        $this->assertSame([[0.0, 8.0, $text]], $this->split([[0, 8, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxDuration: 8))));
        $this->assertCount(2, $this->split([[0, 8, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxDuration: 7))));
        $this->assertCount(1, $this->split([[0, 4, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerSecond: 13))));
        $this->assertCount(2, $this->split([[0, 4, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerSecond: 12))));
    }


    public function testCoreMarkupClosesAtTheBreakAndOpensAgain(): void
    {
        $cues = $this->split([[0, 10, '<v Ann><i>We go <font color="#ff0000">now, but</font> slowly.</i> <b>Keep up.</b>']],
                             new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 15, maxLinesPerCue: 1, minDuration: 0)));

        $this->assertSame([
            '<v Ann><i>We go <font color="#ff0000">now,</font></i></v>',
            '<v Ann><i><font color="#ff0000">but</font> slowly.</i></v>',
            '<v Ann><b>Keep up.</b>',
        ], array_column($cues, 2));
    }


    public function testCjkTextSplitsAtCjkPunctuation(): void
    {
        $cues = $this->split([[0, 10, "今日はとても良い天気ですね。明日も晴れると良いのですが、雨が降るかもしれません。「本当に？」そうです。"]],
                             new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 16, maxLinesPerCue: 1)));

        $this->assertSame([
            [0.0, 2.745, "今日はとても良い天気ですね。"],
            [2.745, 5.49, "明日も晴れると良いのですが、"],
            [5.49, 7.843, "雨が降るかもしれません。"],
            [7.843, 10.0, "「本当に？」そうです。"],
        ], $cues);
    }


    public function testCjkTextSplitsAtWordTimestamps(): void
    {
        $text = "<00:00:00.000>今日<00:00:01.000>は<00:00:01.500>とても<00:00:02.500>良い<00:00:03.500>天気<00:00:04.500>です";

        $cues = $this->split([[0, 6, $text]], new ResegmentOptions(ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 6, maxLinesPerCue: 1)));

        $this->assertSame([
            [0.0, 2.5, "<00:00:00.000>今日<00:00:01.000>は<00:00:01.500>とても"],
            [2.5, 6.0, "<00:00:02.500>良い<00:00:03.500>天気<00:00:04.500>です"],
        ], $cues);
    }


    public function testImageCuesAndCuesOfOneWordStayUnchanged(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(0, 20, "Supercalifragilisticexpialidocious"))
            ->addCue(TestSubtitles::imageCue(new CueImage("png", 0, 0, 1, 1, 1, 1), new SubtitleCue(20, 40, self::LONG_TENSOR_SENTENCE)));

        foreach ([ResegmentMode::SplitLong, ResegmentMode::ByWords] as $mode) {
            $this->assertSame([[0.0, 20.0, "Supercalifragilisticexpialidocious"], [20.0, 40.0, self::LONG_TENSOR_SENTENCE]],
                              TestSubtitles::describe(self::apply($subtitle, new ResegmentOptions($mode, limits: new CueLimits(maxCharactersPerLine: 10, maxLinesPerCue: 1)))),
                              $mode->name);
        }
    }


    public function testSplitKeepsIdentifierOnFirstPartAndCommentsBeforeIt(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 1, "Before."], [1, 11.05, self::LONG_TENSOR_SENTENCE], [12, 13, "After."]]);
        $subtitle->getCues()[1]->setIdentifier("long")->setAlignment(Alignment::TopCenter)->setForced(true);
        $subtitle->addComment("before long", 1)->addComment("before after", 2);

        Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong));

        $cues = array_values($subtitle->getCues());
        $this->assertCount(5, $cues);
        $this->assertSame(["long", null, null], [$cues[1]->findIdentifier(), $cues[2]->findIdentifier(), $cues[3]->findIdentifier()]);
        $this->assertSame([Alignment::TopCenter, Alignment::TopCenter, Alignment::TopCenter], [$cues[1]->getAlignment(), $cues[2]->getAlignment(), $cues[3]->getAlignment()]);
        $this->assertTrue($cues[3]->isForced());
        $this->assertEquals([new Comment("before long", 1), new Comment("before after", 4)],
                          $subtitle->getComments());
    }


    public function testResegmentEndsCueAtSentenceEndAcrossCues(): void
    {
        $cues = $this->resegment([
            [0, 2, "<00:00:00.000>One <00:00:00.500>two. <00:00:01.000>Three"],
            [2, 4, "<00:00:02.000>four <00:00:03.000>five."],
        ]);

        $this->assertSame([
            [0.0, 1.0, "<00:00:00.000>One <00:00:00.500>two."],
            [1.0, 4.0, "<00:00:01.000>Three <00:00:02.000>four <00:00:03.000>five."],
        ], $cues);
    }


    public function testResegmentEndsCueAtMaxWordGap(): void
    {
        $cues = [[0, 2, "<00:00:00.000>One <00:00:01.000>two"], [2.6, 4, "<00:00:02.600>three <00:00:03.000>four"]];

        $this->assertCount(2, $this->resegment($cues));
        $this->assertCount(1, $this->resegment($cues, new ResegmentOptions(ResegmentMode::ByWords, maxWordGap: 0.7)));
    }


    public function testResegmentEndsCueWhenTheNextWordBreaksALimit(): void
    {
        $cues = $this->resegment([[0, 4, "<00:00:00.000>Aaaa <00:00:01.000>bbbb <00:00:02.000>cccc <00:00:03.000>dddd"]],
                                 new ResegmentOptions(ResegmentMode::ByWords, limits: new CueLimits(maxCharactersPerLine: 10, maxLinesPerCue: 1)));

        $this->assertSame([[0.0, 2.0, "<00:00:00.000>Aaaa <00:00:01.000>bbbb"], [2.0, 4.0, "<00:00:02.000>cccc <00:00:03.000>dddd"]], $cues);
    }


    public function testResegmentKeepsCuesWithoutWordTimestampsAndDifferentSpeakersApart(): void
    {
        $cues = $this->resegment([
            [0, 1, "<v Ann><00:00:00.000>Hello <00:00:00.500>there"],
            [1, 2, "<v Bob><00:00:01.000>Hi <00:00:01.500>Ann"],
            [2, 3, "No timestamps"],
            [3, 4, "<v Bob><00:00:03.000>Bye"],
        ]);

        $this->assertSame([
            [0.0, 1.0, "<v Ann><00:00:00.000>Hello <00:00:00.500>there"],
            [1.0, 2.0, "<v Bob><00:00:01.000>Hi <00:00:01.500>Ann"],
            [2.0, 3.0, "No timestamps"],
            [3.0, 4.0, "<v Bob><00:00:03.000>Bye"],
        ], $cues);
    }


    public function testResegmentKeepsIdentifierOfTheFirstWordAndMovesComments(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 2, "<00:00:00.000>One <00:00:01.000>two"],
            [2, 4, "<00:00:02.000>three. <00:00:03.000>Four."],
        ]);
        $subtitle->getCues()[0]->setIdentifier("first");
        $subtitle->getCues()[1]->setIdentifier("second");
        $subtitle->addComment("before second", 1);

        Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::ByWords));

        $cues = array_values($subtitle->getCues());
        $this->assertSame(["first", null], [$cues[0]->findIdentifier(), $cues[1]->findIdentifier()]);
        $this->assertEquals([new Comment("before second", 0)], $subtitle->getComments());
    }


    public function testModeHasStringValues(): void
    {
        $this->assertSame(["splitLong", "byWords"], array_map(fn (ResegmentMode $mode): string => $mode->value, ResegmentMode::cases()));
        $this->assertSame(ResegmentMode::ByWords, ResegmentMode::from("byWords"));
    }
}
