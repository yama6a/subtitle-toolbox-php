<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class HlsWebVttJoinerTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/hls/";


    /** @return list<array{float, float, string}> */
    private function summarize(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues());
    }


    /** @return list<string> */
    private function ownSegments(): array
    {
        return array_map(fn (int $index): string => file_get_contents(self::FILES . "own-fileSequence$index.webvtt"), range(0, 3));
    }


    public function testRealSegmentsParse(): void
    {
        $first = Subtitle::fromString($this->ownSegments()[0], Format::WebVtt);
        $this->assertSame([[1.0, 3.0, "The ferry leaves at noon."], [5.0, 7.5, "Please keep your tickets ready."]],
                          $this->summarize($first));
        $this->assertSame(181083, TimestampMap::fromSubtitle($first)->mpegts);

        foreach (["shaka-vtt-071.vtt", "shaka-vtt-072.vtt"] as $file) {
            $segment = Subtitle::fromString(file_get_contents(self::FILES . $file), Format::WebVtt);
            $this->assertSame([], $segment->getCues());
            $this->assertSame(3600.0, TimestampMap::fromSubtitle($segment)->local);
        }
    }


    public function testJoinRemovesCuesThatRepeatAcrossSegments(): void
    {
        $joined = HlsWebVttJoiner::join($this->ownSegments());

        $this->assertSame([
            [1.0, 3.0, "The ferry leaves at noon."],
            [5.0, 7.5, "Please keep your tickets ready."],
            [9.0, 11.0, "Mind the gap between\nthe ramp and the deck."],
            [18.5, 20.0, "Thank you for travelling with us."],
        ], $this->summarize($joined));
        $this->assertStringStartsWith("WEBVTT\n\n1\n00:00:01.000 --> 00:00:03.000 align:center line:90%\n" .
                                      "The ferry leaves at noon.\n\n2\n00:00:05.000 --> 00:00:07.500 align:center line:90%\n",
                                      $joined->toString(Format::WebVtt, new WriteOptions(bom: false)));
    }


    public function testJoinAppliesTheMapRelativeToTheStreamStart(): void
    {
        $this->assertSame([[0.0, 2.0, "The ferry leaves at noon."]],
                          array_slice($this->summarize(HlsWebVttJoiner::join($this->ownSegments(), 181083 + 90000)), 0, 1));

        $segment = "WEBVTT\nX-TIMESTAMP-MAP=LOCAL:01:00:00.000,MPEGTS:324000000\n\n01:02:20.000 --> 01:02:22.000\nOne\n";
        $this->assertSame([[140.0, 142.0, "One"]], $this->summarize(HlsWebVttJoiner::join([$segment])));
        $this->assertSame([[3740.0, 3742.0, "One"]], $this->summarize(HlsWebVttJoiner::join([$segment], 0)));
    }


    public function testJoinUsesMpegtsZeroForASegmentWithoutAMap(): void
    {
        $segments = ["WEBVTT\nX-TIMESTAMP-MAP=MPEGTS:90000,LOCAL:00:00:00.000\n\n00:00:01.000 --> 00:00:02.000\nOne\n",
                     "WEBVTT\n\n00:00:03.000 --> 00:00:04.000\nTwo\n"];

        $this->assertSame([[1.0, 2.0, "One"], [2.0, 3.0, "Two"]], $this->summarize(HlsWebVttJoiner::join($segments)));
    }


    public function testJoinAllowsForAWrappedMpegtsValue(): void
    {
        $segments = ["WEBVTT\nX-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:" . (TimestampMap::MPEGTS_WRAP - 90000) .
                     "\n\n00:00:00.000 --> 00:00:01.000\nOne\n",
                     "WEBVTT\nX-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:0\n\n00:00:00.000 --> 00:00:01.000\nTwo\n"];

        $this->assertSame([[0.0, 1.0, "One"], [1.0, 2.0, "Two"]], $this->summarize(HlsWebVttJoiner::join($segments)));
    }


    public function testJoinRejoinsACueThatASegmenterSplitAtTheBoundary(): void
    {
        $segments = ["WEBVTT\n\n00:00:04.000 --> 00:00:06.000\nAll aboard.\n",
                     "WEBVTT\n\n00:00:06.000 --> 00:00:07.000\nAll aboard.\n"];

        $this->assertSame([[4.0, 7.0, "All aboard."]], $this->summarize(HlsWebVttJoiner::join($segments)));
    }


    public function testJoinJoinsOverlappingCuesWithTheSameText(): void
    {
        $segments = ["WEBVTT\n\n00:00:04.000 --> 00:00:06.000\nAll aboard.\n\n00:00:05.500 --> 00:00:06.500\nAll aboard.\n"];

        $this->assertSame([[4.0, 6.5, "All aboard."]], $this->summarize(HlsWebVttJoiner::join($segments)));
    }


    public function testJoinOfEmptyRealSegmentsHasNoCues(): void
    {
        $joined = HlsWebVttJoiner::join([file_get_contents(self::FILES . "shaka-vtt-071.vtt"),
                                         file_get_contents(self::FILES . "shaka-vtt-072.vtt")]);

        $this->assertSame([], $joined->getCues());
        $this->assertSame([], $joined->findFormatData("vtt"));
    }


    public function testSegmentAndJoinRoundTripARealFile(): void
    {
        $original = Subtitle::fromString(file_get_contents(self::FILES . "node-webvtt-subs1.vtt"), Format::WebVtt);

        foreach ([new HlsSegmentOptions(), new HlsSegmentOptions(segmentDuration: 4, mpegts: 181083, local: 3600),
                  new HlsSegmentOptions(segmentDuration: 2.002, mpegts: TimestampMap::MPEGTS_WRAP - 90000)] as $options) {
            $joined = HlsWebVttJoiner::join(HlsWebVttSegmenter::segment($original, $options)->getSegments());

            $this->assertSame($this->summarize($original), $this->summarize($joined));
            $this->assertSame($original->toString(Format::WebVtt), $joined->toString(Format::WebVtt));
        }
    }


    public function testJoinRejectsAStreamStartOutsideThe33BitRange(): void
    {
        $segment = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nOne\n";

        foreach ([1 << 40, -(1 << 40), -1, TimestampMap::MPEGTS_WRAP] as $streamStart) {
            try {
                HlsWebVttJoiner::join([$segment], $streamStart);
                $this->fail("No exception for $streamStart.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString("from 0 to 8589934591, got $streamStart.", $exception->getMessage());
            }
        }
    }
}
