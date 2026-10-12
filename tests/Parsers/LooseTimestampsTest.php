<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\CueStreamReader;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;

class LooseTimestampsTest extends TestCase
{
    /**
     * Each case holds the format, the start time, the end time, the start and end in seconds, and whether strict mode reads it.
     *
     * @return array<string, array{Format, string, string, float, float, bool}>
     */
    public static function looseTimes(): array
    {
        return [
            "SubRip with : before the fraction"   => [Format::SubRip, "00:00:01:105", "00:00:02:200", 1.105, 2.2, true],
            "SubRip with 4 fraction digits"       => [Format::SubRip, "00:28:41,1000", "00:28:42,0006", 1721.1, 1722.001, false],
            "SubRip with 1-digit minutes"         => [Format::SubRip, "0:0:1,500", "0:0:2,500", 1.5, 2.5, false],
            "SubRip with 1-digit seconds"         => [Format::SubRip, "00:00:0,000", "00:00:2,000", 0.0, 2.0, false],
            "SubRip without hours"                => [Format::SubRip, "07:03,920", "07:04,500", 423.92, 424.5, false],
            "SubRip without fraction"             => [Format::SubRip, "00:00:04", "00:00:05", 4.0, 5.0, true],
            "SubRip with 4 hour digits"           => [Format::SubRip, "0000:00:01,000", "0000:00:02,000", 1.0, 2.0, false],
            "WebVTT with 1 fraction digit"        => [Format::WebVtt, "00:00:01.5", "00:00:02.5", 1.5, 2.5, false],
            "WebVTT with 2 fraction digits"       => [Format::WebVtt, "00:00:01.25", "00:00:02.25", 1.25, 2.25, false],
            "WebVTT with a comma"                 => [Format::WebVtt, "00:00:01,500", "00:00:02,500", 1.5, 2.5, false],
        ];
    }


    #[DataProvider("looseTimes")]
    public function testLenientModeReadsTheTimesAndWarnsOncePerTime(Format $format, string $start, string $end, float $startSeconds, float $endSeconds, bool $strict): void
    {
        $subtitle = Subtitle::fromString($this->content($format, $start, $end), $format, new ReadOptions(lenient: true));
        $cues     = $subtitle->getCues();

        $this->assertCount(2, $cues);
        $this->assertSame([$startSeconds, $endSeconds, ["The ferry leaves."]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertSame([7200.0, 7202.0], [$cues[1]->getStart(), $cues[1]->getEnd()]);

        $warnings = $subtitle->getParseWarnings();
        $this->assertCount($strict ? 0 : 2, $warnings);
        foreach ($warnings as $warning) {
            $this->assertSame(ParseWarningAction::Repaired, $warning->action);
            $this->assertSame($format === Format::SubRip ? 2 : 3, $warning->lineNumber);
        }
    }


    #[DataProvider("looseTimes")]
    public function testStrictModeReadsOnlyTheUnambiguousShapes(Format $format, string $start, string $end, float $startSeconds, float $endSeconds, bool $strict): void
    {
        if (!$strict) {
            $this->expectException(ParsingException::class);
            $this->expectExceptionMessage("is not valid.");
        }

        $cues = Subtitle::fromString($this->content($format, $start, $end), $format)->getCues();

        $this->assertSame([$startSeconds, $endSeconds], [$cues[0]->getStart(), $cues[0]->getEnd()]);
    }


    #[DataProvider("looseTimes")]
    public function testStreamReaderReadsTheSameCuesAndWarnings(Format $format, string $start, string $end): void
    {
        $content  = $this->content($format, $start, $end);
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));
        $reader   = $format === Format::SubRip ? new SubRipStreamReader(new ReadOptions(lenient: true)) : new WebVttStreamReader(new ReadOptions(lenient: true));

        $this->assertEquals($subtitle->getCues(), iterator_to_array($this->read($reader, $content)));
        $this->assertEquals($subtitle->getParseWarnings(), $reader->getWarnings());
    }


    #[DataProvider("looseTimes")]
    public function testFormattersWriteCanonicalTimes(Format $format, string $start, string $end): void
    {
        $subtitle = Subtitle::fromString($this->content($format, $start, $end), $format, new ReadOptions(lenient: true));

        $output = $format === Format::SubRip ? (new SubRipFormatter())->format($subtitle) : (new WebVttFormatter())->format($subtitle);

        $pattern = $format === Format::SubRip ? '/^\d\d:\d\d:\d\d,\d{3} --> \d\d:\d\d:\d\d,\d{3}$/m' : '/^\d\d:\d\d:\d\d\.\d{3} --> \d\d:\d\d:\d\d\.\d{3}/m';
        $this->assertSame(2, preg_match_all($pattern, $output));
    }


    public function testLenientModeReadsATimeWithOneSecondDigitInUtf16(): void
    {
        $content = file_get_contents(__DIR__ . "/../files/lenient/mantas_utf16.srt");

        $subtitle = Subtitle::fromString($content, Format::SubRip, new ReadOptions(lenient: true));

        $this->assertSame([0.0, 1.0], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
        $this->assertSame("Block #0 has the time \"00:00:0,000\", which is not in the form hh:mm:ss,mmm. The parser read it as 0 s.", $subtitle->getParseWarnings()[0]->message);
    }


    public function testLenientModeKeepsTheSettingsAfterALooseWebVttEndTime(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.5 --> 00:00:02.25 align:start line:0\nThe ferry leaves.\n", Format::WebVtt, new ReadOptions(lenient: true));

        $this->assertSame(["align" => "start", "line" => "0"], $subtitle->getCues()[0]->findFormatData("vtt"));
    }


    public function testLenientModeSkipsATimeThatTheEndOfTheFileCutOff(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:01,000 --> 00:00:0", Format::SubRip, new ReadOptions(lenient: true));

        $this->assertSame([], $subtitle->getCues());
        $this->assertSame(ParseWarningAction::Skipped, $subtitle->getParseWarnings()[0]->action);
    }


    /**
     * @return array<string, array{Format, string}>
     */
    public static function timesWithMinutesOrSecondsOfSixtyOrMore(): array
    {
        return [
            "SubRip minutes"            => [Format::SubRip, "1\n00:00:01,000 --> 00:75:02,000\nThe ferry leaves.\n\n2\n02:00:00,000 --> 02:00:02,000\nIt is late.\n"],
            "SubRip seconds"            => [Format::SubRip, "1\n00:00:01,000 --> 00:00:75,000\nThe ferry leaves.\n\n2\n02:00:00,000 --> 02:00:02,000\nIt is late.\n"],
            "SubRip without hours"      => [Format::SubRip, "1\n00:01,000 --> 00:60,000\nThe ferry leaves.\n\n2\n02:00:00,000 --> 02:00:02,000\nIt is late.\n"],
            "WebVTT seconds"            => [Format::WebVtt, "WEBVTT\n\n00:01.000 --> 00:75.000\nThe ferry leaves.\n\n02:00:00.000 --> 02:00:02.000\nIt is late.\n"],
            "WebVTT minutes"            => [Format::WebVtt, "WEBVTT\n\n00:00:01.000 --> 00:60:02,000\nThe ferry leaves.\n\n02:00:00.000 --> 02:00:02.000\nIt is late.\n"],
            "ASS minutes"               => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\nDialogue: 0,0:00:01.00,0:75:02,Default,,0,0,0,,The ferry leaves.\nDialogue: 0,2:00:00.00,2:00:02.00,Default,,0,0,0,,It is late.\n"],
            "ASS seconds"               => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\nDialogue: 0,0:00:01.00,0:00:99,Default,,0,0,0,,The ferry leaves.\nDialogue: 0,2:00:00.00,2:00:02.00,Default,,0,0,0,,It is late.\n"],
            "ASS minutes with fraction" => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\nDialogue: 0,0:00:01.00,0:75:02.00,Default,,0,0,0,,The ferry leaves.\nDialogue: 0,2:00:00.00,2:00:02.00,Default,,0,0,0,,It is late.\n"],
            "ASS seconds with fraction" => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Text\nDialogue: 0,0:00:75.00,0:00:99.00,Default,The ferry leaves.\nDialogue: 0,2:00:00.00,2:00:02.00,Default,It is late.\n"],
            "SubViewer 2 minutes"       => [Format::SubViewer, "[SUBTITLE]\n00:00:01.00,00:75:02.00\nThe ferry leaves.\n\n02:00:00.00,02:00:02.00\nIt is late.\n"],
            "SubViewer 1 minutes"       => [Format::SubViewer, "******** START SCRIPT ********\n[00:75:01]\nThe ferry leaves.\n\n[02:00:00]\nIt is late.\n[02:00:02]\n"],
            "LRC minutes after hours"   => [Format::Lyrics, "[00:00.50]\n[1:75:00.00]The ferry leaves.\n[2:00:00.00]It is late.\n[2:00:02.00]\n"],
        ];
    }


    #[DataProvider("timesWithMinutesOrSecondsOfSixtyOrMore")]
    public function testLenientModeSkipsACueWithMinutesOrSecondsOfSixtyOrMore(Format $format, string $content): void
    {
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));

        $this->assertSame([[7200.0, 7202.0]], array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([ParseWarningAction::Skipped], array_map(fn ($warning) => $warning->action, $subtitle->getParseWarnings()));
    }


    #[DataProvider("timesWithMinutesOrSecondsOfSixtyOrMore")]
    public function testStrictModeRejectsACueWithMinutesOrSecondsOfSixtyOrMore(Format $format, string $content): void
    {
        if ($format === Format::Lyrics) {
            $cues = Subtitle::fromString($content, $format)->getCues();
            $this->assertSame([[7200.0, 7202.0]], array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd()], $cues));

            return;
        }

        $this->expectException(ParsingException::class);
        Subtitle::fromString($content, $format);
    }


    /**
     * @return array<string, array{Format, string}>
     */
    public static function exceededMinutesFixtures(): array
    {
        return [
            "SubRip" => [Format::SubRip, "srt/exceeded_minutes.srt"],
            "WebVTT" => [Format::WebVtt, "vtt/exceeded_minutes.vtt"],
        ];
    }


    #[DataProvider("exceededMinutesFixtures")]
    public function testLenientModeSkipsTheCueOfTheExceededMinutesFixture(Format $format, string $file): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/" . $file), $format, new ReadOptions(lenient: true));

        $this->assertSame([], $subtitle->getCues());
        $this->assertSame([ParseWarningAction::Skipped], array_map(fn ($warning) => $warning->action, $subtitle->getParseWarnings()));
    }


    private function content(Format $format, string $start, string $end): string
    {
        return $format === Format::SubRip
            ? "1\n$start --> $end\nThe ferry leaves.\n\n2\n02:00:00,000 --> 02:00:02,000\nIt is late.\n"
            : "WEBVTT\n\n$start --> $end\nThe ferry leaves.\n\n02:00:00.000 --> 02:00:02.000\nIt is late.\n";
    }


    private function read(CueStreamReader $reader, string $content): Generator
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        yield from $reader->read($stream);
    }
}
