<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\Validation\ValidationRules;

class ValidationTest extends TestCase
{
    private const FILES = __DIR__ . "/files";


    /**
     * @param list<ValidationViolation> $results
     */
    private function toArrays(array $results): array
    {
        return array_map(fn (ValidationViolation $result): array => [
            $result->cueIndex,
            $result->rule,
            $result->value,
            $result->limit,
        ], $results);
    }


    public function testNoRulesGiveNoResults(): void
    {
        $subtitle = TestFiles::parse("validation/own_netflix_checks.srt", Format::SubRip);

        $this->assertSame([], $subtitle->validate(new ValidationRules()));
    }


    public function testNetflixEnglishPresetOnOwnFile(): void
    {
        $subtitle = TestFiles::parse("validation/own_netflix_checks.srt", Format::SubRip);

        $this->assertCount(7, $subtitle->getCues());
        $this->assertSame([
            [1, ValidationRule::MaxCharactersPerLine, 45, 42],
            [1, ValidationRule::MaxCharactersPerSecond, 112.5, 20.0],
            [1, ValidationRule::MinDuration, 0.4, 5 / 6],
            [1, ValidationRule::MinGap, 0.04, 2 / 24],
            [2, ValidationRule::MaxCharactersPerLine, 49, 42],
            [2, ValidationRule::MaxLinesPerCue, 3, 2],
            [2, ValidationRule::MaxDuration, 7.5, 7.0],
            [3, ValidationRule::NoOverlap, 0.5, null],
        ], $this->toArrays($subtitle->validate(ValidationRules::netflixEnglish(24))));
    }


    public function testNetflixEnglishPresetOnRealSubRipFile(): void
    {
        $subtitle = TestFiles::parse("srt/real/language_subtitles_dots_tester.srt", Format::SubRip);

        $this->assertSame([
            [0, ValidationRule::MaxCharactersPerLine, 62, 42],
            [0, ValidationRule::MaxCharactersPerSecond, 98 / 1.999, 20.0],
            [1, ValidationRule::MinGap, 0.001, 2 / 24],
            [2, ValidationRule::NoOverlap, 1.999, null],
        ], $this->toArrays($subtitle->validate(ValidationRules::netflixEnglish(24))));
    }


    public function testNetflixEnglishPresetOnRealWebVttFile(): void
    {
        $subtitle = TestFiles::parse("vtt/real/w3c_comments.vtt", Format::WebVtt);

        $this->assertSame([], $subtitle->validate(ValidationRules::netflixEnglish(24)));
        $this->assertSame(
            [[1, ValidationRule::MaxCharactersPerLine, 33, 30]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerLine: 30)))
        );
    }


    public function testNetflixEnglishPresetValues(): void
    {
        $rules = ValidationRules::netflixEnglish(25);

        $this->assertSame(20.0, $rules->maxCharactersPerSecond);
        $this->assertSame(42, $rules->maxCharactersPerLine);
        $this->assertSame(2, $rules->maxLinesPerCue);
        $this->assertSame(5 / 6, $rules->minDuration);
        $this->assertSame(7.0, $rules->maxDuration);
        $this->assertSame(0.08, $rules->minGap);
        $this->assertTrue($rules->noOverlap);
        $this->assertFalse($rules->noEmptyCues);
    }


    public function testEmptyCueRule(): void
    {
        $subtitle = TestFiles::parse("validation/own_netflix_checks.srt", Format::SubRip);

        $this->assertSame(
            [[6, ValidationRule::NoEmptyCues, 0, null]],
            $this->toArrays($subtitle->validate(new ValidationRules(noEmptyCues: true)))
        );
    }


    public function testCountsCharactersWithoutMarkupAndWithMultibyteLetters(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 10, ["<b>Größe: zwölf Äpfel, dreißig Birnen, sechs.</b>", "<i>Fish &amp; chips</i>"]],
        ]);

        $this->assertSame([], $subtitle->validate(new ValidationRules(maxCharactersPerLine: 42)));
        $this->assertSame(
            [[0, ValidationRule::MaxCharactersPerLine, 42, 41]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerLine: 41)))
        );
        $this->assertSame(
            [[0, ValidationRule::MaxCharactersPerSecond, 5.4, 5.0]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerSecond: 5)))
        );
    }


    public function testCountsBytesOfInvalidUtf8(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 1, "a\xff\xfe"]]);

        $this->assertSame(
            [[0, ValidationRule::MaxCharactersPerLine, 3, 2]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerLine: 2)))
        );
    }


    public function testLinesWithOnlyMarkupDoNotCount(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 1, ["One", "<i> </i>", "Two"]]]);

        $this->assertSame([], $subtitle->validate(new ValidationRules(maxLinesPerCue: 2)));
    }


    public function testZeroDurationGivesInfiniteReadingSpeed(): void
    {
        $subtitle = TestSubtitles::fromCues([[5, 5, "Hi"], [6, 6, ""]]);

        $this->assertSame(
            [[0, ValidationRule::MaxCharactersPerSecond, INF, 20.0]],
            $this->toArrays($subtitle->validate(new ValidationRules(maxCharactersPerSecond: 20)))
        );
    }


    public function testLimitsMatchCueTimesInMilliseconds(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 0.833, "a"], [0.916, 1.749, "b"], [1.831, 2.664, "c"]]);

        $this->assertSame(
            [[2, ValidationRule::MinGap, 0.082, 2 / 24]],
            $this->toArrays($subtitle->validate(new ValidationRules(minDuration: 5 / 6, minGap: 2 / 24)))
        );
    }


    public function testOverlapUsesLatestEndOfAllEarlierCues(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 10, "long"], [2, 3, "short"], [5, 6, "inside"], [10, 11, "after"]]);

        $this->assertSame([
            [1, ValidationRule::NoOverlap, 8.0, null],
            [2, ValidationRule::NoOverlap, 5.0, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(minGap: 0, noOverlap: true))));
    }


    public function testGapRuleSkipsOverlappingCues(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 2, "a"], [1, 3, "b"], [3, 4, "c"]]);

        $this->assertSame(
            [[2, ValidationRule::MinGap, 0.0, 0.5]],
            $this->toArrays($subtitle->validate(new ValidationRules(minGap: 0.5)))
        );
    }


    public function testTextRulesOnOwnFile(): void
    {
        $subtitle = TestFiles::parse("validation/own_text_checks.vtt", Format::WebVtt);
        $rules    = new ValidationRules(
            noDoubleSpaces: true,
            noLeadingOrTrailingSpaces: true,
            noUnbalancedTags: true,
            dialogueDashStyle: DialogueDashStyle::HyphenSpace,
            maxSpeakersPerCue: 2,
            maxWordsPerMinute: 180,
            minSecondsPerWord: 0.3,
            noAllCapsLines: true,
        );

        $this->assertCount(8, $subtitle->getCues());
        $this->assertSame([
            [0, ValidationRule::NoDoubleSpaces, 1, null],
            [0, ValidationRule::NoUnbalancedTags, 1, null],
            [0, ValidationRule::DialogueDashStyle, 1, null],
            [0, ValidationRule::MaxSpeakersPerCue, 3, 2],
            [0, ValidationRule::MaxWordsPerMinute, 480.0, 180.0],
            [0, ValidationRule::MinSecondsPerWord, 0.125, 0.3],
            [1, ValidationRule::NoDoubleSpaces, 1, null],
            [1, ValidationRule::NoLeadingOrTrailingSpaces, 2, null],
            [3, ValidationRule::NoAllCapsLines, 1, null],
            [4, ValidationRule::DialogueDashStyle, 2, null],
            [6, ValidationRule::NoUnbalancedTags, 2, null],
        ], $this->toArrays($subtitle->validate($rules)));
    }


    public function testTextRulesAreOffByDefault(): void
    {
        $subtitle = TestFiles::parse("validation/own_text_checks.vtt", Format::WebVtt);

        $this->assertSame([], $subtitle->validate(new ValidationRules()));
    }


    public function testOneCueBreaksSixTextRules(): void
    {
        $subtitle = TestSubtitles::fromCues([[10, 11, ["-Where are you?\u{00A0} <i>Home", "- Wait.", "- Now!"]]]);
        $rules    = new ValidationRules(
            noDoubleSpaces: true,
            noUnbalancedTags: true,
            dialogueDashStyle: DialogueDashStyle::HyphenSpace,
            maxSpeakersPerCue: 2,
            maxWordsPerMinute: 180,
            minSecondsPerWord: 0.3,
        );

        $this->assertSame([
            [0, ValidationRule::NoDoubleSpaces, 1, null],
            [0, ValidationRule::NoUnbalancedTags, 1, null],
            [0, ValidationRule::DialogueDashStyle, 1, null],
            [0, ValidationRule::MaxSpeakersPerCue, 3, 2],
            [0, ValidationRule::MaxWordsPerMinute, 480.0, 180.0],
            [0, ValidationRule::MinSecondsPerWord, 0.125, 0.3],
        ], $this->toArrays($subtitle->validate($rules)));
    }


    public function testWordsMatchSubtitleStatistics(): void
    {
        $subtitle = TestFiles::parse("vtt/real/webvttpy_netflix.vtt", Format::WebVtt);
        $words    = 0;
        foreach ($subtitle->validate(new ValidationRules(maxWordsPerMinute: 0.001)) as $result) {
            $cue    = $subtitle->getCues()[$result->cueIndex];
            $words += (int)round($result->value * round($cue->getEnd() - $cue->getStart(), 3) / 60);
        }

        $this->assertSame(SubtitleStatistics::of($subtitle)->wordCount, $words);
    }


    public function testBbcPresetValues(): void
    {
        $rules = ValidationRules::bbc();

        $this->assertSame(37, $rules->maxCharactersPerLine);
        $this->assertSame(180.0, $rules->maxWordsPerMinute);
        $this->assertSame(0.3, $rules->minSecondsPerWord);
        $this->assertNull($rules->maxCharactersPerSecond);
        $this->assertNull($rules->maxLinesPerCue);
        $this->assertFalse($rules->noOverlap);
    }


    public function testBbcPresetOnRealWebVttFile(): void
    {
        $subtitle = TestFiles::parse("vtt/real/w3c_voices.vtt", Format::WebVtt);

        $this->assertSame([
            [1, ValidationRule::MaxCharactersPerLine, 55, 37],
            [1, ValidationRule::MaxWordsPerMinute, 200.0, 180.0],
            [2, ValidationRule::MaxCharactersPerLine, 39, 37],
            [2, ValidationRule::MaxWordsPerMinute, 210.0, 180.0],
            [2, ValidationRule::MinSecondsPerWord, 2 / 7, 0.3],
            [6, ValidationRule::MaxWordsPerMinute, 240.0, 180.0],
            [6, ValidationRule::MinSecondsPerWord, 0.25, 0.3],
            [7, ValidationRule::MaxCharactersPerLine, 61, 37],
            [7, ValidationRule::MaxWordsPerMinute, 260.0, 180.0],
            [7, ValidationRule::MinSecondsPerWord, 3 / 13, 0.3],
            [8, ValidationRule::MaxWordsPerMinute, 200.0, 180.0],
            [9, ValidationRule::MaxCharactersPerLine, 47, 37],
            [9, ValidationRule::MaxWordsPerMinute, 270.0, 180.0],
            [9, ValidationRule::MinSecondsPerWord, 2 / 9, 0.3],
            [10, ValidationRule::MaxCharactersPerLine, 42, 37],
            [12, ValidationRule::MaxCharactersPerLine, 58, 37],
            [12, ValidationRule::MaxWordsPerMinute, 288.0, 180.0],
            [12, ValidationRule::MinSecondsPerWord, 2.5 / 12, 0.3],
        ], $this->toArrays($subtitle->validate(ValidationRules::bbc())));
    }


    public function testSpeakersFromVoicesAndDashesOnRealWebVttFiles(): void
    {
        $voices  = TestFiles::parse("vtt/real/w3c_voices.vtt", Format::WebVtt);
        $netflix = TestFiles::parse("vtt/real/webvttpy_netflix.vtt", Format::WebVtt);

        $this->assertSame([], $voices->validate(new ValidationRules(maxSpeakersPerCue: 1)));
        $this->assertSame(
            [[0, ValidationRule::MaxSpeakersPerCue, 2, 1]],
            $this->toArrays(TestSubtitles::fromCues([[0, 1, ["<v Anna>Hi", "<v.loud Tom>Hello", "<v Anna>Bye"]]])
                ->validate(new ValidationRules(maxSpeakersPerCue: 1)))
        );
        $this->assertSame(
            [[17, ValidationRule::MaxSpeakersPerCue, 2, 1]],
            $this->toArrays($netflix->validate(new ValidationRules(maxSpeakersPerCue: 1)))
        );
        $this->assertSame([], $netflix->validate(new ValidationRules(dialogueDashStyle: DialogueDashStyle::HyphenSpace)));
        $this->assertSame(
            [[17, ValidationRule::DialogueDashStyle, 2, null]],
            $this->toArrays($netflix->validate(new ValidationRules(dialogueDashStyle: DialogueDashStyle::EnDashSpace)))
        );
    }


    public function testUnbalancedTagsOnRealSubRipFile(): void
    {
        $subtitle = TestFiles::parse("srt/real/own_styled.srt", Format::SubRip);

        $this->assertSame(
            [[7, ValidationRule::NoUnbalancedTags, 1, null]],
            $this->toArrays($subtitle->validate(new ValidationRules(noUnbalancedTags: true)))
        );
    }


    public function testUnbalancedTagsAcrossLinesAndOpenVoices(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 1, ["<v Anna><i>Over two", "lines</i>"]],
            [1, 2, ["<b><i>Crossed</b></i>"]],
            [2, 3, ["Stray</u> and <font color=\"#ff0000\">open"]],
            [3, 4, ["<c.red>Other</c> tags and <00:00:03.500>word timestamps"]],
            [4, 5, ["Text &lt;i&gt; that looks like a tag"]],
        ]);

        $this->assertSame([
            [1, ValidationRule::NoUnbalancedTags, 2, null],
            [2, ValidationRule::NoUnbalancedTags, 2, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noUnbalancedTags: true))));
    }


    public function testUnbalancedWebVttTagsOnOwnFile(): void
    {
        $subtitle = TestFiles::parse("validation/own_webvtt_tags.vtt", Format::WebVtt);

        $this->assertCount(9, $subtitle->getCues());
        $this->assertSame([
            [0, ValidationRule::NoUnbalancedTags, 1, null],
            [1, ValidationRule::NoUnbalancedTags, 1, null],
            [3, ValidationRule::NoUnbalancedTags, 1, null],
            [5, ValidationRule::NoUnbalancedTags, 1, null],
            [8, ValidationRule::NoUnbalancedTags, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noUnbalancedTags: true))));
    }


    public function testUnbalancedRubyTextTags(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 1, ["<ruby>a<rt>b<rt>c"]],
            [1, 2, ["<ruby>a<rt><i>b</ruby>"]],
            [2, 3, ["<b>a<rt>b</rt></b>", "<rt>c"]],
            [3, 4, ["<ruby>a", "<rt>b</rt></ruby>"]],
        ]);

        $this->assertSame([
            [0, ValidationRule::NoUnbalancedTags, 2, null],
            [1, ValidationRule::NoUnbalancedTags, 1, null],
            [2, ValidationRule::NoUnbalancedTags, 3, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noUnbalancedTags: true))));
    }


    public function testSpacesInsideTagsAndNonBreakingSpaces(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 1, ["One <i> two</i>", "<b>three </b>"]],
            [1, 2, ["Four\u{00A0}\u{00A0}five", "\u{00A0}six"]],
            [2, 3, ["No <i>problem</i> here"]],
        ]);

        $this->assertSame([
            [0, ValidationRule::NoDoubleSpaces, 1, null],
            [0, ValidationRule::NoLeadingOrTrailingSpaces, 1, null],
            [1, ValidationRule::NoDoubleSpaces, 1, null],
            [1, ValidationRule::NoLeadingOrTrailingSpaces, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noDoubleSpaces: true, noLeadingOrTrailingSpaces: true))));
    }


    public function testDialogueDashStyle(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 1, ["- Yes.", "-No."]],
            [1, 2, ["<i>-Maybe.</i>", "\u{2014}Never."]],
            [2, 3, ["-20 degrees.", "--- a line", "- "]],
        ]);

        $this->assertSame([
            [0, ValidationRule::DialogueDashStyle, 1, null],
            [1, ValidationRule::DialogueDashStyle, 2, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(dialogueDashStyle: DialogueDashStyle::HyphenSpace))));
        $this->assertSame([
            [0, ValidationRule::DialogueDashStyle, 1, null],
            [1, ValidationRule::DialogueDashStyle, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(dialogueDashStyle: DialogueDashStyle::Hyphen))));
    }


    public function testWordRulesSkipEmptyCuesAndMatchMilliseconds(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 1.2, "Four words right here"], [2, 2, "Now"], [3, 4, ""], [5, 6.199, "Four words too fast"]]);

        $this->assertSame([
            [1, ValidationRule::MaxWordsPerMinute, INF, 200.0],
            [1, ValidationRule::MinSecondsPerWord, 0.0, 0.3],
            [3, ValidationRule::MaxWordsPerMinute, 240 / 1.199, 200.0],
            [3, ValidationRule::MinSecondsPerWord, 1.199 / 4, 0.3],
        ], $this->toArrays($subtitle->validate(new ValidationRules(maxWordsPerMinute: 200, minSecondsPerWord: 0.3))));
    }


    public function testAllowedCharactersAsStringAndCharacterClass(): void
    {
        $subtitle = TestFiles::parse("validation/own_text_checks.vtt", Format::WebVtt);
        // BBC Subtitle Guidelines 9.3.1, characters for broadcast.
        $broadcast = "[A-Za-z0-9!)(,.?:\\-><&@#%+*=/\u{00A3}\$\u{00A2}\u{00A5}\u{00A9}\u{00AE}\u{00BC}\u{00BD}\u{00BE}\u{2122}'\"]";

        $this->assertSame([
            [1, ValidationRule::AllowedCharacters, 1, null],
            [3, ValidationRule::AllowedCharacters, 2, null],
            [4, ValidationRule::AllowedCharacters, 2, null],
            [5, ValidationRule::AllowedCharacters, 4, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(allowedCharacters: $broadcast))));
        $this->assertSame(
            [[0, ValidationRule::AllowedCharacters, 5, null]],
            $this->toArrays(TestSubtitles::fromCues([[0, 1, "Caf\u{00E9} a/b [c]"]])
                ->validate(new ValidationRules(allowedCharacters: "Cafab")))
        );
        $this->assertSame([], TestSubtitles::fromCues([[0, 1, "a/b"]])->validate(new ValidationRules(allowedCharacters: "[a-z/]")));
    }


    public function testAllCapsLinesSkipVoiceNamesAndBrackets(): void
    {
        $subtitle = TestSubtitles::fromCues([
            [0, 1, ["<v ANNA>Hello.", "[BELL RINGS]", "(SHOUTS) Stop!", "I"]],
            [1, 2, ["STOP THE TRAIN!", "<i>NOW</i>", "\u{00C4}RGER"]],
            [2, 3, ["(SHOUTING) STOP"]],
        ]);

        $this->assertSame([
            [1, ValidationRule::NoAllCapsLines, 3, null],
            [2, ValidationRule::NoAllCapsLines, 1, null],
        ], $this->toArrays($subtitle->validate(new ValidationRules(noAllCapsLines: true))));
    }


    public function testTextRulesOnInvalidUtf8(): void
    {
        $subtitle = TestSubtitles::fromCues([[0, 1, "CAF\xC9 \xC9T\xC9"]]);
        $rules    = new ValidationRules(noDoubleSpaces: true, allowedCharacters: "[A-Za-z]", noAllCapsLines: true);

        $this->assertSame([
            [0, ValidationRule::AllowedCharacters, 3, null],
            [0, ValidationRule::NoAllCapsLines, 1, null],
        ], $this->toArrays($subtitle->validate($rules)));
    }
}
