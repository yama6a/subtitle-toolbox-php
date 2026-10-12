<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class HlsWebVttSegmenterTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/hls/";

    private const MAP = "X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:900000";


    private function ferry(): Subtitle
    {
        return (new Subtitle())
            ->addCue(new SubtitleCue(1, 3, "The ferry leaves at noon."))
            ->addCue(new SubtitleCue(5, 7.5, "Please keep your tickets ready."))
            ->addCue(new SubtitleCue(18.5, 20, "Thank you for travelling with us."));
    }


    /**
     * Returns the target duration and the EXTINF durations of a media playlist.
     *
     * @return array{int, array<string, float>}
     */
    private function readPlaylist(string $playlist): array
    {
        $lines = preg_split("/\r?\n/", trim($playlist));
        $this->assertSame("#EXTM3U", $lines[0]);

        $targetDuration = null;
        $durations      = [];
        foreach ($lines as $index => $line) {
            if (preg_match("/^#EXT-X-TARGETDURATION:(\d+)$/", $line, $matches)) {
                $targetDuration = (int) $matches[1];
            } elseif (preg_match("/^#EXTINF:([\d.]+),?/", $line, $matches)) {
                $durations[$lines[$index + 1]] = (float) $matches[1];
            }
        }
        $this->assertNotNull($targetDuration);

        return [$targetDuration, $durations];
    }


    private function assertTargetDurationRule(string $playlist): void
    {
        [$targetDuration, $durations] = $this->readPlaylist($playlist);
        foreach ($durations as $name => $duration) {
            $this->assertLessThanOrEqual($targetDuration, (int) round($duration), $name);
        }
    }


    public function testRealPlaylistsKeepTheTargetDurationRule(): void
    {
        foreach (["shaka-playlist-vtt.m3u8", "own-prog_index.m3u8"] as $file) {
            $this->assertTargetDurationRule(file_get_contents(self::FILES . $file));
        }

        [$targetDuration, $durations] = $this->readPlaylist(file_get_contents(self::FILES . "shaka-playlist-vtt.m3u8"));
        $this->assertSame(2, $targetDuration);
        $this->assertSame(["vtt-069.vtt" => 2.0, "vtt-070.vtt" => 2.0, "vtt-071.vtt" => 0.917, "vtt-072.vtt" => 2.0,
                           "vtt-073.vtt" => 2.0], $durations);
    }


    public function testSegmentWritesTheSegmentsDurationsAndPlaylist(): void
    {
        $hls = HlsWebVttSegmenter::segment($this->ferry(), new HlsSegmentOptions(segmentDuration: 6, mpegts: 900000));

        $this->assertSame([
            "sub0.vtt" => "WEBVTT\n" . self::MAP . "\n\n1\n00:00:01.000 --> 00:00:03.000\nThe ferry leaves at noon.\n\n" .
                          "2\n00:00:05.000 --> 00:00:07.500\nPlease keep your tickets ready.\n",
            "sub1.vtt" => "WEBVTT\n" . self::MAP . "\n\n2\n00:00:05.000 --> 00:00:07.500\nPlease keep your tickets ready.\n",
            "sub2.vtt" => "WEBVTT\n" . self::MAP . "\n\n",
            "sub3.vtt" => "WEBVTT\n" . self::MAP . "\n\n3\n00:00:18.500 --> 00:00:20.000\nThank you for travelling with us.\n",
        ], iterator_to_array($hls->getSegments()));
        $this->assertSame(["sub0.vtt" => 6.0, "sub1.vtt" => 6.0, "sub2.vtt" => 6.0, "sub3.vtt" => 2.0], iterator_to_array($hls->getDurations()));
        $this->assertSame("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:0\n" .
                          "#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:6.000,\nsub0.vtt\n#EXTINF:6.000,\nsub1.vtt\n" .
                          "#EXTINF:6.000,\nsub2.vtt\n#EXTINF:2.000,\nsub3.vtt\n#EXT-X-ENDLIST\n", $hls->getPlaylist());
    }


    public function testEverySegmentOfARealFileParsesAndThePlaylistKeepsTheRules(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "node-webvtt-subs1.vtt"), Format::WebVtt);
        $cues     = $subtitle->getCues();
        $this->assertCount(30, $cues);
        $this->assertSame([1.8, 5.16, "0"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([123.84, 125.56, "29"], [$cues[29]->getStart(), $cues[29]->getEnd(), $cues[29]->getText()]);

        $hls = HlsWebVttSegmenter::segment($subtitle, new HlsSegmentOptions(segmentDuration: 10, mediaDuration: 130.08));

        $this->assertCount(14, iterator_to_array($hls->getSegments()));
        $this->assertSame(0.08, iterator_to_array($hls->getDurations())["sub13.vtt"]);
        $this->assertTargetDurationRule($hls->getPlaylist());
        [, $durations] = $this->readPlaylist($hls->getPlaylist());
        $this->assertSame(array_keys(iterator_to_array($hls->getSegments())), array_keys($durations));
        $this->assertEqualsWithDelta(130.08, array_sum($durations), 0.0005);

        $segmentStart = 0;
        foreach (iterator_to_array($hls->getSegments()) as $name => $vtt) {
            $segment = Subtitle::fromString($vtt, Format::WebVtt);
            $this->assertSame(900000, TimestampMap::fromSubtitle($segment)->mpegts);
            foreach ($segment->getCues() as $cue) {
                $this->assertLessThan($segmentStart + $durations[$name], $cue->getStart(), $name);
                $this->assertGreaterThan($segmentStart, $cue->getEnd(), $name);
            }
            $segmentStart += $durations[$name];
        }
        $texts = fn (string $name): array => array_map(fn (SubtitleCue $cue): string => $cue->getText(),
                                                       Subtitle::fromString(iterator_to_array($hls->getSegments())[$name], Format::WebVtt)->getCues());
        $this->assertSame(["8", "9", "10", "11"], $texts("sub2.vtt"));
        $this->assertSame(["11", "12", "13", "14", "15", "16"], $texts("sub3.vtt"));
        $this->assertSame([], Subtitle::fromString(iterator_to_array($hls->getSegments())["sub13.vtt"], Format::WebVtt)->getCues());
    }


    // A scan of all cues for each segment takes about 9 s here.
    public function testRealFileRepeatedToFiftyOneThousandCuesSegmentsUnderFiveSeconds(): void
    {
        $file  = Subtitle::fromString(file_get_contents(self::FILES . "node-webvtt-subs1.vtt"), Format::WebVtt);
        $texts = fn (string $vtt): array => array_map(fn (SubtitleCue $cue): string => $cue->getText(),
                                                      array_values(Subtitle::fromString($vtt, Format::WebVtt)->getCues()));
        $once  = array_map($texts, array_values(iterator_to_array(HlsWebVttSegmenter::segment($file, new HlsSegmentOptions(mediaDuration: 132))->getSegments())));
        $cues  = [];
        for ($copy = 0; $copy < 1700; $copy++) {
            foreach ($file->getCues() as $cue) {
                $cues[] = new SubtitleCue($cue->getStart() + 132 * $copy, $cue->getEnd() + 132 * $copy, $cue->getText());
            }
        }
        $hls = HlsWebVttSegmenter::segment((new Subtitle())->addCues($cues));

        $start    = microtime(true);
        $count    = 0;
        $copy1000 = [];
        foreach ($hls->getSegments() as $vtt) {
            if (intdiv($count++, 22) === 1000) {
                $copy1000[] = $texts($vtt);
            }
        }

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertSame(37399, $count);
        $this->assertCount(22, $once);
        $this->assertSame($once, $copy1000);
    }


    /** @return array<string, array{string, float}> */
    public static function goldenFolders(): array
    {
        return ["local 0 s" => ["node-webvtt-subs1", 0.0], "local 10 s" => ["node-webvtt-subs1-local", 10.0]];
    }


    #[DataProvider("goldenFolders")]
    public function testRealFileGivesTheGoldenSegmentsAndPlaylist(string $folder, float $local): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "node-webvtt-subs1.vtt"), Format::WebVtt);
        $hls      = HlsWebVttSegmenter::segment($subtitle, new HlsSegmentOptions(
            segmentDuration: 10, mpegts: 126000, local: $local, fileNamePattern: "part%03d.vtt", mediaDuration: 150
        ));

        $golden   = self::FILES . "expected/$folder/";
        $segments = iterator_to_array($hls->getSegments());
        $this->assertSame(array_map("basename", glob($golden . "part*.vtt")), array_keys($segments));
        foreach ($segments as $name => $content) {
            $this->assertStringEqualsFile($golden . $name, $content, $name);
        }
        $this->assertStringEqualsFile($golden . "index.m3u8", $hls->getPlaylist());
    }


    public function testTargetDurationRoundsToTheNearestInteger(): void
    {
        $hls = HlsWebVttSegmenter::segment($this->ferry(), new HlsSegmentOptions(segmentDuration: 6.006));

        $this->assertStringContainsString("#EXT-X-TARGETDURATION:6\n", $hls->getPlaylist());
        $this->assertStringContainsString("#EXTINF:6.006,\nsub0.vtt\n", $hls->getPlaylist());
        $this->assertStringContainsString("#EXTINF:1.982,\nsub3.vtt\n", $hls->getPlaylist());
        $this->assertTargetDurationRule($hls->getPlaylist());

        $hls = HlsWebVttSegmenter::segment($this->ferry(), new HlsSegmentOptions(segmentDuration: 6.5));
        $this->assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $hls->getPlaylist());
        $this->assertTargetDurationRule($hls->getPlaylist());
    }


    public function testMediaDurationAddsEmptySegmentsUpToTheEnd(): void
    {
        $hls = HlsWebVttSegmenter::segment($this->ferry(), new HlsSegmentOptions(mediaDuration: 31));

        $this->assertSame(["sub0.vtt" => 6.0, "sub1.vtt" => 6.0, "sub2.vtt" => 6.0, "sub3.vtt" => 6.0, "sub4.vtt" => 6.0,
                           "sub5.vtt" => 1.0], iterator_to_array($hls->getDurations()));
        $this->assertSame("WEBVTT\n" . self::MAP . "\n\n", iterator_to_array($hls->getSegments())["sub5.vtt"]);
    }


    public function testMediaDurationShorterThanTheCuesCutsTheLastSegment(): void
    {
        $hls = HlsWebVttSegmenter::segment($this->ferry(), new HlsSegmentOptions(mediaDuration: 7));

        $this->assertSame(["sub0.vtt" => 6.0, "sub1.vtt" => 1.0], iterator_to_array($hls->getDurations()));
        $this->assertStringContainsString("00:00:05.000 --> 00:00:07.500", iterator_to_array($hls->getSegments())["sub1.vtt"]);
    }


    public function testOptionsSetTheMapTheLocalTimeAndTheFileNames(): void
    {
        $options = new HlsSegmentOptions(segmentDuration: 10, mpegts: 181083, local: 3600, fileNamePattern: "text/seg_%03d.webvtt");
        $hls     = HlsWebVttSegmenter::segment($this->ferry(), $options);

        $this->assertSame(["text/seg_000.webvtt", "text/seg_001.webvtt"], array_keys(iterator_to_array($hls->getSegments())));
        $this->assertSame("WEBVTT\nX-TIMESTAMP-MAP=LOCAL:01:00:00.000,MPEGTS:181083\n\n" .
                          "3\n01:00:18.500 --> 01:00:20.000\nThank you for travelling with us.\n",
                          iterator_to_array($hls->getSegments())["text/seg_001.webvtt"]);
    }


    public function testZeroLengthCuesGoToTheSegmentTheyStartIn(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(6, 6, "Mark"))->addCue(new SubtitleCue(8, 9, "End"));
        $segments = iterator_to_array(HlsWebVttSegmenter::segment($subtitle)->getSegments());

        $this->assertStringNotContainsString("Mark", $segments["sub0.vtt"]);
        $this->assertStringContainsString("00:00:06.000 --> 00:00:06.000\nMark", $segments["sub1.vtt"]);
    }


    public function testSegmentsKeepTheFileDataAndReplaceAnOldMap(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT Ferry\nX-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:0\nKind: captions\n\n" .
                                    "STYLE\n::cue { color: yellow }\n\nintro\n00:00:01.000 --> 00:00:02.000 line:0\n" .
                                    "<i>Welcome aboard.</i>\n", Format::WebVtt);

        $this->assertSame("WEBVTT Ferry\n" . self::MAP . "\nKind: captions\n\nSTYLE\n::cue { color: yellow }\n\n" .
                          "intro\n00:00:01.000 --> 00:00:02.000 line:0\n<i>Welcome aboard.</i>\n",
                          iterator_to_array(HlsWebVttSegmenter::segment($subtitle)->getSegments())["sub0.vtt"]);
    }


    public function testSegmentNeedsADurationForAnEmptySubtitle(): void
    {
        $this->assertSame(["sub0.vtt" => "WEBVTT\n" . self::MAP . "\n\n"],
                          iterator_to_array(HlsWebVttSegmenter::segment(new Subtitle(), new HlsSegmentOptions(mediaDuration: 4))->getSegments()));

        $this->expectException(InvalidArgumentException::class);
        HlsWebVttSegmenter::segment(new Subtitle());
    }


    public function testOptionsRejectInvalidValues(): void
    {
        foreach ([fn () => new HlsSegmentOptions(segmentDuration: 0), fn () => new HlsSegmentOptions(mediaDuration: 0.0001),
                  fn () => new HlsSegmentOptions(fileNamePattern: "sub.vtt"), fn () => new HlsSegmentOptions(fileNamePattern: "%s%d.vtt"),
                  fn () => new HlsSegmentOptions(fileNamePattern: "%d_%d.vtt"), fn () => new HlsSegmentOptions(mpegts: -1),
                  fn () => new HlsSegmentOptions(local: -1), fn () => new HlsSegmentOptions(segmentDuration: NAN),
                  fn () => new HlsSegmentOptions(segmentDuration: INF), fn () => new HlsSegmentOptions(mediaDuration: NAN),
                  fn () => new HlsSegmentOptions(mediaDuration: INF), fn () => new HlsSegmentOptions(local: NAN),
                  fn () => new HlsSegmentOptions(local: INF)] as $index => $create) {
            try {
                $create();
                $this->fail("No exception for case $index");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame("sub_007%.vtt", (new HlsSegmentOptions(fileNamePattern: "sub_%03d%%.vtt"))->fileName(7));
    }
}
