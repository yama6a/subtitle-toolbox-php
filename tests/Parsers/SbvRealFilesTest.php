<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\RealFiles;

class SbvRealFilesTest extends TestCase
{
    use RealFiles;


    private static function realFilesDir(): string
    {
        return "sbv/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Sbv;
    }


    public static function realFiles(): array
    {
        return [
            "subsrt shape" => [
                "subsrt_shape.sbv",
                5,
                [0.48, 3.95, "&gt;&gt; MARA: Good morning, this is Mara Lind[br]and this is Tom Berg"],
                [16.3, 21.15, "First, we put the flour and the water on the table"],
            ],
            "YouTube Studio LF" => [
                "youtube_studio_lf.sbv",
                9,
                [0.0, 2.24, "[Music]"],
                [3600.0, 3603.5, "That was the weather. Thank you for watching.\n[Applause]"],
            ],
            "YouTube Studio CR LF" => [
                "youtube_studio_crlf.sbv",
                6,
                [1.5, 4.0, "The 7:15 train to the harbour leaves from platform 2."],
                [18.43, 21.0, "The next stop is Market Square."],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($fileName)->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    public static function realFileNames(): array
    {
        return array_map(fn (array $file): array => [$file[0]], self::realFiles());
    }


    #[DataProvider("realFileNames")]
    public function testRealFileSurvivesARoundTrip(string $fileName): void
    {
        $subtitle  = $this->parseFile($fileName);
        $formatted = $subtitle->toString(Format::Sbv);
        $reparsed  = Subtitle::fromString($formatted, Format::Sbv);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($formatted, $reparsed->toString(Format::Sbv));
    }


    public function testCueWithoutTextIsKeptWithoutLines(): void
    {
        $cues = $this->parseFile("own_empty_cue.sbv")->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([23.292, 28.898, []], [$cues[1]->getStart(), $cues[1]->getEnd(), $cues[1]->getLines()]);
    }


    #[DataProvider("realFileNames")]
    public function testRealFileFormatsToItsOwnTextWithLfEndings(string $fileName): void
    {
        $expected = rtrim(str_replace("\r\n", "\n", file_get_contents(__DIR__ . "/../files/sbv/real/" . $fileName)), "\n") . "\n";

        $this->assertSame($expected, $this->parseFile($fileName)->toString(Format::Sbv));
    }
}
