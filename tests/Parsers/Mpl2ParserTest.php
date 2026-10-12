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

class Mpl2ParserTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/mpl2/";


    private static function realFilesDir(): string
    {
        return "mpl2/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Mpl2;
    }


    /**
     * Each case holds the file, its encoding, the cue count, the first and the last cue, and the output options of a
     * byte-for-byte round trip, or null when the formatter writes the file in another shape.
     */
    public static function realFiles(): array
    {
        return [
            "Windows-1250 with CR LF" => [
                "../napiprojekt_cp1250.txt",
                "Windows-1250",
                8,
                [1.2, 4.5, "Dzień dobry, piekarnia jest już otwarta."],
                [360.0, 364.5, "Do zobaczenia w poniedziałek!"],
                new WriteOptions(lineEnding: LineEnding::Crlf),
            ],
            "pysubs2 shape with BOM" => [
                "pysubs2_shape_bom.txt",
                null,
                6,
                [0.0, 2.5, "The bakery opens at six."],
                [3600.0, 3604.2, "Closed on Sundays."],
                null,
            ],
            "Subtitle Edit shape" => [
                "subtitle_edit_shape.txt",
                null,
                5,
                [1.5, 4.0, "The ferry leaves at nine."],
                [18.0, 22.0, "Arrival in two hours."],
                null,
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, ?string $encoding, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($file, new ReadOptions(encoding: $encoding))->getCues();

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $this->row($cues[0]));
        $this->assertSame($lastCue, $this->row($cues[$cueCount - 1]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $file, ?string $encoding, int $cueCount, array $firstCue, array $lastCue, ?WriteOptions $options): void
    {
        $subtitle  = $this->parseFile($file, new ReadOptions(encoding: $encoding));
        $formatted = $subtitle->toString(Format::Mpl2, $options ?? new WriteOptions());

        $this->assertEquals($subtitle->getCues(), (new Mpl2Parser())->parse($formatted, new ReadOptions())->getCues());
        if ($options !== null) {
            $original = file_get_contents(self::DIR . "real/$file");
            $this->assertSame($original, $encoding === null ? $formatted : iconv("UTF-8", $encoding, $formatted));
        }
    }


    public function testReadsItalicsAndLineBreaks(): void
    {
        $cues = (new Mpl2Parser())->parse("[12][45]Where are you?|/Home.\n[50][60]/ Both| / lines\n", new ReadOptions())->getCues();

        $this->assertSame([1.2, 4.5, "Where are you?\n<i>Home.</i>"], $this->row($cues[0]));
        $this->assertSame([5.0, 6.0, "<i>Both</i>\n<i>lines</i>"], $this->row($cues[1]));
    }


    public function testKeepsASlashInsideALineAsText(): void
    {
        $cue = (new Mpl2Parser())->parse("[0][10]Either/or <b>", new ReadOptions())->getCues()[0];

        $this->assertSame("Either/or &lt;b&gt;", $cue->getText());
    }


    public function testSortsCuesByStart(): void
    {
        $cues = (new Mpl2Parser())->parse("[30][40]Second\n[10][20]First\n", new ReadOptions())->getCues();

        $this->assertSame(["First", "Second"], [$cues[0]->getText(), $cues[1]->getText()]);
    }


    public function testStrictModeThrowsWithTheLineNumber(): void
    {
        try {
            (new Mpl2Parser())->parse(file_get_contents(self::DIR . "lenient/broken.txt"), new ReadOptions());
            $this->fail("The parser accepted a line without times.");
        } catch (ParsingException $exception) {
            $this->assertSame(1, $exception->getLineNumber());
            $this->assertStringContainsString("The line \"Downloaded from a subtitle site\" is not an MPL2 cue.", $exception->getMessage());
        }
    }


    public function testLenientModeSkipsLinesWithoutTimes(): void
    {
        $subtitle = (new Mpl2Parser())->parse(file_get_contents(self::DIR . "lenient/broken.txt"), new ReadOptions(lenient: true));

        $this->assertSame([[1.0, 3.0, "The market opens at ten."], [7.0, 9.5, "<i>Bring a basket.</i>"]], array_map($this->row(...), $subtitle->getCues()));
        $this->assertSame(
            [
                [1, 0, ParseWarningAction::Skipped, "The line \"Downloaded from a subtitle site\" is not an MPL2 cue."],
                [3, 2, ParseWarningAction::Skipped, "The line \"[4x][60]The stalls sell fish.\" is not an MPL2 cue."],
            ],
            TestSubtitles::warnings($subtitle)
        );
    }


    public function testSubtitleParseDetectsMpl2(): void
    {
        $subtitle = Subtitle::fromString("[12][45]Where are you?|/Home.\r\n");

        $this->assertSame("[12][45]Where are you?|/Home.\n", $subtitle->toString(Format::Mpl2));
    }


    private function row(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getText()];
    }
}
