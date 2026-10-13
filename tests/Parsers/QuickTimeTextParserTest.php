<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;
use SubtitleToolbox\WriteOptions;

class QuickTimeTextParserTest extends TestCase
{
    use RealFiles;

    private const HEADER = "{QTtext} {font:Tahoma}\n{plain} {size:20}\n{timeScale:30}\n{width:160} {height:32}\n{timestamps:absolute} {language:0}\n";


    private static function realFilesDir(): string
    {
        return "qttext/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::QuickTimeText;
    }


    /**
     * Each case holds the file, the cue count, the first and the last cue, and the output options of a byte-for-byte round trip.
     */
    public static function realFiles(): array
    {
        return [
            "Subtitle Edit shape" => [
                "subtitle_edit_shape.txt",
                3,
                [2.5, 5.0, "The museum opens at ten,\nthe café at nine.", Alignment::BottomCenter],
                [3600.0, 3603.333, "Closing time.", Alignment::BottomCenter],
                new WriteOptions(lineEnding: LineEnding::Crlf),
            ],
            "QuickTime export shape" => [
                "quicktime_export_styles.txt",
                4,
                [1.5, 4.0, "The museum opens at ten.", Alignment::BottomCenter],
                [9.75, 12.0, "Who is there? <b>Hello?</b>", Alignment::BottomCenter],
                new WriteOptions(),
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
    public function testRealFileRoundTrips(string $file, int $cueCount, array $firstCue, array $lastCue, WriteOptions $writeOptions): void
    {
        $content = file_get_contents(__DIR__ . "/../files/qttext/real/$file");

        $this->assertSame($content, $this->parseFile($file)->toString(Format::QuickTimeText, $writeOptions));
    }


    /**
     * Adapted from testConvertingToInternalFormat of mantas-done/subtitles, tests/formats/TxtQuickTimeTest.php
     * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/formats/TxtQuickTimeTest.php), MIT.
     */
    public function testTheFractionCountsUnitsOfTheTimeScale(): void
    {
        $content = self::HEADER . "[00:02:17.11]\nThe ferry leaves at nine,\nfrom the north pier.\n[00:02:20.09]\n\n[01:02:20.11]\nTickets at the kiosk.\n[01:02:22.12]\n\n";

        $this->assertEquals(
            [new SubtitleCue(137.367, 140.3, ["The ferry leaves at nine,", "from the north pier."]), new SubtitleCue(3740.367, 3742.4, "Tickets at the kiosk.")],
            Subtitle::fromString($content)->getCues()
        );
    }


    /**
     * Adapted from testParsesWithNoEndTimestamp of mantas-done/subtitles, tests/formats/TxtQuickTimeTest.php
     * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/formats/TxtQuickTimeTest.php), MIT.
     */
    public function testASampleEndsAtTheNextTimestampAndTheLastLastsTheLastCueDuration(): void
    {
        $content = self::HEADER . "[00:00:00.00]\na\n\n[00:00:12.00]\nb\n";

        $this->assertEquals(
            [new SubtitleCue(0, 12, "a"), new SubtitleCue(12, 13, "b")],
            Subtitle::fromString($content, Format::QuickTimeText, new ReadOptions(lastCueDuration: 1))->getCues()
        );
    }


    public function testReadsOtherTimeScalesAndDecimalFractionsWithoutATimeScale(): void
    {
        $tenths  = Subtitle::fromString("{QTtext}{timeScale:10}\n[00:00:07.5]\nRain\n[00:00:09.0]\n")->getCues()[0];
        $decimal = Subtitle::fromString("{QTtext}{font:Geneva}\n[00:00:07.25]\nRain\n[00:00:09.5]\n")->getCues()[0];

        $this->assertSame([7.5, 9.0], [$tenths->getStart(), $tenths->getEnd()]);
        $this->assertSame([7.25, 9.5], [$decimal->getStart(), $decimal->getEnd()]);
    }


    public function testAddsRelativeTimestampsToThePreviousOne(): void
    {
        $content = "{QTtext}{timeScale:600}{timeStamps:relative}\n[00:00:01.300]\nOne\n[00:00:02.000]\n[00:00:01.000]\nTwo\n[00:00:02.000]\n";

        $this->assertEquals(
            [new SubtitleCue(1.5, 3.5, "One"), new SubtitleCue(4.5, 6.5, "Two")],
            Subtitle::fromString($content)->getCues()
        );
    }


    public function testStyleDescriptorsLastUntilPlain(): void
    {
        $content = "{QTtext}{timeScale:30}{plain}\n[00:00:01.00]\n{italic}Sung\n[00:00:02.00]\nstill sung {underline}loud\n[00:00:03.00]\n{plain}Spoken\n[00:00:04.00]\n";

        $this->assertSame(
            [["<i>Sung</i>"], ["<i>still sung <u>loud</u></i>"], ["Spoken"]],
            array_map(fn (SubtitleCue $cue): array => $cue->getLines(), Subtitle::fromString($content)->getCues())
        );
    }


    public function testStrictModeNeedsQtTextFirst(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with {QTtext}.");

        Subtitle::fromString("{timeScale:30}\n[00:00:01.00]\nOne\n[00:00:02.00]\n", Format::QuickTimeText);
    }


    public function testLenientModeReadsAFileWithoutQtText(): void
    {
        $subtitle = Subtitle::fromString("[00:00:01.00]\nOne\n[00:00:02.00]\n", Format::QuickTimeText, new ReadOptions(lenient: true));

        $this->assertEquals([new SubtitleCue(1, 2, "One")], $subtitle->getCues());
        $this->assertSame(ParseWarningAction::Repaired, $subtitle->getParseWarnings()[0]->action);
    }


    public function testAFractionAtTheTimeScaleThrowsInStrictModeAndCountsInLenientMode(): void
    {
        $content = "{QTtext}{timeScale:30}\n[00:00:01.45]\nOne\n[00:00:04.00]\n";

        $subtitle = Subtitle::fromString($content, Format::QuickTimeText, new ReadOptions(lenient: true));
        $this->assertSame(2.5, $subtitle->getCues()[0]->getStart());
        $this->assertSame(2, $subtitle->getParseWarnings()[0]->lineNumber);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The timestamp \"[00:00:01.45]\" counts 45 units, but the time scale is 30 per second.");
        Subtitle::fromString($content, Format::QuickTimeText);
    }
}
