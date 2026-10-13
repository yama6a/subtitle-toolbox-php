<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\SpruceStlReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\RealFiles;
use SubtitleToolbox\WriteOptions;

class SpruceStlParserTest extends TestCase
{
    use RealFiles;


    private static function realFilesDir(): string
    {
        return "spruce-stl/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::SpruceStl;
    }


    /**
     * Each case holds the file, the cue count, the first and the last cue, and the output options of a byte-for-byte
     * round trip, or null when the formatter writes the file in another shape.
     */
    public static function realFiles(): array
    {
        return [
            "mantas-done shape without header" => [
                "bakery.stl",
                2,
                [137.4, 140.4, "The bakery opens at seven,\nevery day of the week.", Alignment::BottomCenter],
                [3740.48, 3742.48, "Fresh bread, warm rolls.", Alignment::BottomCenter],
                null,
            ],
            "mantas-done shape with header" => [
                "bakery_with_header.stl",
                2,
                [137.4, 140.36, "The bakery opens at seven,\nevery day of the week.", Alignment::BottomCenter],
                [140.44, 142.48, "Fresh bread, warm rolls.", Alignment::BottomCenter],
                null,
            ],
            "DVD Studio Pro shape at 30 fps" => [
                "dvdsp_full_header_ntsc.stl",
                5,
                [3.8, 6.967, "The ferry to the island\nleaves at half past eight.", Alignment::BottomCenter],
                [15.333, 18.0, "Please keep your <i>boarding pass</i> ready.", Alignment::BottomCenter],
                new WriteOptions(lineEnding: LineEnding::Crlf),
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($file)->getCues();

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $this->describeCue($cues[0]));
        $this->assertSame($lastCue, $this->describeCue($cues[$cueCount - 1]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileRoundTrips(string $file, int $cueCount, array $firstCue, array $lastCue, ?WriteOptions $writeOptions): void
    {
        if ($writeOptions === null) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $content = file_get_contents(__DIR__ . "/../files/spruce-stl/real/$file");

        $this->assertSame($content, $this->parseFile($file)->toString(Format::SpruceStl, $writeOptions));
    }


    public function testCommandsSetTheStyleAndTheAlignmentOfLaterCues(): void
    {
        $cues = $this->parseFile("dvdsp_full_header_ntsc.stl")->getCues();

        $this->assertSame(["Tickets are <b>sold out</b> today."], $cues[1]->getLines());
        $this->assertSame(["<i>Over the water we go,</i>", "<i>the gulls are flying low.</i>"], $cues[2]->getLines());
        $this->assertSame(Alignment::TopCenter, $cues[3]->getAlignment());
        $this->assertSame(["HARBOUR MASTER"], $cues[3]->getLines());
        $this->assertSame(Alignment::BottomCenter, $cues[4]->getAlignment());
    }


    public function testATogglesSwitchesTheStyleOfTheCommands(): void
    {
        $content = "\$Italic = TRUE\n00:00:01:00 , 00:00:02:00 , Sung ^Ispoken^I sung|still sung\n00:00:03:00 , 00:00:04:00 , ^IPlain\n";

        $cues = Subtitle::fromString($content, Format::SpruceStl)->getCues();

        $this->assertSame(["<i>Sung</i> spoken <i>sung</i>", "<i>still sung</i>"], $cues[0]->getLines());
        $this->assertSame(["Plain"], $cues[1]->getLines());
    }


    public function testKeepsCommentsCommandsAndTheFileShapeInTheFormatData(): void
    {
        $subtitle = $this->parseFile("dvdsp_full_header_ntsc.stl");

        $fileData = $subtitle->findFormatData("spruce-stl");
        $this->assertSame(30.0, $fileData["frameRate"]);
        $this->assertSame(" , ", $fileData["separator"]);
        $this->assertSame("|", $fileData["lineBreak"]);
        $this->assertCount(29, $fileData["header"]);
        $this->assertSame("//Song lyrics in italics", "//" . $subtitle->getComments()[0]->text);
        $this->assertSame(2, $subtitle->getComments()[0]->beforeCueIndex);
        $this->assertSame(['$Italic         = True'], $subtitle->getCues()[2]->findFormatData("spruce-stl")["commands"]);
    }


    public function testFrameNumbersOf25OrMoreMean30Fps(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:15 , 00:00:02:25 , NTSC\n", Format::SpruceStl);

        $this->assertSame([1.5, 2.833], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
        $this->assertSame(30.0, $subtitle->findFormatData("spruce-stl")["frameRate"]);
    }


    public function testFrameNumbersBelow25Mean25Fps(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:15 , 00:00:02:24 , PAL\n", Format::SpruceStl);

        $this->assertSame([1.6, 2.96], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testLenientModeWarnsAboutTheGuessedFrameRate(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:15 , 00:00:02:00 , PAL\n", Format::SpruceStl, new ReadOptions(lenient: true));

        $warnings = $subtitle->getParseWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame("The file does not give its frame rate. The parser read the frames at 25 fps.", $warnings[0]->message);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
    }


    public function testTheFrameRateOfTheOptionsWins(): void
    {
        $options  = new ReadOptions(lenient: true, formats: [new SpruceStlReadOptions(frameRate: 24)]);
        $subtitle = Subtitle::fromString("00:00:01:12 , 00:00:02:00 , Film\n", Format::SpruceStl, $options);

        $this->assertSame(1.5, $subtitle->getCues()[0]->getStart());
        $this->assertSame(24.0, $subtitle->findFormatData("spruce-stl")["frameRate"]);
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testAFrameNumberAtTheFrameRateThrowsInStrictMode(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame number in \"00:00:01:25\" is not below the frame rate of 25 fps.");

        Subtitle::fromString("00:00:01:25 , 00:00:02:00 , Late\n", Format::SpruceStl, new ReadOptions(formats: [new SpruceStlReadOptions(frameRate: 25)]));
    }


    public function testLenientModeSkipsALineThatIsNoSubtitle(): void
    {
        $content  = "00:00:01:00 , 00:00:02:00 , One\n00:00:03:00 . 00:00:04:00 , Two\n00:00:05:00 , 00:00:06:00 , Three\n";
        $subtitle = Subtitle::fromString($content, Format::SpruceStl, new ReadOptions(lenient: true));

        $this->assertSame([["One"], ["Three"]], array_map(fn ($cue): array => $cue->getLines(), $subtitle->getCues()));
        $this->assertSame(ParseWarningAction::Skipped, $subtitle->getParseWarnings()[1]->action);
        $this->assertSame(2, $subtitle->getParseWarnings()[1]->lineNumber);
    }


    public function testStrictModeThrowsForALineThatIsNoSubtitle(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The line \"00:00:03:00 . 00:00:04:00 , Two\" is no Spruce STL subtitle, command or comment.");

        Subtitle::fromString("00:00:01:00 , 00:00:02:00 , One\n00:00:03:00 . 00:00:04:00 , Two\n", Format::SpruceStl);
    }


    /**
     * Adapted from testClientFile and testTimesBiggerThan24Hours of mantas-done/subtitles, tests/formats/StlTest.php
     * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/formats/StlTest.php), MIT.
     */
    public function testReadsTabsAroundTheCommasAndHoursAbove24(): void
    {
        $content  = "\$FadeOut\t\t\t=\t0\n\$TapeOffset\t\t\t=\tTRUE\n \n\$HorzAlign\t=\tCenter\n" .
                    "00:00:00:00\t,\t00:00:01:00\t,\ta\n00:00:01:00\t,\t00:00:02:00\t,\tb\n99:00:00:00 , 99:00:01:00 , c\n";
        $subtitle = Subtitle::fromString($content);

        $this->assertSame(Format::SpruceStl, $subtitle->findFormat());
        $this->assertSame(
            [[0.0, 1.0, ["a"]], [1.0, 2.0, ["b"]], [356400.0, 356401.0, ["c"]]],
            array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues())
        );
        $this->assertSame("\t,\t", $subtitle->findFormatData("spruce-stl")["separator"]);
    }
}
