<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class ShortCueMergingTest extends TestCase
{
    private const FILES = __DIR__ . "/files/short-cues/";


    private function merge(array $cues, ?MergeShortCuesOptions $options = null): array
    {
        return TestSubtitles::describe(TestSubtitles::fromCues($cues)->mergeShortCues($options ?? new MergeShortCuesOptions()));
    }


    public function testThreeShortCuesWithSmallGapsBecomeOneCue(): void
    {
        $subtitle = Subtitle::fromString("12\n00:01:02,100 --> 00:01:02,600\nWait.\n\n" .
                                    "13\n00:01:02,640 --> 00:01:03,300\nWhere are you\n\n" .
                                    "14\n00:01:03,320 --> 00:01:04,100\ngoing?\n", Format::SubRip);

        $subtitle->mergeShortCues(new MergeShortCuesOptions(
            limits: new CueLimits(maxCharactersPerLine: 42, maxLinesPerCue: 2, maxDuration: 7),
            maxGap: 0.25,
        ));

        $this->assertSame([[62.1, 64.1, "Wait. Where are you going?"]], TestSubtitles::describe($subtitle));
    }


    public function testRealSpeechToTextFile(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_speech_to_text.srt"), Format::SubRip);
        $this->assertCount(10, $subtitle->getCues());

        $subtitle->mergeShortCues(new MergeShortCuesOptions());

        $cues = TestSubtitles::describe($subtitle);
        $this->assertCount(7, $cues);
        $this->assertSame([0.5, 1.76, "Good morning. Today we look at"], $cues[0]);
        $this->assertSame([4.0, 4.4, "Okay."], $cues[2]);
        $this->assertSame([10.64, 12.1, "- Is it safe?\n- Yes, it is. <i>Thanks.</i>"], $cues[5]);
        $this->assertSame([15.0, 15.4, "Bye."], $cues[6]);
        $this->assertStringEqualsFile(self::FILES . "own_speech_to_text_merged.srt", $subtitle->toString(Format::SubRip));
    }


    public function testRealInterviewFileWithSameSpeakerOnly(): void
    {
        $content = file_get_contents(self::FILES . "own_interview.vtt");
        $this->assertSame(
            TestSubtitles::describe(Subtitle::fromString($content, Format::WebVtt)),
            TestSubtitles::describe(Subtitle::fromString($content, Format::WebVtt)->mergeShortCues(new MergeShortCuesOptions()))
        );

        $subtitle = Subtitle::fromString($content, Format::WebVtt)
            ->mergeShortCues(new MergeShortCuesOptions(mergeSameSpeakerAnyDuration: true));

        $cues = TestSubtitles::describe($subtitle);
        $this->assertCount(4, $cues);
        $this->assertSame([3.5, 10.9, "<v Guest>In a small town near the coast, in the</v>\n" .
                                      "<v Guest>north. My parents ran a bakery there."], $cues[1]);
        $this->assertSame([13.1, 16.0, "<v Guest>Every summer."], $cues[3]);
        $this->assertStringEqualsFile(self::FILES . "own_interview_merged.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testCueOfAtLeastMinDurationIsNotShort(): void
    {
        $cues = [[0, 1, "One."], [1.1, 2.1, "Two."]];

        $this->assertSame([[0.0, 1.0, "One."], [1.1, 2.1, "Two."]], $this->merge($cues));
        $this->assertSame([[0.0, 2.1, "One. Two."]], $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(minDuration: 1.001))));
    }


    public function testMinCharacters(): void
    {
        $cues = [[0, 2, "<i>Yes.</i>"], [2.1, 4, "That is the plan."]];

        $this->assertSame([[0.0, 2.0, "<i>Yes.</i>"], [2.1, 4.0, "That is the plan."]], $this->merge($cues));
        $this->assertSame([[0.0, 2.0, "<i>Yes.</i>"], [2.1, 4.0, "That is the plan."]],
                          $this->merge($cues, new MergeShortCuesOptions(minCharacters: 4)));
        $this->assertSame([[0.0, 4.0, "<i>Yes.</i> That is the plan."]],
                          $this->merge($cues, new MergeShortCuesOptions(minCharacters: 5)));
    }


    public function testMaxGap(): void
    {
        $cues = [[0, 0.5, "One"], [0.75, 1.5, "two"]];

        $this->assertSame([[0.0, 1.5, "One two"]], $this->merge($cues));
        $this->assertSame([[0.0, 0.5, "One"], [0.75, 1.5, "two"]], $this->merge($cues, new MergeShortCuesOptions(maxGap: 0.249)));
    }


    public function testJoinedTextMustFitMaxCharactersPerLineAndMaxLines(): void
    {
        $cues = [[0, 0.5, "The bridge opened"], [0.5, 1, "last spring."]];

        $this->assertSame([[0.0, 1.0, "The bridge opened last spring."]], $this->merge($cues));
        $this->assertSame([[0.0, 1.0, "The bridge opened\nlast spring."]],
                          $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 20))));
        $this->assertSame([[0.0, 0.5, "The bridge opened"], [0.5, 1.0, "last spring."]],
                          $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 20, maxLinesPerCue: 1))));
        $this->assertSame([[0.0, 0.5, "The bridge opened"], [0.5, 1.0, "last spring."]],
                          $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 16))));
    }


    public function testWrapClosesAndReopensTagsAsWrapLinesDoes(): void
    {
        $this->assertSame([[0.0, 1.0, "<i>The bridge opened</i>\n<i>last spring.</i>"]],
                          $this->merge([[0, 0.5, "<i>The bridge opened"], [0.5, 1, "last spring.</i>"]],
                                       new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 20))));
    }


    public function testDialogueDashKeepsItsLineBreak(): void
    {
        $this->assertSame([[0.0, 1.0, "- Is it safe?\n- Yes."]], $this->merge([[0, 0.5, "- Is it safe?"], [0.5, 1, "- Yes."]]));
        $this->assertSame([[0.0, 1.0, "- Ready?\n<i>- Yes.</i>"]], $this->merge([[0, 0.5, "- Ready?"], [0.5, 1, "<i>- Yes.</i>"]]));
        $this->assertSame([[0.0, 0.5, "- Is it safe?"], [0.5, 1.0, "- Yes."]],
                          $this->merge([[0, 0.5, "- Is it safe?"], [0.5, 1, "- Yes."]], new MergeShortCuesOptions(limits: new CueLimits(maxLinesPerCue: 1))));
        $this->assertSame([[0.0, 0.5, "- Is it safe?"], [0.5, 1.0, "- Yes."]],
                          $this->merge([[0, 0.5, "- Is it safe?"], [0.5, 1, "- Yes."]], new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 12))));
    }


    public function testMaxDuration(): void
    {
        $cues = [[0, 6.5, "The first part of the bridge"], [6.5, 7, "opened."]];

        $this->assertSame([[0.0, 7.0, "The first part of the bridge opened."]], $this->merge($cues));
        $this->assertSame([[0.0, 6.5, "The first part of the bridge"], [6.5, 7.0, "opened."]],
                          $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(maxDuration: 6.999))));
    }


    public function testMaxCharactersPerSecond(): void
    {
        $cues = [[0, 0.5, "Wait."], [0.5, 1, "Where are you going?"]];

        $this->assertSame([[0.0, 1.0, "Wait. Where are you going?"]], $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerSecond: 26))));
        $this->assertSame([[0.0, 0.5, "Wait."], [0.5, 1.0, "Where are you going?"]],
                          $this->merge($cues, new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerSecond: 25))));
    }


    public function testTriesThePreviousCueWhenTheNextCueDoesNotFit(): void
    {
        $this->assertSame([[0.0, 2.5, "We start now."], [4.0, 5.0, "Next part."]],
                          $this->merge([[0, 2, "We start"], [2.1, 2.5, "now."], [4, 5, "Next part."]]));
        $this->assertSame([[0.0, 2.0, "We start"], [2.1, 2.5, "now."]],
                          $this->merge([[0, 2, "We start"], [2.1, 2.5, "now."]], new MergeShortCuesOptions(limits: new CueLimits(maxDuration: 2))));
    }


    public function testJoinsAgainWhileTheJoinedCueIsShort(): void
    {
        $this->assertSame([[0.0, 1.2, "One two three"]], $this->merge([[0, 0.3, "One"], [0.3, 0.6, "two"], [0.7, 1.2, "three"]]));
    }


    public function testWalksTheCuesInTimeOrder(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(0.5, 1, "two"));
        $subtitle->addCue(new SubtitleCue(0, 0.5, "One"));

        $this->assertSame([[0.0, 1.0, "One two"]], TestSubtitles::describe($subtitle->mergeShortCues(new MergeShortCuesOptions())));
    }


    public function testNeverJoinsDifferentSpeakers(): void
    {
        $this->assertSame([[0.0, 0.5, "<v Anna>Hi."], [0.5, 1.0, "<v Ben>Hello."]],
                          $this->merge([[0, 0.5, "<v Anna>Hi."], [0.5, 1, "<v Ben>Hello."]]));
        $this->assertSame([[0.0, 0.5, "<v Anna>Hi."], [0.5, 1.0, "Hello."]],
                          $this->merge([[0, 0.5, "<v Anna>Hi."], [0.5, 1, "Hello."]]));
        $this->assertSame([[0.0, 1.0, "<v.loud Anna>Hi. Hello.</v>"]],
                          $this->merge([[0, 0.5, "<v.loud Anna>Hi.</v>"], [0.5, 1, "<v Anna>Hello.</v>"]]));
    }


    public function testSameSpeakerOnly(): void
    {
        $options = new MergeShortCuesOptions(mergeSameSpeakerAnyDuration: true);

        $this->assertSame([[0.0, 9.0, "<v Anna>I grew up near the coast and my parents"]],
                          $this->merge([[0, 5, "<v Anna>I grew up near the coast"], [5.1, 9, "<v Anna>and my parents"]], $options));
        $this->assertSame([[0.0, 0.5, "Hi."], [0.5, 1.0, "Hello."]], $this->merge([[0, 0.5, "Hi."], [0.5, 1, "Hello."]], $options));
        $this->assertSame([[0.0, 0.5, "<v Anna>Hi."], [0.5, 1.0, "<v Ben>Hello."]],
                          $this->merge([[0, 0.5, "<v Anna>Hi."], [0.5, 1, "<v Ben>Hello."]], $options));
    }


    public function testNeverJoinsDifferentAlignmentsForcedFlagsOrImageCues(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 0.5, "One"], [0.5, 1, "two"]]);
        $subtitle->getCues()[1]->setAlignment(Alignment::TopCenter);
        $this->assertCount(2, $subtitle->mergeShortCues(new MergeShortCuesOptions())->getCues());

        $subtitle = TestSubtitles::fromCues([[0, 0.5, "One"], [0.5, 1, "two"]]);
        $subtitle->getCues()[1]->setAlignment(Alignment::BottomCenter);
        $this->assertCount(1, $subtitle->mergeShortCues(new MergeShortCuesOptions())->getCues());

        $subtitle = TestSubtitles::fromCues([[0, 0.5, "One"], [0.5, 1, "two"]]);
        $subtitle->getCues()[1]->setForced(true);
        $this->assertCount(2, $subtitle->mergeShortCues(new MergeShortCuesOptions())->getCues());

        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(0, 0.5, "One"))
            ->addCue((new CueImage("png", 0, 0, 1, 1, 1, 1))->toCue(new SubtitleCue(0.5, 1, "two")));
        $this->assertCount(2, $subtitle->mergeShortCues(new MergeShortCuesOptions())->getCues());
    }


    public function testKeepSentenceEnds(): void
    {
        $cues    = [[0, 0.5, "Wait."], [0.5, 1, "Where are you"], [1, 1.5, "going?"]];
        $options = new MergeShortCuesOptions(keepSentenceEnds: true);

        $this->assertSame([[0.0, 0.5, "Wait."], [0.5, 1.5, "Where are you going?"]], $this->merge($cues, $options));
        $this->assertSame([[0.0, 0.5, "<i>Stop!</i>"], [0.5, 1.0, "Now."]],
                          $this->merge([[0, 0.5, "<i>Stop!</i>"], [0.5, 1, "Now."]], $options));
    }


    public function testJoinedCueKeepsIdentifierFormatDataAndComments(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 0.5, "One"], [0.5, 1, "two"], [3, 5, "Three."]]);
        $subtitle->getCues()[0]->setIdentifier("a")->setFormatData("vtt", ["settings" => "line:0"]);
        $subtitle->getCues()[1]->setIdentifier("b")->setFormatData("vtt", ["settings" => "line:5"]);
        $subtitle->addComment("before one", 0)->addComment("before two", 1)->addComment("before three", 2);

        $subtitle->mergeShortCues(new MergeShortCuesOptions());

        $this->assertSame([[0.0, 1.0, "One two"], [3.0, 5.0, "Three."]], TestSubtitles::describe($subtitle));
        $this->assertSame("a", $subtitle->getCues()[0]->getIdentifier());
        $this->assertSame(["settings" => "line:0"], $subtitle->getCues()[0]->findFormatData("vtt"));
        $this->assertEquals([new Comment("before one", 0),
                           new Comment("before two", 0),
                           new Comment("before three", 1)], $subtitle->getComments());
    }


    public function testEmptySubtitle(): void
    {
        $this->assertSame([], $this->merge([]));
    }
}
