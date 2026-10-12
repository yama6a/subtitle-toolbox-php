<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class ProfanityFilterTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/profanity/";


    /**
     * @param list<string> $lines
     * @return array{list<string>, list<array{float, float}>}
     */
    private static function filterLines(array $lines, ProfanityOptions $options): array
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, $lines));
        $ranges   = ProfanityFilter::apply($subtitle, $options)->muteRanges;
        $cues     = $subtitle->getCues();

        return [$cues === [] ? [] : array_values($cues[0]->getLines()), self::times($ranges)];
    }


    /**
     * @param list<MuteRange> $ranges
     * @return list<array{float, float}>
     */
    private static function times(array $ranges): array
    {
        return array_map(fn (MuteRange $range): array => [$range->start, $range->end], $ranges);
    }


    public function testFirstLetterMaskKeepsWordTimestampsAndPadsTheMuteRange(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:01:02.000 --> 00:01:04.000\n" .
                                    "<00:01:02.000>What <00:01:02.300>the <00:01:02.480>hell <00:01:02.800>is this?\n",
                                    Format::WebVtt);

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(
            words: ["hell", "damn*"],
            mask: ProfanityMask::FirstLetter,
            padding: 0.1,
        ))->muteRanges;

        $this->assertSame(["<00:01:02.000>What <00:01:02.300>the <00:01:02.480>h*** <00:01:02.800>is this?"],
                          $subtitle->getCues()[0]->getLines());
        $this->assertSame([[62.38, 62.9]], self::times($ranges));
        $this->assertSame("62.380 62.900 1\n", MuteRange::toEdl($ranges));
        $this->assertSame("volume=enable='between(t,62.380,62.900)':volume=0", MuteRange::toFfmpegVolumeFilter($ranges));
    }


    /**
     * @return array<string, array{list<string>, list<string>, string|\Closure, list<string>}>
     */
    public static function maskCases(): array
    {
        return [
            "stars"                   => [["hell"], ["What the hell?"], ProfanityMask::Stars, ["What the ****?"]],
            "first letter keeps case" => [["hell"], ["HELL no"], ProfanityMask::FirstLetter, ["H*** no"]],
            "remove"                  => [["hell"], ["What the hell is this?"], ProfanityMask::Remove, ["What the is this?"]],
            "none"                    => [["hell"], ["What the hell?"], ProfanityMask::None, ["What the hell?"]],
            "callback"                => [["hell"], ["What the hell?"], fn (string $word): string => "[$word]", ["What the [hell]?"]],
            "callback with markup"    => [["hell"], ["What the hell?"], fn (string $word): string => "<$word>", ["What the &lt;hell&gt;?"]],
            "case-insensitive"        => [["HeLL"], ["hell and Hell"], ProfanityMask::Stars, ["**** and ****"]],
            "whole word only"         => [["hell"], ["Hello, shell, hell's"], ProfanityMask::Stars, ["Hello, shell, ****'s"]],
            "wildcard ending"         => [["damn*"], ["Damn, damned, damnit, condemn"], ProfanityMask::Stars,
                                          ["****, ******, ******, condemn"]],
            "unicode letters"         => [["hölle", "scheiß*"], ["Zur HÖLLE, Scheißkerl!"], ProfanityMask::FirstLetter,
                                          ["Zur H****, S*********!"]],
            "unicode word boundary"   => [["hell"], ["Bellhellé hell"], ProfanityMask::Stars, ["Bellhellé ****"]],
            "combining mark counted"  => [["cafe\u{0301}"], ["Le cafe\u{0301}"], ProfanityMask::Stars, ["Le ****"]],
            "phrase"                  => [["son of a"], ["You son of a gun"], ProfanityMask::Stars, ["You ******** gun"]],
            "inside tags"             => [["hell"], ["<i>What the hell</i>"], ProfanityMask::Stars, ["<i>What the ****</i>"]],
            "entities stay escaped"   => [["hell"], ["hell &amp; &lt;b&gt;"], ProfanityMask::Stars, ["**** &amp; &lt;b&gt;"]],
        ];
    }


    /**
     * @param list<string> $words
     * @param list<string> $lines
     * @param list<string> $expected
     */
    #[DataProvider("maskCases")]
    public function testMasks(array $words, array $lines, ProfanityMask|\Closure $mask, array $expected): void
    {
        [$actual, $ranges] = self::filterLines($lines, new ProfanityOptions($words, $mask));

        $this->assertSame($expected, $actual);
        $this->assertSame([[1.0, 2.0]], $ranges);
    }


    public function testNoMatchChangesNothing(): void
    {
        $this->assertSame([["Hello there"], []], self::filterLines(["Hello there"], new ProfanityOptions(["hell"])));
    }


    public function testRemoveDeletesACueWithoutOtherText(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "<i>Damn</i>"))
            ->addCue(new SubtitleCue(3, 4, "Fine."));

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn"], ProfanityMask::Remove))->muteRanges;

        $this->assertSame([[1.0, 2.0]], self::times($ranges));
        $this->assertSame([0], array_keys($subtitle->getCues()));
        $this->assertSame(["Fine."], $subtitle->getCues()[0]->getLines());
    }


    public function testWordTimestampsSetTheRange(): void
    {
        $cue = new SubtitleCue(10, 14, [
            "Damn <00:00:10.500>this <00:00:11.000>hell",
            "<00:00:12.000>is <00:00:12.500>hot.",
        ]);
        $subtitle = (new Subtitle())->addCue($cue);

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn", "hell", "hot"]))->muteRanges;

        $this->assertSame([[10.0, 10.5], [11.0, 12.0], [12.5, 14.0]], self::times($ranges));
    }


    public function testRangesThatTouchOrOverlapJoin(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "hell"))
            ->addCue(new SubtitleCue(2, 3, "hell"))
            ->addCue(new SubtitleCue(2.5, 4, "hell"))
            ->addCue(new SubtitleCue(5, 6, "hell"))
            ->addCue(new SubtitleCue(6.3, 7, "hell"));

        $this->assertSame([[1.0, 4.0], [5.0, 6.0], [6.3, 7.0]],
                          self::times(ProfanityFilter::apply($subtitle, new ProfanityOptions(["hell"]))->muteRanges));
    }


    public function testPaddingJoinsRangesAndStopsAtZero(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(0.1, 1, "hell"))
            ->addCue(new SubtitleCue(1.3, 2, "hell"));

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(["hell"], padding: 0.2))->muteRanges;

        $this->assertSame([[0.0, 2.2]], self::times($ranges));
    }


    public function testCuesWithoutTextAreSkipped(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, []));

        $this->assertSame([], ProfanityFilter::apply($subtitle, new ProfanityOptions(["hell"]))->muteRanges);
    }


    public function testRealSubRipFileMatchesTheLastCue(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "keys.srt"), Format::SubRip);

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"]))->muteRanges;

        $this->assertCount(7, $subtitle->getCues());
        $this->assertSame([[3.4, 5.0], [8.0, 9.1], [11.5, 13.0], [13.2, 15.6]], self::times($ranges));
        $cues = array_values($subtitle->getCues());
        $this->assertSame(["Where did I put the keys?"], $cues[0]->getLines());
        $this->assertSame(["- Hello?", "- Go to ****."], $cues[5]->getLines());
        $this->assertSame(["That ****** door again."], $cues[6]->getLines());
        $this->assertSame(13.2, $cues[6]->getStart());
        $this->assertSame(15.6, $cues[6]->getEnd());

        $expected = str_replace(["Damn it", "the hell", "to hell", "damned"], ["**** it", "the ****", "to ****", "******"],
                                file_get_contents(self::FILES . "keys.srt"));
        $this->assertSame($expected, $subtitle->toString(Format::SubRip, new WriteOptions(lineEnding: LineEnding::Crlf, bom: false)));
        $this->assertSame([[3.4, 5.0], [8.0, 9.1], [11.5, 13.0], [13.2, 15.6]], self::times(ProfanityFilter::apply(
            Subtitle::fromString(file_get_contents(self::FILES . "keys.srt"), Format::SubRip),
            new ProfanityOptions(["damn*", "hell"], ProfanityMask::None)
        )->muteRanges));
    }


    public function testRealSubRipFileWithPaddingJoinsTheLastRanges(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "keys.srt"), Format::SubRip);

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"], padding: 0.25))->muteRanges;

        $this->assertSame([[3.15, 5.25], [7.75, 9.35], [11.25, 15.85]], self::times($ranges));
        $this->assertSame("3.150 5.250 1\n7.750 9.350 1\n11.250 15.850 1\n", MuteRange::toEdl($ranges));
    }


    public function testRealWebVttFileWithWordTimestamps(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "radio.vtt"), Format::WebVtt);

        $ranges = ProfanityFilter::apply($subtitle, new ProfanityOptions(["damn*", "hell"], ProfanityMask::FirstLetter))->muteRanges;

        $cues = array_values($subtitle->getCues());
        $this->assertCount(4, $cues);
        $this->assertSame([[1.6, 2.0], [6.3, 6.7], [8.0, 9.0]], self::times($ranges));
        $this->assertSame(["<00:00:01.000>Turn <00:00:01.400>the <00:00:01.600>d*** <00:00:02.000>radio <00:00:02.500>off."],
                          $cues[0]->getLines());
        $this->assertSame(["<00:00:07.500>Fine, <00:00:08.000>d*****."], $cues[3]->getLines());
        $this->assertSame(7.5, $cues[3]->getStart());
        $this->assertSame(9.0, $cues[3]->getEnd());

        $expected = str_replace(["damn <", "hell <", "damnit."], ["d*** <", "h*** <", "d*****."],
                                file_get_contents(self::FILES . "radio.vtt"));
        $this->assertSame($expected, $subtitle->toString(Format::WebVtt, new WriteOptions(bom: false)));
        $this->assertSame("volume=enable='between(t,1.600,2.000)+between(t,6.300,6.700)+between(t,8.000,9.000)':volume=0",
                          MuteRange::toFfmpegVolumeFilter($ranges));
    }


    public function testEmptyRangeListWritesEmptyStrings(): void
    {
        $this->assertSame("", MuteRange::toEdl([]));
        $this->assertSame("", MuteRange::toFfmpegVolumeFilter([]));
    }


    /**
     * @return array<string, array{\Closure}>
     */
    public static function invalidOptions(): array
    {
        return [
            "empty list"         => [fn () => new ProfanityOptions([])],
            "empty word"         => [fn () => new ProfanityOptions([""])],
            "star in the middle" => [fn () => new ProfanityOptions(["f*ck"])],
            "only a star"        => [fn () => new ProfanityOptions(["*"])],
            "space at the end"   => [fn () => new ProfanityOptions(["hell "])],
            "no string"          => [fn () => new ProfanityOptions([5])],
            "invalid UTF-8"      => [fn () => new ProfanityOptions(["h\xE9ll"])],
            "negative padding"   => [fn () => new ProfanityOptions(["hell"], padding: -0.1)],
        ];
    }


    #[DataProvider("invalidOptions")]
    public function testInvalidOptionsThrow(\Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }
}
