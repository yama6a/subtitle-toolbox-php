<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;

class TimecodeTest extends TestCase
{
    private const SECONDS = [0, 0.0004, 0.0005, 1.996, 59.9996, 3599.9996, 360000];


    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function units(): array
    {
        return [
            "seconds"      => ["seconds", [[0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 2], [0, 1, 0], [1, 0, 0], [100, 0, 0]]],
            "centiseconds" => ["centiseconds", [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
            "milliseconds" => ["milliseconds", [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 1], [0, 0, 1, 996], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
        ];
    }


    #[DataProvider("units")]
    public function testUnitRoundsTheTotalBeforeItSplits(string $method, array $expected): void
    {
        foreach (self::SECONDS as $index => $seconds) {
            $this->assertSame($expected[$index], Timecode::$method($seconds), "$method($seconds)");
        }
    }


    /**
     * @return array<string, array{float, bool, list<list<int>>}>
     */
    public static function frameRates(): array
    {
        return [
            "23.976 fps"            => [24000 / 1001, false, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 0, 59, 23], [0, 59, 56, 10], [99, 54, 0, 9]]],
            "25 fps"                => [25, false, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
            "29.97 fps drop-frame"  => [30000 / 1001, true, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 0, 59, 28], [1, 0, 0, 0], [100, 0, 0, 11]]],
            "30 fps"                => [30, false, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
        ];
    }


    /**
     * @return array<string, array{string, float}>
     */
    public static function fractions(): array
    {
        return [
            "no fraction" => ["", 3723.0],
            "1 digit"     => ["5", 3723.5],
            "2 digits"    => ["05", 3723.05],
            "3 digits"    => ["005", 3723.005],
            "4 digits"    => ["1234", 3723.1234],
        ];
    }


    #[DataProvider("fractions")]
    public function testToSecondsReadsTheFractionAsDecimalDigits(string $fraction, float $expected): void
    {
        $this->assertSame($expected, Timecode::toSeconds(1, 2, 3, $fraction));
    }


    public function testToSecondsFromFramesCountsFramesAfterTheLastWholeSecond(): void
    {
        $this->assertSame(3723.48, Timecode::toSecondsFromFrames(1, 2, 3, 12, new FrameRate(25)));
        $this->assertEqualsWithDelta(1.5005, Timecode::toSecondsFromFrames(0, 0, 1, 12, new FrameRate(24000 / 1001)), 0.00001);
    }


    public function testShortClockDropsTheHoursBelowOneHour(): void
    {
        $this->assertSame(["0:00", "1:02", "59:59", "1:02:05"], array_map(Timecode::shortClock(...), [0, 62.9, 3599.9, 3725]));
    }


    #[DataProvider("frameRates")]
    public function testFramesRoundToTheNearestFrameBeforeTheySplit(float $fps, bool $dropFrame, array $expected): void
    {
        foreach (self::SECONDS as $index => $seconds) {
            $this->assertSame($expected[$index], Timecode::frames($seconds, new FrameRate($fps), $dropFrame), "frames($seconds)");
        }
    }


    public function testDropFrameSkipsTheFirstLabelsOfEachMinuteExceptEveryTenthMinute(): void
    {
        $ntsc = new FrameRate(30000 / 1001);
        $this->assertSame([0, 0, 59, 29], Timecode::frameNumber(1799, $ntsc, true));
        $this->assertSame([0, 1, 0, 2], Timecode::frameNumber(1800, $ntsc, true));
        $this->assertSame([0, 9, 59, 29], Timecode::frameNumber(17981, $ntsc, true));
        $this->assertSame([0, 10, 0, 0], Timecode::frameNumber(17982, $ntsc, true));
        $this->assertSame([0, 11, 0, 2], Timecode::frameNumber(19782, $ntsc, true));
        $this->assertSame([0, 1, 0, 4], Timecode::frameNumber(3600, new FrameRate(60000 / 1001), true));
        $this->assertSame([0, 1, 0, 0], Timecode::frameNumber(1800, $ntsc));
    }


    public function testClockSecondsAndFramesRestartTheFrameCountAtEachClockSecond(): void
    {
        $film = new FrameRate(24000 / 1001);
        $this->assertSame([0, 1, 40, 0], Timecode::clockSecondsAndFrames(100, $film));
        $this->assertSame([0, 0, 2, 0], Timecode::clockSecondsAndFrames(1.996, $film));
        $this->assertSame([0, 0, 1, 12], Timecode::clockSecondsAndFrames(1.5, $film));
        $this->assertSame([0, 1, 0, 0], Timecode::clockSecondsAndFrames(59.9996, $film));
        foreach (self::SECONDS as $seconds) {
            $this->assertSame(Timecode::frames($seconds, new FrameRate(25)), Timecode::clockSecondsAndFrames($seconds, new FrameRate(25)));
        }
    }


    /**
     * @return array<string, array{string, float, array<string, mixed>, bool, 4?: ReadOptions}>
     */
    public static function textFormats(): array
    {
        return [
            "ass"                => ["ass", 0.01, new WriteOptions(), true],
            "csv"                => ["csv", 0.001, new WriteOptions(), true],
            "csv frames"         => ["csv", 1 / 25, new WriteOptions(formats: [new CsvWriteOptions(timeFormat: CsvTimeFormat::Frames, frameRate: 25)]), true, new ReadOptions(formats: [new CsvReadOptions(frameRate: 25)])],
            "ffmeta-chapters"    => ["ffmeta-chapters", 0.001, new WriteOptions(), true],
            "html"               => ["html", 1, new WriteOptions(), false],
            "itt"                => ["itt", 1 / 25, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]), true],
            "json"               => ["json", 0.001, new WriteOptions(), true],
            "lrc"                => ["lrc", 0.01, new WriteOptions(), false],
            "microdvd"           => ["microdvd", 1 / 25, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]), true, new ReadOptions(formats: [new MicroDvdReadOptions(25)])],
            "mpl2"               => ["mpl2", 0.1, new WriteOptions(), true],
            "mpsub"              => ["mpsub", 0.001, new WriteOptions(), true],
            "ogm-chapters"       => ["ogm-chapters", 0.001, new WriteOptions(), false],
            "podcast-chapters"   => ["podcast-chapters", 0.001, new WriteOptions(), true],
            "podcast-transcript" => ["podcast-transcript", 0.001, new WriteOptions(), true, new ReadOptions(formats: [new TranscriptReadOptions(keepSegments: true)])],
            "sami"               => ["sami", 0.001, new WriteOptions(), true],
            "sbv"                => ["sbv", 0.001, new WriteOptions(), true],
            "scc"                => ["scc", 1001 / 30000, new WriteOptions(), true],
            "srt"                => ["srt", 0.001, new WriteOptions(), true],
            "subviewer"          => ["subviewer", 0.01, new WriteOptions(), true],
            "tmplayer"           => ["tmplayer", 1, new WriteOptions(), false],
            "tsv"                => ["tsv", 0.001, new WriteOptions(), true],
            "ttml"               => ["ttml", 0.001, new WriteOptions(), true],
            "vtt"                => ["vtt", 0.001, new WriteOptions(), true],
            "youtube-chapters"   => ["youtube-chapters", 1, new WriteOptions(), false],
        ];
    }


    #[DataProvider("textFormats")]
    public function testCueAtTheEndOfASecondParsesBackWithinOneUnit(string $format, float $unit, WriteOptions $options, bool $writesEnd, ReadOptions $readOptions = new ReadOptions()): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1.996, 4, "One"));
        $subtitle->addCue(new SubtitleCue(59.9996, 62, "Two"));

        $output = $subtitle->toString(Format::from($format), $options);
        $parsed = array_values(Subtitle::fromString($output, Format::from($format), $readOptions)->getCues());

        $this->assertCount(2, $parsed, $output);
        foreach ([[1.996, 4], [59.9996, 62]] as $index => [$start, $end]) {
            $this->assertEqualsWithDelta($start, $parsed[$index]->getStart(), $unit, $output);
            if ($writesEnd) {
                $this->assertEqualsWithDelta($end, $parsed[$index]->getEnd(), $unit, $output);
            }
        }
    }


    /**
     * @return array<string, array{string, Format, string, ?float}>
     */
    public static function fixtureStartTimes(): array
    {
        return [
            "SubRip h:mm:ss,mmm"      => ["srt/valid.srt", Format::SubRip, '/^(\S+) --> /m', null],
            "WebVTT mm:ss.mmm"        => ["vtt/missing_hours.vtt", Format::WebVtt, '/^(\S+) --> /m', null],
            "SBV h:mm:ss.mmm"         => ["sbv/from_srt.sbv", Format::Sbv, '/^([\d:.]+),/m', null],
            "ASS h:mm:ss.cc"          => ["ass/real/own_aegisub.ass", Format::Ass, '/^Dialogue: \d+,([^,]+),/m', null],
            "SubViewer hh:mm:ss.cc"   => ["subviewer/real/subviewer2_crlf.sub", Format::SubViewer, '/^([\d:.]+),[\d:.]+\r?$/m', null],
            "LRC mm:ss.f"             => ["lrc/one_digit_fraction.lrc", Format::Lyrics, '/^\[([\d:.]+)\]/m', null],
            "TTML hh:mm:ss.ff"        => ["ttml/real/bbc_ebu_tt_d.ttml", Format::Ttml, '/<p [^>]*begin="([^"]+)"/', null],
            "YouTube chapters mm:ss"  => ["chapters/youtube/real/long_stream_chapters.txt", Format::YouTubeChapters, '/^([\d:]+) /m', null],
            "CSV hh:mm:ss:ff, 25 fps" => ["csv/own_frame_times.csv", Format::Csv, '/^([\d:]+),/m', 25.0],
        ];
    }


    #[DataProvider("fixtureStartTimes")]
    public function testParseReadsEachStartTimeOfAFixtureAsItsParserDoes(string $file, Format $format, string $startRegex, ?float $framesPerSecond): void
    {
        $path        = __DIR__ . "/files/$file";
        $readOptions = $framesPerSecond === null ? null : new ReadOptions(formats: [new CsvReadOptions(frameRate: $framesPerSecond)]);
        $starts      = array_map(fn (SubtitleCue $cue): float => $cue->getStart(), array_values(Subtitle::load($path, $format, $readOptions)->getCues()));
        preg_match_all($startRegex, file_get_contents($path), $matches);
        $frameRate = $framesPerSecond === null ? null : new FrameRate($framesPerSecond);

        $this->assertNotEmpty($starts);
        $this->assertEquals($starts, array_map(fn (string $time): float => Timecode::parse($time, $frameRate), $matches[1]));
    }


    /**
     * @return array<string, array{string, ?float, float}>
     */
    public static function validTimecodes(): array
    {
        return [
            "comma milliseconds"         => ["00:01:02,500", null, 62.5],
            "period milliseconds"        => ["00:01:02.500", null, 62.5],
            "one hour digit"             => ["1:01:02.5", null, 3662.5],
            "three hour digits"          => ["100:00:00", null, 360000.0],
            "no fraction"                => ["00:01:02", null, 62.0],
            "long fraction"              => ["00:00:01.0000005", null, 1.0000005],
            "minutes and seconds"        => ["01:02.5", null, 62.5],
            "one minute digit"           => ["1:02", null, 62.0],
            "minutes from 60"            => ["75:00,25", null, 4500.25],
            "frames at 25 fps"           => ["00:01:02:12", 25.0, 62.48],
            "frames at 23.976 fps"       => ["00:00:01:23", 24000 / 1001, 1 + 23 * 1001 / 24000],
            "last hour below the limit"  => ["99999:59:59.999", null, 359999999.999],
        ];
    }


    #[DataProvider("validTimecodes")]
    public function testParseReturnsSeconds(string $timecode, ?float $framesPerSecond, float $expected): void
    {
        $frameRate = $framesPerSecond === null ? null : new FrameRate($framesPerSecond);

        $this->assertEqualsWithDelta($expected, Timecode::parse($timecode, $frameRate), 1e-9);
    }


    /**
     * @return array<string, array{string, ?float, string}>
     */
    public static function invalidTimecodes(): array
    {
        $shape = "is not h:mm:ss.mmm, m:ss.mmm or h:mm:ss:ff";

        return [
            "empty"                => ["", null, $shape],
            "seconds only"         => ["62.5", null, $shape],
            "five parts"           => ["1:2:3:4:5", null, $shape],
            "one-digit seconds"    => ["0:01:2.5", null, $shape],
            "one-digit minutes"    => ["0:1:02.5", null, $shape],
            "60 minutes"           => ["00:60:00", null, $shape],
            "60 seconds"           => ["00:00:60", null, $shape],
            "negative"             => ["-00:00:02,500", null, $shape],
            "space"                => [" 00:00:02,500", null, $shape],
            "empty fraction"       => ["00:00:02.", null, $shape],
            "drop-frame"           => ["00:00:02;12", 30000 / 1001, $shape],
            "colon milliseconds"   => ["00:00:02:500", 25.0, "has a frame number that is not below 25"],
            "frames without rate"  => ["00:01:02:12", null, "counts frames and needs a frame rate"],
            "100000 hours"         => ["100000:00:00", null, "is not below 100000 hours"],
            "huge minutes"         => ["6000000:00", null, "is not below 100000 hours"],
            "huge hour digits"     => [str_repeat("9", 40) . ":00:00", null, "is not below 100000 hours"],
            "frames at 100000 h"   => ["100000:00:00:00", 25.0, "is not below 100000 hours"],
        ];
    }


    #[DataProvider("invalidTimecodes")]
    public function testParseRejectsAnInvalidTimecodeAndQuotesIt(string $timecode, ?float $framesPerSecond, string $problem): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The timecode \"$timecode\" $problem.");

        Timecode::parse($timecode, $framesPerSecond === null ? null : new FrameRate($framesPerSecond));
    }
}
