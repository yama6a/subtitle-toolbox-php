<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Application;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\SyncPoint;

class WordTimestampRetimingTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    private static function load(string $path, Format $format): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . $path), $format);
    }


    public function testShiftMovesWebVttWordTimestamps(): void
    {
        $subtitle = self::load("profanity/radio.vtt", Format::WebVtt)->shift(10);

        $this->assertSame([11.0, 13.0], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
        $this->assertSame(["<00:00:11.000>Turn <00:00:11.400>the <00:00:11.600>damn <00:00:12.000>radio <00:00:12.500>off."],
                          $subtitle->getCues()[0]->getLines());
        $this->assertStringContainsString("00:00:15.500 --> 00:00:17.000\n<00:00:15.500>It <00:00:15.700>sounds <00:00:16.100>like\n" .
                                          "<00:00:16.300>hell <00:00:16.700>to <00:00:16.800>me.\n",
                                          $subtitle->toString(Format::WebVtt));
    }


    public function testShiftFromTimeMovesOnlyTheWordsOfLaterCues(): void
    {
        $subtitle = self::load("profanity/radio.vtt", Format::WebVtt)->shift(-1, 5);

        $this->assertSame("<00:00:01.000>Turn", explode(" ", $subtitle->getCues()[0]->getText())[0]);
        $this->assertSame(["<00:00:06.500>Fine, <00:00:07.000>damnit."], $subtitle->getCues()[3]->getLines());
    }


    public function testShiftClampsWordTimestampsAtZero(): void
    {
        $subtitle = self::load("profanity/radio.vtt", Format::WebVtt)->shift(-1.5);

        $this->assertSame(["<00:00:00.000>Turn <00:00:00.000>the <00:00:00.100>damn <00:00:00.500>radio <00:00:01.000>off."],
                          $subtitle->getCues()[0]->getLines());
    }


    public function testScaleMovesEnhancedLrcWordTimestamps(): void
    {
        $subtitle = self::load("lrc/real/handwritten-enhanced.lrc", Format::Lyrics)->scale(2);

        $this->assertStringContainsString("[00:06.00] <00:06.00> Slow <00:07.10> river <00:08.80> runs\n",
                                          $subtitle->toString(Format::Lyrics));
    }


    public function testFrameRateConversionMovesAssKaraoke(): void
    {
        $subtitle = self::load("ass/real/own_aegisub.ass", Format::Ass)->convertFrameRate(25, 50);
        $karaoke  = array_values(array_filter(explode("\n", $subtitle->toString(Format::Ass)),
                                              fn (string $line): bool => str_contains($line, "{\\k")));

        $this->assertSame(["Dialogue: 0,0:00:06.75,0:00:08.00,Karaoke,,0,0,0,,{\\k20}The {\\k18}train {\\k25}leaves {\\k30}at {\\k32}noon"],
                          array_map("rtrim", $karaoke));
    }


    public function testShiftedLibraryJsonKeepsTheMovedWordTimestamps(): void
    {
        $subtitle = self::load("profanity/radio.vtt", Format::WebVtt)->shift(60);
        $copy     = Subtitle::fromString($subtitle->toString(Format::Json), Format::Json);

        $this->assertSame(["<00:01:07.500>Fine, <00:01:08.000>damnit."], $copy->getCues()[3]->getLines());
    }


    public function testMuteRangesUseTheShiftedWordTimestamps(): void
    {
        $subtitle = self::load("profanity/radio.vtt", Format::WebVtt)->shift(100);
        $ranges   = ProfanityFilter::apply($subtitle, new ProfanityOptions(words: ["damn"]))->muteRanges;

        $this->assertSame([[101.6, 102.0]], array_map(fn (MuteRange $range): array => [$range->start, $range->end], $ranges));
    }


    public function testSyncByPointsMovesWordTimestamps(): void
    {
        $subtitle = self::load("profanity/radio.vtt", Format::WebVtt)->syncByPoints([new SyncPoint(1, 2), new SyncPoint(7.5, 15)]);

        $this->assertSame(["<00:00:02.000>Turn <00:00:02.800>the <00:00:03.200>damn <00:00:04.000>radio <00:00:05.000>off."],
                          $subtitle->getCues()[0]->getLines());
    }


    public function testMergeMovesTheWordTimestampsOfTheOtherSubtitle(): void
    {
        $subtitle = (new Subtitle())->merge(self::load("profanity/radio.vtt", Format::WebVtt), 30);

        $this->assertSame(["<00:00:37.500>Fine, <00:00:38.000>damnit."], $subtitle->getCues()[3]->getLines());
    }


    public function testSliceToZeroMovesWordTimestamps(): void
    {
        $original = self::load("profanity/radio.vtt", Format::WebVtt);
        $slice    = $original->withSlice(5, 9, true);

        $this->assertSame(["<00:00:02.500>Fine, <00:00:03.000>damnit."], $slice->getCues()[1]->getLines());
        $this->assertSame(["<00:00:07.500>Fine, <00:00:08.000>damnit."], $original->getCues()[3]->getLines());
        $this->assertSame(["<00:00:07.500>Fine, <00:00:08.000>damnit."], $original->withSlice(5, 9)->getCues()[1]->getLines());
    }


    public function testReferenceSyncWithSplitsMovesWordTimestampsWithTheirPart(): void
    {
        $reference = self::load("sync/own_reference_en_tv_break.srt", Format::SubRip);
        $target    = self::load("sync/own_target_de_25fps.srt", Format::SubRip);
        $cues      = $target->getCues();
        foreach ([0, count($cues) - 1] as $index) {
            $cues[$index]->setLines("<" . Markup::coreTimestamp($cues[$index]->getStart() + 0.5) . ">" . $cues[$index]->getText());
        }

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference, -180, 180, maxSplits: 2));

        $this->assertCount(2, $result->getSegments());
        foreach ([$target->getCues()[0], $target->getCues()[count($cues) - 1]] as $cue) {
            preg_match(Markup::WORD_TIMESTAMP_REGEX, $cue->getText(), $match);
            $this->assertEqualsWithDelta($cue->getStart() + 0.5 * $result->scale, Markup::wordTimestampSeconds($match[1]), 0.0015);
        }
    }


    public function testCliRetimeMovesWordTimestamps(): void
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        $code    = (new Application(...$streams))->run(["subtitle-toolbox", "retime", self::FILES . "profanity/radio.vtt", "--shift", "2"]);
        rewind($streams[1]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:09.500 --> 00:00:11.000\n<00:00:09.500>Fine, <00:00:10.000>damnit.\n",
                                          stream_get_contents($streams[1]));
    }
}
