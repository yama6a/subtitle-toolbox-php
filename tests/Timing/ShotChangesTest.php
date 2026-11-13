<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class ShotChangesTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/shot-changes/";


    public function testFromFfmpegLogReadsThePtsTimes(): void
    {
        $times = ShotChanges::fromFfmpegLog(file_get_contents(self::FILES . "own_ffmpeg_showinfo.log"));

        $this->assertSame([12.5, 62.5, 70.0, 80.0, 91.25], $times);
    }


    public function testFromFfmpegLogSortsAndDropsDuplicates(): void
    {
        $log = "[Parsed_showinfo_1 @ 0x1] n:   1 pts:  2 pts_time:8.5 duration:1\n" .
               "[Parsed_showinfo_1 @ 0x1] n:   0 pts:  1 pts_time:3 duration:1\n" .
               "[Parsed_showinfo_1 @ 0x1] n:   2 pts:  2 pts_time:8.5 duration:1\n";

        $this->assertSame([3.0, 8.5], ShotChanges::fromFfmpegLog($log));
        $this->assertSame([], ShotChanges::fromFfmpegLog("frame=    0 fps=0.0 time=00:00:10.00\n"));
    }


    public function testFromTextReadsSecondsAndTimestamps(): void
    {
        $times = ShotChanges::fromText(file_get_contents(self::FILES . "own_scenes.txt"));

        $this->assertSame([12.5, 62.5, 70.0, 80.0, 91.25], $times);
    }


    public function testFromTextSkipsBlankLinesAndReadsBomAndCrLf(): void
    {
        $text = "\xEF\xBB\xBF01:00:00.040\r\n\r\n  2  \r\n00:00:01\r\n";

        $this->assertSame([1.0, 2.0, 3600.04], ShotChanges::fromText($text));
    }


    public function testFromTextThrowsWithTheLineNumber(): void
    {
        try {
            ShotChanges::fromText("1.5\n\n00:01:2x\n");
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertSame(3, $exception->getLineNumber());
        }
    }


    /**
     * @return array<string, array{\Closure(): mixed, string, int}>
     */
    public static function timesOf100000HoursOrMore(): array
    {
        return [
            "log"                    => [fn () => ShotChanges::fromFfmpegLog("pts_time:100000000000000000000000000000"),
                                         'The shot change time "100000000000000000000000000000" is not below 100000 hours.', 1],
            "log, line of the match" => [fn () => ShotChanges::fromFfmpegLog("start\r\npts_time:1\r\npts_time:359999999.9996\n"),
                                         'The shot change time "359999999.9996" is not below 100000 hours.', 3],
            "text"                   => [fn () => ShotChanges::fromText("99999999999999999999:00:00"),
                                         'The shot change time "99999999999999999999:00:00" is not valid. Use seconds or hh:mm:ss.mmm.', 1],
            "text in seconds"        => [fn () => ShotChanges::fromText("1\n360000000"),
                                         'The shot change time "360000000" is not valid. Use seconds or hh:mm:ss.mmm.', 2],
        ];
    }


    #[DataProvider("timesOf100000HoursOrMore")]
    public function testTimeOf100000HoursOrMoreThrows(\Closure $read, string $message, int $line): void
    {
        try {
            $read();
            $this->fail("No exception.");
        } catch (ParsingException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertSame($line, $exception->getLineNumber());
        }
    }


    public function testLastTimeBelowTheLimitReads(): void
    {
        $this->assertSame([359999999.999], ShotChanges::fromText("99999:59:59.999"));
        $this->assertSame([359999999.999], ShotChanges::fromFfmpegLog("pts_time:359999999.999"));
    }
}
