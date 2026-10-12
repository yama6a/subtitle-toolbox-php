<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class SubtitleStatisticsTest extends TestCase
{
    private const FILES = __DIR__ . "/files";


    /**
     * @param array<string, int> $counts
     * @return list<array{word: string, count: int}>
     */
    private static function words(array $counts): array
    {
        return array_map(fn (string|int $word, int $count): array => ["word" => (string) $word, "count" => $count],
                         array_keys($counts), $counts);
    }


    public function testStatisticsOfAFourCueFile(): void
    {
        $subtitle   = Subtitle::fromString(file_get_contents(self::FILES . "/statistics/own_bakery.srt"), Format::SubRip);
        $statistics = SubtitleStatistics::of($subtitle);

        $this->assertSame(4, $statistics->cueCount);
        $this->assertSame(26, $statistics->wordCount);
        $this->assertSame(123, $statistics->characterCount);
        $this->assertSame(9.0, $statistics->totalDisplayTime);
        $this->assertSame(11.0, $statistics->span);
        $this->assertEqualsWithDelta(["min" => 38 / 3.5, "average" => (12 + 18.4 + 15 + 38 / 3.5) / 4, "max" => 18.4],
                                     $statistics->charactersPerSecond, 0.0001);
        $this->assertEqualsWithDelta(["min" => 8 / 3.5 * 60, "average" => (150 + 240 + 180 + 8 / 3.5 * 60) / 4, "max" => 240.0],
                                     $statistics->wordsPerMinute, 0.0001);
        $this->assertSame(["min" => 15.0, "average" => 20.5, "max" => 26.0], $statistics->charactersPerLine);
        $this->assertEqualsWithDelta(["min" => -0.5, "average" => 2 / 3, "max" => 2.0], $statistics->gaps, 0.0001);
        $this->assertSame(self::words(["the" => 4, "bread" => 3, "bakery" => 2, "at" => 2]), array_slice($statistics->mostUsedWords, 0, 4));
    }


    // A copy of the line lengths after each cue takes about 20 s here.
    public function testOwnFileRepeatedFifteenThousandTimesUnderFiveSeconds(): void
    {
        $bakery = Subtitle::fromString(file_get_contents(self::FILES . "/statistics/own_bakery.srt"), Format::SubRip);
        $cues   = [];
        for ($copy = 0; $copy < 15000; $copy++) {
            foreach ($bakery->getCues() as $cue) {
                $cues[] = new SubtitleCue($cue->getStart() + 12 * $copy, $cue->getEnd() + 12 * $copy, $cue->getLines());
            }
        }
        $subtitle = TestSubtitles::fromCues($cues);

        $start      = microtime(true);
        $statistics = SubtitleStatistics::of($subtitle);

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertSame([60000, 26 * 15000, 123 * 15000], [$statistics->cueCount, $statistics->wordCount, $statistics->characterCount]);
        $this->assertSame(["min" => 15.0, "average" => 20.5, "max" => 26.0], $statistics->charactersPerLine);
    }


    public function testToArrayEncodesAsJson(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "/statistics/own_bakery.srt"), Format::SubRip);
        $array    = SubtitleStatistics::of($subtitle)->toArray();

        $this->assertSame(["cueCount", "wordCount", "characterCount", "totalDisplayTime", "span", "charactersPerSecond",
                           "wordsPerMinute", "charactersPerLine", "gaps", "mostUsedWords"], array_keys($array));
        $this->assertCount(10, $array["mostUsedWords"]);
        $this->assertSame(["min" => 15.0, "average" => 20.5, "max" => 26.0], $array["charactersPerLine"]);
        $this->assertJson(json_encode($array, JSON_THROW_ON_ERROR));
    }


    public function testSubtitleWithoutCuesGivesZeroCountsAndNullForTheOtherNumbers(): void
    {
        $statistics = SubtitleStatistics::of(new Subtitle());

        $this->assertSame([
            "cueCount"            => 0,
            "wordCount"           => 0,
            "characterCount"      => 0,
            "totalDisplayTime"    => 0.0,
            "span"                => null,
            "charactersPerSecond" => null,
            "wordsPerMinute"      => null,
            "charactersPerLine"   => null,
            "gaps"                => null,
            "mostUsedWords"       => [],
        ], $statistics->toArray());
    }


    public function testOneCueWithoutTextHasASpanButNoGapsAndNoTextNumbers(): void
    {
        $statistics = SubtitleStatistics::of(TestSubtitles::fromCues([[1, 3, "<i> </i>"]]));

        $this->assertSame(2.0, $statistics->span);
        $this->assertNull($statistics->gaps);
        $this->assertNull($statistics->charactersPerSecond);
        $this->assertNull($statistics->wordsPerMinute);
        $this->assertNull($statistics->charactersPerLine);
    }


    public function testImageCueAndCueWithoutTextCountOnlyAsCuesAndInTimes(): void
    {
        $subtitle = TestSubtitles::fromCues([[1, 3, "One two"], [9, 10, "<i> </i>"]]);
        $subtitle->addCue(TestSubtitles::imageCue(new CueImage("png", 0, 0, 1, 1, 720, 576), new SubtitleCue(4, 8)));
        $statistics = SubtitleStatistics::of($subtitle);

        $this->assertSame(3, $statistics->cueCount);
        $this->assertSame(2, $statistics->wordCount);
        $this->assertSame(7, $statistics->characterCount);
        $this->assertSame(7.0, $statistics->totalDisplayTime);
        $this->assertSame(9.0, $statistics->span);
        $this->assertSame(["min" => 3.5, "average" => 3.5, "max" => 3.5], $statistics->charactersPerSecond);
        $this->assertSame(["min" => 60.0, "average" => 60.0, "max" => 60.0], $statistics->wordsPerMinute);
        $this->assertSame(["min" => 7.0, "average" => 7.0, "max" => 7.0], $statistics->charactersPerLine);
        $this->assertSame(["min" => 1.0, "average" => 1.0, "max" => 1.0], $statistics->gaps);
    }


    public function testCueWithoutDurationHasNoReadingSpeed(): void
    {
        $statistics = SubtitleStatistics::of(TestSubtitles::fromCues([[1, 1, "Rain"], [2, 4, "Sun today"]]));

        $this->assertSame(13, $statistics->characterCount);
        $this->assertSame(["min" => 4.5, "average" => 4.5, "max" => 4.5], $statistics->charactersPerSecond);
        $this->assertSame(["min" => 60.0, "average" => 60.0, "max" => 60.0], $statistics->wordsPerMinute);
    }


    public function testCountsCharactersLikeValidation(): void
    {
        $statistics = SubtitleStatistics::of(TestSubtitles::fromCues([
            [0, 2, ["<v Anna> Gr\u{fc}\u{df}e &amp; <00:00:01.000>Tee </v>", "\u{4f60}\u{597d}"]],
        ]));

        $this->assertSame(13, $statistics->characterCount);
        $this->assertSame(4, $statistics->wordCount);
        $this->assertSame(["min" => 2.0, "average" => 6.5, "max" => 11.0], $statistics->charactersPerLine);
    }


    public function testMostUsedWordsIgnoreCaseAndOuterPunctuation(): void
    {
        $statistics = SubtitleStatistics::of(TestSubtitles::fromCues([
            [0, 2, ["- \u{c4}pfel? \"Apples!\"", "\u{e4}pfel, don't... apples"]],
            [3, 4, "Don't stop -- 12:30."],
        ]));

        $this->assertSame(10, $statistics->wordCount);
        $this->assertSame(self::words(["\u{e4}pfel" => 2, "apples" => 2, "don't" => 2, "stop" => 1, "12:30" => 1]),
                          $statistics->mostUsedWords);
        $this->assertSame([["word" => "12:30", "count" => 1]], array_slice($statistics->mostUsedWords, 4));
    }


    public function testAWordOfDigitsStaysAString(): void
    {
        $statistics = SubtitleStatistics::of(TestSubtitles::fromCues([[0, 2, "2024 2024"]]));

        $this->assertSame([["word" => "2024", "count" => 2]], array_slice($statistics->mostUsedWords, 0, 1));
    }


    public function testCountsInvalidUtf8ByBytes(): void
    {
        $content    = "{1}{1}25.000\n{25}{75}Caf\xe9 au lait\n";
        $statistics = SubtitleStatistics::of((new MicroDvdParser())->parse($content, new ReadOptions()));

        $this->assertSame(1, $statistics->cueCount);
        $this->assertSame(3, $statistics->wordCount);
        $this->assertSame(12, $statistics->characterCount);
        $this->assertSame(self::words(["caf\xe9" => 1, "au" => 1, "lait" => 1]), $statistics->mostUsedWords);
    }
}
