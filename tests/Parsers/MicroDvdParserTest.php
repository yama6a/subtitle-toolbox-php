<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class MicroDvdParserTest extends TestCase
{
    public static function realFiles(): array
    {
        return [
            "mantas-done plain" => [
                "sub_microdvd.sub", 23.976, 2,
                [137.387, 140.39, ["Passengers, the train is now", "arriving at the central station."]],
                [3740.49, 3742.492, ["Thank you, conductor."]],
            ],
            "mantas-done styles" => [
                "sub_microdvd_with_styles.sub", 23.976, 2,
                [137.387, 140.39, ["Passengers, the train is now", "<i>arriving at the central station.</i>"]],
                [3740.49, 3742.492, ["<font color=\"#ff0000\"><b><u>Thank you, conductor.</u></b></font>"]],
            ],
            "subsrt sample" => [
                "subsrt_sample.sub", 25, 5,
                [599.0, 4160.0, ["Hi, my name is Alice Miller and this is John Brown"]],
                [16700.0, 21480.0, ["Okay, so we have all the ingredients laid out here"]],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, float $frameRate, int $cueCount, array $first, array $last): void
    {
        $subtitle = (new MicroDvdParser())->parse(file_get_contents(__DIR__ . "/../files/microdvd/real/$file"), new ReadOptions(formats: [new MicroDvdReadOptions($frameRate)]));
        $cues     = $subtitle->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertSame($last, [end($cues)->getStart(), end($cues)->getEnd(), end($cues)->getLines()]);
    }


    public function testFrameRateLineSetsTheFrameRate(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/microdvd/valid.sub"), Format::MicroDvd);
        $cues     = $subtitle->getCues();

        $this->assertSame(4, count($cues));
        $this->assertSame(1.001, $cues[0]->getStart());
        $this->assertSame(3.003, $cues[0]->getEnd());
        $this->assertSame(["frameRate" => 23.976], $subtitle->findFormatData("microdvd"));
    }


    public function testControlCodesBecomeCoreMarkup(): void
    {
        $cues = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/microdvd/valid.sub"), Format::MicroDvd)->getCues();

        $this->assertSame(["Hello", "<i>world</i>"], $cues[0]->getLines());
        $this->assertSame(["<font color=\"#ff0000\">Red text</font>"], $cues[1]->getLines());
        $this->assertSame(["<b>Bold</b>", "<b>on two lines</b>"], $cues[2]->getLines());
        $this->assertSame(["<u><s>Underline &lt; strike &amp; more</s></u>"], $cues[3]->getLines());
    }


    public function testOtherControlCodesGoToTheFormatData(): void
    {
        $cues = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/microdvd/valid.sub"), Format::MicroDvd)->getCues();

        $this->assertSame("{f:Arial}{s:20}", $cues[2]->findFormatData("microdvd")["lines"][0]["otherCodes"]);
        $this->assertSame("", $cues[2]->findFormatData("microdvd")["lines"][1]["otherCodes"]);
        $this->assertSame("{P:0}", $cues[3]->findFormatData("microdvd")["lines"][0]["otherCodes"]);
    }


    public function testLowerCaseColorOverridesUpperCaseColorOnItsLine(): void
    {
        $subtitle = (new MicroDvdParser())->parse('{0}{25}{C:$0000FF}{Y:i}One|{c:$00FF00}{y:b}Two', new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame(
            ["<font color=\"#ff0000\"><i>One</i></font>", "<font color=\"#00ff00\"><b><i>Two</i></b></font>"],
            $subtitle->getCues()[0]->getLines()
        );
    }


    public function testCodesInsideTheLineStayText(): void
    {
        $subtitle = (new MicroDvdParser())->parse("{0}{25}Hello, {y:i}world", new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame(["Hello, {y:i}world"], $subtitle->getCues()[0]->getLines());
    }


    public function testLatin1TextKeepsItsBytes(): void
    {
        $subtitle = (new MicroDvdParser())->parse("{0}{25}{y:i}caf\xE9 & bread|<3 jam", new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame(["<i>caf\xE9 &amp; bread</i>", "&lt;3 jam"], $subtitle->getCues()[0]->getLines());
    }


    public function testConstructorFrameRateWinsOverFrameRateLine(): void
    {
        $subtitle = (new MicroDvdParser())->parse("{1}{1}23.976\n{25}{50}Hello", new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame(1, count($subtitle->getCues()));
        $this->assertSame(1.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(2.0, $subtitle->getCues()[0]->getEnd());
    }


    public function testWindowsLineEndingsBomAndEmptyLinesAreAccepted(): void
    {
        $subtitle = Subtitle::fromString("\xEF\xBB\xBF\r\n{1}{1}25\r\n\r\n{25}{50}Hello\r\n", Format::MicroDvd);

        $this->assertSame(["Hello"], $subtitle->getCues()[0]->getLines());
    }


    public function testMissingFrameRateThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame rate is unknown");
        Subtitle::fromString("{25}{50}Hello", Format::MicroDvd);
    }


    public function testStrictModeWithoutFrameRateNamesLenientMode(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame rate is unknown. Set MicroDvdReadOptions::\$frameRate, start the file with {1}{1}<fps>, " .
                                      "or read the file in lenient mode for 23.976 fps.");
        (new MicroDvdParser())->parse("{0}{25}Hello");
    }


    public function testLenientModeWithoutFrameRateUses23976FpsAndWarns(): void
    {
        $parser   = new MicroDvdParser();
        $subtitle = $parser->parse("{0}{25}Hello", new ReadOptions(lenient: true));

        $this->assertSame(1.043, $subtitle->getCues()[0]->getEnd());
        $this->assertSame(["frameRate" => 23.976], $subtitle->findFormatData("microdvd"));
        $this->assertEquals(
            [new ParseWarning("The file has no {1}{1}<fps> line. The parser used 23.976 fps.", null, null, [], ParseWarningAction::Repaired)],
            $parser->getWarnings()
        );
    }


    public function testLenientModeKeepsTheFrameRateOfTheOptions(): void
    {
        $parser   = new MicroDvdParser();
        $subtitle = $parser->parse("{0}{25}Hello", new ReadOptions(lenient: true, formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame(1.0, $subtitle->getCues()[0]->getEnd());
        $this->assertSame([], $parser->getWarnings());
    }


    public function testLenientModeKeepsTheFrameRateLine(): void
    {
        $parser   = new MicroDvdParser();
        $subtitle = $parser->parse("{1}{1}25\n{0}{25}Hello", new ReadOptions(lenient: true));

        $this->assertSame(1.0, $subtitle->getCues()[0]->getEnd());
        $this->assertSame([], $parser->getWarnings());
    }


    public static function realFilesWithoutFrameRate(): array
    {
        return [
            "mantas-done plain"  => ["sub_microdvd.sub", 2],
            "mantas-done styles" => ["sub_microdvd_with_styles.sub", 2],
            "subsrt sample"      => ["subsrt_sample.sub", 5],
        ];
    }


    #[DataProvider("realFilesWithoutFrameRate")]
    public function testRealFileWithoutFrameRateParsesInLenientMode(string $file, int $cueCount): void
    {
        $content = file_get_contents(__DIR__ . "/../files/microdvd/real/$file");
        $lenient = Subtitle::fromStringAutoDetectFormat($content, new ReadOptions(lenient: true));
        $at23976 = Subtitle::fromString($content, Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(23.976)]));

        $this->assertCount($cueCount, $lenient->getCues());
        $this->assertEquals($at23976->getCues(), $lenient->getCues());
        $this->assertCount(1, $lenient->getParseWarnings());
    }


    public function testZeroFrameRateThrowsException(): void
    {
        try {
            Subtitle::fromString("{1}{1}0\n{25}{50}Hello", Format::MicroDvd);
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            $this->assertStringEndsWith($exception->getPrevious()->getMessage(), $exception->getMessage());
        }
    }


    public function testLineWithoutFramesThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The line \"00:00:01,000 --> 00:00:02,000\" is not a MicroDVD cue.");
        Subtitle::fromString("{1}{1}25\n{25}{50}Hello\n00:00:01,000 --> 00:00:02,000", Format::MicroDvd);
    }
}
