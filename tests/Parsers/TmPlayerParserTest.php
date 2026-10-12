<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;
use SubtitleToolbox\WriteOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class TmPlayerParserTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/tmplayer/";


    private static function realFilesDir(): string
    {
        return "tmplayer/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::TmPlayer;
    }


    /**
     * Each case holds the file, the cue count, the first and the last cue, and the output options of a byte-for-byte
     * round trip, or null when the formatter writes the file in another shape.
     */
    public static function realFiles(): array
    {
        return [
            "TMPlayer with CR LF" => [
                "tmplayer_crlf.txt",
                5,
                [1.0, 5.0, "The train to the coast\nleaves from platform two."],
                [3600.0, 3605.0, "We arrive on time today."],
                new WriteOptions(lineEnding: LineEnding::Crlf),
            ],
            "TMPlayer+ with equals signs" => [
                "tmplayer_plus.txt",
                4,
                [2.0, 6.0, "Rain moves in from the west."],
                [150.0, 155.0, "Frost on the roads in the morning."],
                null,
            ],
            "TMPlayer with a line out of time order" => [
                "tmplayer_out_of_order.txt",
                4,
                [1.0, 4.0, "The ferry leaves at nine."],
                [12.0, 16.0, "We arrive at the island at noon."],
                null,
            ],
            "TMPlayer+ with line numbers and BOM" => [
                "tmplayer_multiline_bom.txt",
                3,
                [3.0, 7.0, "The shop opens at eight.\nFresh milk arrives at nine."],
                [12.0, 16.0, "Bring your own bag.\nPaper bags cost ten cents."],
                null,
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($file)->getCues();

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $this->row($cues[0]));
        $this->assertSame($lastCue, $this->row($cues[$cueCount - 1]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $file, int $cueCount, array $firstCue, array $lastCue, ?WriteOptions $options): void
    {
        // The formatter writes no end entry after the last cue. tmplayer_multiline_bom.txt has one 4 s after the start.
        $readOptions = new ReadOptions(lastCueDuration: 4);
        $subtitle    = $this->parseFile($file, $readOptions);
        $formatted   = $subtitle->toString(Format::TmPlayer, $options ?? new WriteOptions());

        $this->assertEquals($subtitle->getCues(), (new TmPlayerParser())->parse($formatted, $readOptions)->getCues());
        if ($options !== null) {
            $this->assertSame(file_get_contents(self::DIR . "real/$file"), $formatted);
        }
    }


    public static function variants(): array
    {
        return [
            "colon"                 => ["00:00:01:Where are you?|Home.\n"],
            "equals sign"           => ["0:00:01=Where are you?|Home.\n"],
            "line numbers"          => ["00:00:01,1=Where are you?\n00:00:01,2=Home.\n"],
            "line numbers and colon" => ["00:00:01,1:Where are you?\n00:00:01,2:Home.\n"],
        ];
    }


    #[DataProvider("variants")]
    public function testReadsTheVariants(string $content): void
    {
        $cues = (new TmPlayerParser())->parse($content, new ReadOptions())->getCues();

        $this->assertSame([[1.0, 6.0, "Where are you?\nHome."]], array_map($this->row(...), $cues));
    }


    public function testCueEndsAtTheNextCue(): void
    {
        $cues = (new TmPlayerParser())->parse("00:00:01:One\n00:00:03:Two\n00:00:09:\n", new ReadOptions())->getCues();

        $this->assertSame([[1.0, 3.0, "One"], [3.0, 9.0, "Two"]], array_map($this->row(...), $cues));
    }


    public function testALineOutOfTimeOrderEndsTheCueBeforeIt(): void
    {
        $cues = $this->parseFile("tmplayer_out_of_order.txt")->getCues();

        $this->assertSame([
            [1.0, 4.0, "The ferry leaves at nine."],
            [4.0, 7.0, "Please mind the gap."],
            [7.0, 10.0, "Tickets are sold\non the upper deck."],
            [12.0, 16.0, "We arrive at the island at noon."],
        ], array_map($this->row(...), $cues));
    }


    public function testLastCueDurationIsAConstructorArgument(): void
    {
        $cue = (new TmPlayerParser())->parse("00:01:00:Bye", new ReadOptions(lastCueDuration: 2.5))->getCues()[0];

        $this->assertSame([60.0, 62.5, "Bye"], $this->row($cue));
    }


    public function testLineNumberOneStartsANewCueAtTheSameTime(): void
    {
        $cues = (new TmPlayerParser())->parse("00:00:01,1=One\n00:00:01,1=Two\n", new ReadOptions())->getCues();

        $this->assertSame([[1.0, 1.0, "One"], [1.0, 6.0, "Two"]], array_map($this->row(...), $cues));
    }


    public function testKeepsColonsAndEscapesTagCharacters(): void
    {
        $cue = (new TmPlayerParser())->parse("00:00:01:Time: 10:30 <now> & then", new ReadOptions())->getCues()[0];

        $this->assertSame("Time: 10:30 &lt;now&gt; &amp; then", $cue->getText());
    }


    public function testStrictModeThrowsWithTheLineNumber(): void
    {
        try {
            (new TmPlayerParser())->parse("00:00:02:Fine\nbroken line\n", new ReadOptions());
            $this->fail("The parser accepted a line without a time.");
        } catch (ParsingException $exception) {
            $this->assertSame(2, $exception->getLineNumber());
            $this->assertStringContainsString("The line \"broken line\" is not a TMPlayer line.", $exception->getMessage());
        }
    }


    public function testLenientModeSkipsLinesWithoutATime(): void
    {
        $subtitle = (new TmPlayerParser())->parse(file_get_contents(self::DIR . "lenient/broken.txt"), new ReadOptions(lenient: true));

        $this->assertSame([[2.0, 9.0, "The museum opens at ten."], [9.0, 14.0, "The cafe is on the top floor."]], array_map($this->row(...), $subtitle->getCues()));
        $this->assertSame(
            [
                [1, 0, ParseWarningAction::Skipped, "The line \"Movie.Name.2004.DVDRip\" is not a TMPlayer line."],
                [3, 2, ParseWarningAction::Skipped, "The line \"00:0x:06:Entry is free on Mondays.\" is not a TMPlayer line."],
            ],
            TestSubtitles::warnings($subtitle)
        );
    }


    public function testSubtitleParseDetectsTmPlayer(): void
    {
        $this->assertCount(2, Subtitle::fromStringAutoDetectFormat("00:00:01:Where are you?\r\n00:00:04:Home.\r\n")->getCues());
    }


    private function row(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getText()];
    }
}
