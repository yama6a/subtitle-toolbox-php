<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\Validation\ValidationViolation;

class FixesTest extends \PHPUnit\Framework\TestCase
{
    private function wrap(string $text, int $maxCharactersPerLine, int $maxLines = 2): array
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, $text));
        $subtitle->wrapLines($maxCharactersPerLine, $maxLines);

        return $subtitle->getCues()[0]->getLines();
    }


    public function testRealFileWithOverlapsAndShortCues(): void
    {
        $content  = file_get_contents(__DIR__ . "/files/fixes/own_overlaps_and_short_cues.srt");
        $subtitle = Subtitle::fromString($content, Format::SubRip);
        $cues     = $subtitle->getCues();

        $this->assertCount(9, $cues);
        $this->assertSame([1.0, 3.2, "Good morning, everyone."], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([20.0, 20.4, "Tom &amp; Jerry &lt;3 coffee."], [$cues[8]->getStart(), $cues[8]->getEnd(), $cues[8]->getText()]);

        $subtitle->fixOverlaps(0.083)->extendShortCues(0.833, 0.083)->wrapLines(42);

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_overlaps_and_short_cues_fixed.srt"),
                          $subtitle->toString(Format::SubRip));
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testFixOverlapsKeepsTwoFramesGapAt24Fps(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 5.2], [5.1, 7]]);

        $this->assertSame($subtitle, $subtitle->fixOverlaps((new FrameRate(24))->framesToSeconds(2)));
        $this->assertSame([[1.0, 5.017], [5.1, 7.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsWithoutGap(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 5.2], [5.1, 7], [7, 8]]);

        $subtitle->fixOverlaps();

        $this->assertSame([[1.0, 5.1], [5.1, 7.0], [7.0, 8.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsWidensGapsThatAreTooSmall(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 5.05], [5.1, 7]]);

        $subtitle->fixOverlaps(0.1);

        $this->assertSame([[1.0, 5.0], [5.1, 7.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsNeverMovesAStartTime(): void
    {
        $subtitle = TestSubtitles::fromTimes([[5, 6], [5, 7], [5.05, 8]]);

        $subtitle->fixOverlaps(0.1);

        $this->assertSame([[5.0, 5.0], [5.0, 5.0], [5.05, 8.0]], TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsTrimsCuesWithTheSameStartAgainstTheNextLaterStart(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 4], [1, 3], [2, 5], [6, 7], [6, 8]]);

        $subtitle->fixOverlaps();

        $this->assertSame([[1.0, 2.0], [1.0, 2.0], [2.0, 5.0], [6.0, 7.0], [6.0, 8.0]],
                          TestSubtitles::times($subtitle));
    }


    public function testFixOverlapsWithNegativeGapThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The minimum gap must not be negative, got -1.");
        TestSubtitles::fromTimes([[1, 2]])->fixOverlaps(-1);
    }


    public function testExtendShortCues(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.3], [3.3, 5]]);

        $this->assertSame($subtitle, $subtitle->extendShortCues(0.833));
        $this->assertSame([[1.0, 1.833], [3.3, 5.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesStopsBeforeTheNextCue(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.3], [1.5, 1.6], [1.6, 3]]);

        $subtitle->extendShortCues(1, 0.1);

        $this->assertSame([[1.0, 1.4], [1.5, 1.6], [1.6, 3.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesNeverShortensACue(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.3], [1.2, 1.4], [2, 5]]);

        $subtitle->extendShortCues(1, 0.1);

        $this->assertSame([[1.0, 1.3], [1.2, 1.9], [2.0, 5.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesDoesNotExtendCuesWithTheSameStart(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.2], [1, 1.3], [5, 6]]);

        $subtitle->extendShortCues(1);

        $this->assertSame([[1.0, 1.2], [1.0, 1.3], [5.0, 6.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesExtendsTheLastCue(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 1.2]]);

        $subtitle->extendShortCues(2, 0.5);

        $this->assertSame([[1.0, 3.0]], TestSubtitles::times($subtitle));
    }


    public function testExtendShortCuesWithZeroDurationThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The minimum duration must be greater than 0, got 0.");
        TestSubtitles::fromTimes([[1, 2]])->extendShortCues(0);
    }


    public function testAddLeadInOutOnSpeechToTextTiming(): void
    {
        $subtitle = Subtitle::fromString((string)file_get_contents(__DIR__ . "/files/fixes/own_asr_tight_timing.srt"), Format::SubRip);

        $this->assertSame($subtitle, $subtitle->addLeadInOut(0.2, 0.3, 0.083));

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_asr_tight_timing_lead.srt"), $subtitle->toString(Format::SubRip));
    }


    /**
     * @return array<string, array{list<array{float, float}>, array{float, float, float}, list<array{float, float}>}>
     */
    public static function leadInOutCases(): array
    {
        return [
            "free space"           => [[[1, 2], [5, 6]], [0.2, 0.3, 0], [[0.8, 2.3], [4.8, 6.3]]],
            "small gap"            => [[[1, 2], [2.1, 3]], [0.2, 0.3, 0.04], [[0.8, 2.06], [2.1, 3.3]]],
            "never below 0"        => [[[0.1, 1]], [0.2, 0, 0], [[0.0, 1.0]]],
            "overlap kept"         => [[[1, 3], [2, 4]], [0.2, 0.2, 0], [[0.8, 3.0], [2.0, 4.2]]],
            "lead-out first"       => [[[1, 2], [2.2, 3]], [0.3, 0.3, 0], [[0.7, 2.2], [2.2, 3.3]]],
            "gap below minimum"    => [[[1, 2], [2.02, 3]], [0.2, 0.2, 0.04], [[0.8, 2.0], [2.02, 3.2]]],
            "same start"           => [[[1, 2], [1, 3], [5, 6]], [0.2, 0.2, 0], [[1.0, 2.0], [1.0, 3.2], [4.8, 6.2]]],
            "inside another cue"   => [[[1, 5], [2, 3]], [0.2, 0.2, 0], [[0.8, 5.2], [2.0, 3.0]]],
            "zero leads"           => [[[1, 2], [5, 6]], [0, 0, 0.5], [[1.0, 2.0], [5.0, 6.0]]],
            "zero-length cue"      => [[[1, 1], [3, 4]], [0.2, 0.2, 0], [[0.8, 1.2], [2.8, 4.2]]],
        ];
    }


    /**
     * @param list<array{float, float}> $times
     * @param array{float, float, float} $arguments
     * @param list<array{float, float}> $expected
     */
    #[DataProvider("leadInOutCases")]
    public function testAddLeadInOut(array $times, array $arguments, array $expected): void
    {
        $subtitle = TestSubtitles::fromTimes($times);

        $subtitle->addLeadInOut(...$arguments);

        $this->assertSame($expected, TestSubtitles::times($subtitle));
    }


    public function testAddLeadInOutCreatesNoOverlapAndNoNegativeStart(): void
    {
        mt_srand(461);
        for ($round = 0; $round < 200; $round++) {
            $times = [];
            for ($index = 0; $index < 12; $index++) {
                $start   = mt_rand(0, 6000) / 1000;
                $times[] = [$start, $start + mt_rand(0, 1500) / 1000];
            }
            $subtitle = TestSubtitles::fromTimes($times);
            $times    = TestSubtitles::times($subtitle);
            $minGap   = mt_rand(0, 100) / 1000;

            $subtitle->addLeadInOut(mt_rand(0, 500) / 1000, mt_rand(0, 500) / 1000, $minGap);

            $after = TestSubtitles::times($subtitle);
            foreach ($after as $index => [$start, $end]) {
                $this->assertGreaterThanOrEqual(0, $start);
                $this->assertLessThanOrEqual($times[$index][0], $start);
                $this->assertGreaterThanOrEqual($times[$index][1], $end);
                foreach ($after as $other => [$otherStart, $otherEnd]) {
                    $wasApart = $times[$index][1] <= $times[$other][0];
                    if ($other !== $index && $wasApart) {
                        $this->assertLessThanOrEqual($otherStart, $end, "round $round, cue $index before cue $other");
                    }
                }
            }
        }
    }


    /**
     * @return array<string, array{float, float, float, string}>
     */
    public static function invalidLeadInOut(): array
    {
        return [
            "negative lead-in"  => [-0.1, 0, 0, "The lead-in must not be negative, got -0.1."],
            "negative lead-out" => [0, -1, 0, "The lead-out must not be negative, got -1."],
            "NAN lead-in"       => [NAN, 0, 0, "The lead-in must not be negative, got NAN."],
            "INF lead-out"      => [0, INF, 0, "The lead-out must not be negative, got INF."],
            "negative gap"      => [0.2, 0.2, -1, "The minimum gap must not be negative, got -1."],
        ];
    }


    #[DataProvider("invalidLeadInOut")]
    public function testAddLeadInOutWithInvalidArgumentThrowsException(float $leadIn, float $leadOut, float $minGap, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        TestSubtitles::fromTimes([[1, 2]])->addLeadInOut($leadIn, $leadOut, $minGap);
    }


    public function testLimitLongCuesRemovesEachMaxDurationViolation(): void
    {
        $subtitle = Subtitle::fromString((string)file_get_contents(__DIR__ . "/files/fixes/own_long_cues.srt"), Format::SubRip);
        $tooLong  = fn (): array => array_values(array_filter($subtitle->validate(ValidationRules::netflixEnglish(24)),
                                                              fn (ValidationViolation $violation): bool => $violation->rule === ValidationRule::MaxDuration));
        $this->assertCount(2, $tooLong());

        $this->assertSame($subtitle, $subtitle->limitLongCues(7));

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_long_cues_limited.srt"), $subtitle->toString(Format::SubRip));
        $this->assertSame([], $tooLong());
    }


    /**
     * @return array<string, array{list<array{float, float}>, float, list<array{float, float}>}>
     */
    public static function longCueCases(): array
    {
        return [
            "too long"          => [[[1, 26]], 7, [[1.0, 8.0]]],
            "short"             => [[[1, 3]], 7, [[1.0, 3.0]]],
            "exactly the limit" => [[[1, 8]], 7, [[1.0, 8.0]]],
            "below 1 ms"        => [[[1.001, 9]], 7.0004, [[1.001, 8.001]]],
            "overlapping cues"  => [[[1, 10], [2, 4]], 2.5, [[1.0, 3.5], [2.0, 4.0]]],
        ];
    }


    /**
     * @param list<array{float, float}> $times
     * @param list<array{float, float}> $expected
     */
    #[DataProvider("longCueCases")]
    public function testLimitLongCues(array $times, float $maxDuration, array $expected): void
    {
        $subtitle = TestSubtitles::fromTimes($times);

        $subtitle->limitLongCues($maxDuration);

        $this->assertSame($expected, TestSubtitles::times($subtitle));
    }


    /**
     * @return array<string, array{float, string}>
     */
    public static function invalidMaxDuration(): array
    {
        return [
            "zero"     => [0, "The maximum duration must be greater than 0, got 0."],
            "negative" => [-7, "The maximum duration must be greater than 0, got -7."],
            "NAN"      => [NAN, "The maximum duration must be greater than 0, got NAN."],
            "INF"      => [INF, "The maximum duration must be greater than 0, got INF."],
        ];
    }


    #[DataProvider("invalidMaxDuration")]
    public function testLimitLongCuesWithInvalidMaximumThrowsException(float $maxDuration, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        TestSubtitles::fromTimes([[1, 2]])->limitLongCues($maxDuration);
    }


    public function testWrapLinesBalancesTwoLines(): void
    {
        $this->assertSame(["This is a very long subtitle line", "that does not fit on the screen"],
                          $this->wrap("This is a very long subtitle line that does not fit on the screen", 42));
    }


    public function testWrapLinesReturnsSubtitle(): void
    {
        $subtitle = new Subtitle();

        $this->assertSame($subtitle, $subtitle->wrapLines(42));
    }


    public function testWrapLinesKeepsCuesThatFit(): void
    {
        $this->assertSame(["Short line,", "and another one"], $this->wrap("Short line,\nand another one", 42));
    }


    public function testWrapLinesJoinsCuesWithTooManyLines(): void
    {
        $this->assertSame(["One two three", "four five six"], $this->wrap("One\ntwo three\nfour\nfive six", 15));
    }


    public function testWrapLinesUsesThreeLinesWhenTwoDoNotFit(): void
    {
        $this->assertSame(["one two three", "four five six", "seven eight"],
                          $this->wrap("one two three four five six seven eight", 15, 3));
    }


    public function testWrapLinesKeepsMaxLinesWhenTheTextDoesNotFit(): void
    {
        $this->assertSame(["one two three four", "five six seven eight"],
                          $this->wrap("one two three four five six seven eight", 10));
    }


    public function testWrapLinesDoesNotBreakAWordLongerThanTheLine(): void
    {
        $this->assertSame(["Donaudampfschifffahrtsgesellschaft"], $this->wrap("Donaudampfschifffahrtsgesellschaft", 10));
    }


    public function testWrapLinesClosesAndReopensTagsAtTheBreak(): void
    {
        $this->assertSame(["<i>This is a very long <b>subtitle line</b></i>", "<i>that does not fit</i> on the screen"],
                          $this->wrap("<i>This is a very long <b>subtitle line</b> that does not fit</i> on the screen", 42));
        $this->assertSame(["<i><b>This is a very long subtitle line</b></i>", "<i><b>that does not fit on the screen</b></i>"],
                          $this->wrap("<i><b>This is a very long subtitle line that does not fit on the screen</b></i>", 42));
    }


    public function testWrapLinesDoesNotBreakInsideATag(): void
    {
        $this->assertSame(['<font color="#ff0000">Red text that is long enough</font>',
                           '<font color="#ff0000">to need a break somewhere</font>'],
                          $this->wrap('<font color="#ff0000">Red text that is long enough to need a break somewhere</font>', 30));
        $this->assertSame(["<v Fred>Hello there my friend,</v>", "<v Fred>how are you doing today"],
                          $this->wrap("<v Fred>Hello there my friend, how are you doing today", 30));
    }


    public function testWrapLinesDoesNotCountTagsAndCountsEntitiesAsOneCharacter(): void
    {
        $this->assertSame(["<b>Tom</b> &amp; <i>Jerry</i> &lt;3 <00:00:01.500>coffee"],
                          $this->wrap("<b>Tom</b> &amp; <i>Jerry</i> &lt;3 <00:00:01.500>coffee", 21));
        $this->assertSame(["Tom &amp; Jerry", "&lt;3 coffee"], $this->wrap("Tom &amp; Jerry &lt;3 coffee", 20));
    }


    public function testWrapLinesCountsMultibyteCharactersOnce(): void
    {
        $this->assertSame(["Größere Äpfel überqueren übermütig"],
                          $this->wrap("Größere Äpfel überqueren übermütig", 34));
        $this->assertSame(["Größere Äpfel überqueren übermütig", "die Straße, während Bäume grünen."],
                          $this->wrap("Größere Äpfel überqueren übermütig die Straße, während Bäume grünen.", 42));
    }


    public function testWrapLinesBreaksArabicTextAtSpaces(): void
    {
        $this->assertSame(["هذا سطر طويل من الترجمة لا", "يتسع على الشاشة بشكل جيد"],
                          $this->wrap("هذا سطر طويل من الترجمة لا يتسع على الشاشة بشكل جيد", 42));
    }


    public function testWrapLinesBreaksTextWithoutSpacesBetweenCharacters(): void
    {
        $this->assertSame(["これは字幕の長い行で、", "画面に収まりません。"], $this->wrap("これは字幕の長い行で、画面に収まりません。", 16));
        $this->assertSame(["これは字幕の長い行で、", "画面に収まりません。"], $this->wrap("これは字幕の長い行で、\n画面に収まりません。", 16));
    }


    #[DataProvider('cjkFiles')]
    public function testWrapLinesBreaksChineseAndJapaneseLinesOfARealFile(string $name, int $maxCharactersPerLine): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/files/fixes/$name.srt"), Format::SubRip);

        $subtitle->wrapLines($maxCharactersPerLine);

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/{$name}_wrapped.srt"), $subtitle->toString(Format::SubRip));

        $unwrapped = Subtitle::fromString(file_get_contents(__DIR__ . "/files/fixes/$name.srt"), Format::SubRip)->unwrapLines();
        $this->assertSame($unwrapped->toString(Format::SubRip), $subtitle->unwrapLines()->toString(Format::SubRip));
    }


    public static function cjkFiles(): array
    {
        return [
            "Simplified Chinese at the Netflix limit" => ["own_zh_long_lines", 16],
            "Japanese at the Netflix limit"           => ["own_ja_long_lines", 13],
        ];
    }


    public function testWrapLinesCountsBytesOfInvalidUtf8(): void
    {
        $this->assertSame(["caf\xE9", "au lait"], $this->wrap("caf\xE9 au lait", 8));
    }


    public function testWrapLinesWithZeroMaxCharsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The maximum characters per line and the maximum lines must be at least 1, got 0 and 2.");
        (new Subtitle())->wrapLines(0);
    }


    public function testWrapLinesKeepsEachDialogueTurnOnLinesOfItsOwn(): void
    {
        $this->assertSame(["- Are you coming with", "us to the coast tonight?", "- Yes."],
                          $this->wrap("- Are you coming with us to the coast tonight?\n- Yes.", 42));
        $this->assertSame(["- Are you coming?", "- Yes.", "- Me too."], $this->wrap("- Are you coming?\n- Yes.\n- Me too.", 42));
        $this->assertSame(["Are you coming?", "- Yes, in a minute,", "I promise you."],
                          $this->wrap("Are you coming?\n- Yes, in a minute, I promise you.", 20));
    }


    public function testWrapLinesSplitsDialogueTurnsWithinALine(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "- Are you coming?\n- Yes, in a minute, I promise you."));

        $subtitle->unwrapLines()->wrapLines(42);

        $this->assertSame(["- Are you coming?", "- Yes, in a minute, I promise you."], $subtitle->getCues()[0]->getLines());
        $this->assertSame(["<i>- Are you coming?</i>", "<i>- Yes, in a minute, I promise you.</i>"],
                          $this->wrap("<i>- Are you coming? - Yes, in a minute, I promise you.</i>", 42));
    }


    public function testWrapLinesDoesNotStartATurnAtAMinusSignOrWithinASentence(): void
    {
        $this->assertSame(["- It is very cold outside tonight.", "-20 degrees, they said on the radio."],
                          $this->wrap("- It is very cold outside tonight.\n-20 degrees, they said on the radio.", 42));
        $this->assertSame(["- We walked along the coast - all the", "way to the old harbour lighthouse."],
                          $this->wrap("- We walked along the coast - all the way to the old harbour lighthouse.", 42));
    }


    public function testWrapLinesKeepsDialogueTurnsOfARealFileApart(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/files/fixes/own_dialogue_dashes.srt"), Format::SubRip);

        $subtitle->wrapLines(42);

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_dialogue_dashes_wrapped.srt"),
                          $subtitle->toString(Format::SubRip));
    }


    public function testWrapLinesStartsADialogueTurnAfterAChineseOrJapaneseSentenceEnd(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/files/fixes/own_cjk_dialogue.srt"), Format::SubRip);

        $subtitle->wrapLines(16);

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_cjk_dialogue_wrapped.srt"), $subtitle->toString(Format::SubRip));
    }


    public function testUnwrapLines(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "<i>Please take a seat,</i>\nthe meeting starts."));
        $subtitle->addCue(new SubtitleCue(3, 4, "One line"));
        $subtitle->addCue(new SubtitleCue(5, 6, ""));

        $this->assertSame($subtitle, $subtitle->unwrapLines());
        $this->assertSame([["<i>Please take a seat,</i> the meeting starts."], ["One line"], []],
                          array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
    }


    public function testUnwrapLinesKeepsEachDialogueTurnOnALineOfItsOwn(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "- Are you coming?\n- Yes, in a minute,\nI promise you."));
        $subtitle->addCue(new SubtitleCue(3, 4, "Who is there?\n- Only me."));
        $subtitle->addCue(new SubtitleCue(5, 6, "- It is very cold tonight.\n-20 degrees, they said."));

        $subtitle->unwrapLines();

        $this->assertSame([
            ["- Are you coming?", "- Yes, in a minute, I promise you."],
            ["Who is there?", "- Only me."],
            ["- It is very cold tonight. -20 degrees, they said."],
        ], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
    }


    public function testUnwrapLinesKeepsDialogueTurnsOfARealFileApart(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/files/fixes/own_dialogue_turns_wrapped.srt"), Format::SubRip);

        $subtitle->unwrapLines();

        $this->assertSame(file_get_contents(__DIR__ . "/files/fixes/own_dialogue_turns_unwrapped.srt"),
                          $subtitle->toString(Format::SubRip, new WriteOptions(bom: false)));
    }
}
