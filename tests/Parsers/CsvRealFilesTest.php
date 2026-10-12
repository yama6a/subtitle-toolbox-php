<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\ReadGoldens;
use SubtitleToolbox\Tests\Support\RealFiles;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\WriteOptions;

class CsvRealFilesTest extends TestCase
{
    use RealFiles;


    private static function realFilesDir(): string
    {
        return "csv/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Csv;
    }


    public static function realFiles(): array
    {
        return [
            "mantas-done shape" => [
                "mantas_done_shape.csv",
                new CsvReadOptions(new CsvColumns()),
                5,
                [1.5, 4.0, "The bakery opens at seven, every day."],
                [16.0, 18.2, "Please queue at the door, not inside."],
                ["lineEnding" => "\n", "bom" => false],
            ],
            "Excel German locale" => [
                "excel_de_semicolon.csv",
                new CsvReadOptions(new CsvColumns(end: "Ende", speaker: "Sprecher")),
                4,
                [2.0, 4.5, "<v Lena>Der Zug nach Basel fährt um 7:15 Uhr."],
                [12.0, 14.64, "<v Lena>Danke; bis morgen!"],
                ["lineEnding" => "\r\n", "bom" => true],
            ],
            "dubbing script" => [
                "dubbing_script.csv",
                new CsvReadOptions(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character"), frameRate: 25),
                4,
                [36001.48, 36004.0, "<v NARRATOR>The weather turns cold tonight."],
                [36008.2, 36013.2, "<v NARRATOR>Snow falls in the hills, rain in the valley."],
                ["lineEnding" => "\n", "bom" => false],
            ],
            "spreadsheet TSV" => [
                "sheets_export.tsv",
                new CsvReadOptions(new CsvColumns()),
                4,
                [0.5, 3.0, "Welcome to the station."],
                [10.0, 12.0, "Have a safe trip."],
                ["lineEnding" => "\n", "bom" => false],
            ],
            "openai-whisper TSV" => [
                "own_openai_whisper.tsv",
                new CsvReadOptions(),
                6,
                [0.0, 2.34, "Good morning and welcome to the harbour tour."],
                [17.02, 19.3, "Thank you, and enjoy the trip!"],
                ["lineEnding" => "\n", "bom" => false],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, CsvReadOptions $csvOptions, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($fileName, new ReadOptions(formats: [$csvOptions]))->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileFormatsToItsOwnBytes(string $fileName, CsvReadOptions $csvOptions, int $cueCount, array $firstCue, array $lastCue, array $options): void
    {
        $content = file_get_contents(__DIR__ . "/../files/csv/real/$fileName");

        $this->assertSame($content, $this->parseFile($fileName, new ReadOptions(formats: [$csvOptions]))->toString(Format::Csv, new WriteOptions(
            lineEnding: LineEnding::from($options["lineEnding"]),
            bom: $options["bom"],
        )));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTripInTheDefaultLayout(string $fileName, CsvReadOptions $csvOptions): void
    {
        $subtitle = $this->parseFile($fileName, new ReadOptions(formats: [$csvOptions]))->setFormatData("csv", []);
        $fresh    = (new CsvParser())->parse($subtitle->toString(Format::Csv), new ReadOptions());

        $this->assertSame(array_map($this->describeCue(...), $subtitle->getCues()), array_map($this->describeCue(...), $fresh->getCues()));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue): ?string => $cue->findIdentifier(), $subtitle->getCues()),
            array_map(fn (SubtitleCue $cue): ?string => $cue->findIdentifier(), $fresh->getCues())
        );
    }


    /**
     * @return array<string, array{string}>
     */
    public static function whisperFiles(): array
    {
        return [
            "openai-whisper TSV" => ["own_openai_whisper.tsv"],
            "whisper.cpp CSV"    => ["own_whisper_cpp.csv"],
        ];
    }


    #[DataProvider("whisperFiles")]
    public function testWhisperFileMatchesItsReadGolden(string $fileName): void
    {
        $this->assertStringEqualsFile(TestFiles::DIR . "csv/real/expected/$fileName.json", ReadGoldens::render("csv/real/$fileName", Format::Csv, new ReadOptions()));
    }


    #[DataProvider("whisperFiles")]
    public function testWhisperFileConvertsToItsSubRipGolden(string $fileName): void
    {
        $this->assertStringEqualsFile(TestFiles::DIR . "csv/real/expected/$fileName.to.srt.srt", $this->parseFile($fileName)->toString(Format::SubRip));
    }


    public function testWhisperCppTableKeepsItsQuotedTextColumn(): void
    {
        $content = file_get_contents(__DIR__ . "/../files/csv/real/own_whisper_cpp.csv");

        // whisper.cpp starts each text with a space, which the cue lines do not keep.
        $this->assertSame(str_replace(",\" ", ",\"", $content), $this->parseFile("own_whisper_cpp.csv")->toString(Format::Csv, new WriteOptions(bom: false)));
    }


    public function testDubbingScriptKeepsTheNotesColumn(): void
    {
        $cues = $this->parseFile("dubbing_script.csv", new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character"), frameRate: 25)]))->getCues();

        $this->assertSame(["columns" => ["Notes" => "warm tone"]], $cues[1]->findFormatData("csv"));
        $this->assertSame(36005.8, $cues[2]->getStart());
    }


    public function testTsvKeepsTheIdentifiers(): void
    {
        $cues = $this->parseFile("sheets_export.tsv", new ReadOptions(formats: [new CsvReadOptions()]))->getCues();

        $this->assertSame(["intro", "platform", null, "end"], array_map(fn (SubtitleCue $cue): ?string => $cue->findIdentifier(), $cues));
    }
}
