<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\WriteOptions;

class SubViewerRealFilesTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/subviewer/real/";


    private static function realFilesDir(): string
    {
        return "subviewer/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::SubViewer;
    }


    public static function realFiles(): array
    {
        return [
            "SubViewer 2 with CR LF" => [
                "subviewer2_crlf.sub",
                6,
                [1.5, 4.0, "The bakery opens at six."],
                [3600.0, 3603.5, "See you tomorrow morning."],
                true,
            ],
            "SubViewer 2 with BOM and header" => [
                "mantas_headers_shape.sub",
                2,
                [137.44, 140.38, "Rain moves in from the west\nin the late afternoon."],
                [3740.48, 3742.5, "Tomorrow stays dry."],
                true,
            ],
            "SubViewer 2 without header" => [
                "mantas_plain_shape.sub",
                2,
                [137.4, 140.4, "The train to the harbour\nleaves from platform 2."],
                [3740.5, 3742.5, "Mind the gap."],
                false,
            ],
            "SubViewer 1 from Subtitle Edit" => [
                "subtitle_edit_v1_shape.sub",
                4,
                [2.0, 5.0, "The ferry leaves at nine."],
                [90.0, 94.0, "The last boat returns at six."],
                true,
            ],
            "SubViewer 1 with delay" => [
                "subviewer1_delay.sub",
                4,
                [3.0, 6.0, "Sunny in the north\nand cloudy in the south."],
                [17.0, 22.0, "Back at eight with the news."],
                false,
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $subtitle = $this->parseFile($fileName);
        $cues     = $subtitle->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $fileName): void
    {
        $subtitle  = $this->parseFile($fileName);
        $formatted = $subtitle->toString(Format::SubViewer, $this->optionsFor($fileName));
        $reparsed  = Subtitle::fromString($formatted, Format::SubViewer);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
        $this->assertSame($subtitle->findFormatData("subviewer"), $reparsed->findFormatData("subviewer"));
    }


    public static function filesWithFormatterShape(): array
    {
        // The formatter adds a header block to a file without one, and end lines to cues without them.
        return array_filter(self::realFiles(), fn (array $file): bool => $file[4]);
    }


    #[DataProvider("filesWithFormatterShape")]
    public function testRealFileFormatsToItsOwnBytes(string $fileName): void
    {
        $content    = file_get_contents(self::DIR . $fileName);
        $lineEnding = $this->optionsFor($fileName)->lineEnding->value;

        $this->assertSame(
            rtrim($content, "\r\n") . $lineEnding,
            $this->parseFile($fileName)->toString(Format::SubViewer, $this->optionsFor($fileName))
        );
    }


    public function testSubViewer2HeaderGoesToMetadataAndFormatData(): void
    {
        $subtitle = $this->parseFile("subviewer2_crlf.sub");

        $this->assertSame(
            [Subtitle::METADATA_TITLE => "Morning at the bakery", Subtitle::METADATA_AUTHOR => "Jane Doe"],
            $subtitle->getAllMetadata()
        );
        $this->assertSame(
            [
                "version" => 2,
                "header"  => [
                    "SOURCE"   => "",
                    "PRG"      => "",
                    "FILEPATH" => "",
                    "DELAY"    => "0",
                    "CD TRACK" => "0",
                    "COMMENT"  => "Times in centiseconds",
                ],
                "style"   => "[COLF]&HFFFFFF,[STYLE]bd,[SIZE]18,[FONT]Arial",
            ],
            $subtitle->findFormatData("subviewer")
        );
        $this->assertSame(["Flour &amp; water &lt;and&gt; a pinch of salt."], $subtitle->getCues()[2]->getLines());
    }


    public function testSubViewer1DelayIsAddedToEveryTime(): void
    {
        $subtitle = $this->parseFile("subviewer1_delay.sub");

        $this->assertSame(
            [[3.0, 6.0], [8.0, 11.0], [11.0, 14.0], [17.0, 22.0]],
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues())
        );
        $this->assertSame(["version" => 1, "header" => ["DELAY" => "0"]], $subtitle->findFormatData("subviewer"));
        $this->assertSame("Tom Berg", $subtitle->findMetadata(Subtitle::METADATA_AUTHOR));
    }


    private function optionsFor(string $fileName): WriteOptions
    {
        $content = file_get_contents(self::DIR . $fileName);

        return new WriteOptions(
            lineEnding: str_contains($content, "\r\n") ? LineEnding::Crlf : LineEnding::Lf,
            bom: StringHelpers::hasUtf8Bom($content),
            formats: [new SubViewerWriteOptions(version: str_contains($content, SubViewerParser::START_SCRIPT) ? SubViewerVersion::V1 : SubViewerVersion::V2)],
        );
    }
}
