<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class MicroDvdFormatterTest extends TestCase
{
    public static function realFiles(): array
    {
        return [
            ["sub_microdvd.sub", 23.976],
            ["sub_microdvd_with_styles.sub", 23.976],
            ["subsrt_sample.sub", 25],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileRoundTrips(string $file, float $frameRate): void
    {
        $raw      = file_get_contents(__DIR__ . "/../files/microdvd/real/$file");
        $subtitle = (new MicroDvdParser())->parse($raw, new ReadOptions(formats: [new MicroDvdReadOptions($frameRate)]));

        // Some source files have no line break after the last cue.
        $this->assertSame(
            rtrim($raw, "\n") . "\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: $frameRate)]))
        );
    }


    public function testValidFileRoundTripsWithFrameRateLine(): void
    {
        $raw = file_get_contents(__DIR__ . "/../files/microdvd/valid.sub");

        $this->assertSame($raw, Subtitle::fromString($raw, Format::MicroDvd)->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 23.976, writeFrameRateLine: true)])));
    }


    public function testNullFrameRateTakesTheStoredFrameRate(): void
    {
        $raw      = file_get_contents(__DIR__ . "/../files/microdvd/valid.sub");
        $subtitle = Subtitle::fromString($raw, Format::MicroDvd);

        $this->assertSame($raw, $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(writeFrameRateLine: true)])));
        $this->assertSame($raw, (new MicroDvdFormatter())->format($subtitle, new WriteOptions(formats: [new MicroDvdWriteOptions(writeFrameRateLine: true)])));
        $this->assertSame($raw, (new MicroDvdFormatter())->format($subtitle));
    }


    public function testFrameRateLineOfTheInputIsKept(): void
    {
        $raw      = file_get_contents(__DIR__ . "/../files/microdvd/real/own_max_frames.sub");
        $subtitle = Subtitle::fromString($raw, Format::MicroDvd);

        $this->assertSame(rtrim($raw, "\n") . "\n", $subtitle->toString(Format::MicroDvd));
        $this->assertEquals($subtitle->getCues(), Subtitle::fromString($subtitle->toString(Format::MicroDvd), Format::MicroDvd)->getCues());
    }


    public function testRemovingTheFrameRateLineFieldDropsTheLine(): void
    {
        $subtitle = Subtitle::fromString("{1}{1}25\n{25}{50}Hello", Format::MicroDvd);
        $subtitle->setFormatData("microdvd", ["frameRate" => 25.0]);

        $this->assertSame("{25}{50}Hello\n", $subtitle->toString(Format::MicroDvd));
    }


    public function testFrameRateOptionWinsOverTheStoredFrameRate(): void
    {
        $subtitle = Subtitle::fromString("{1}{1}25\n{25}{50}Hello\n", Format::MicroDvd);

        $this->assertSame("{1}{1}50\n{50}{100}Hello\n",
                          $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 50, writeFrameRateLine: true)])));
    }


    public function testNullFrameRateWithoutAStoredFrameRateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MicroDVD output needs the frame rate of the video. Set MicroDvdWriteOptions::\$frameRate.");
        (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(writeFrameRateLine: true)]));
    }


    public function testFrameRateOptionIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MicroDVD output needs the frame rate of the video. Set MicroDvdWriteOptions::\$frameRate.");
        (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->toString(Format::MicroDvd);
    }


    public function testCoreMarkupBecomesControlCodes(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, [
            "<font color=\"#ff0000\"><b><i>Red</i></b></font>",
            "<u><s>Under &amp; strike</s></u>",
            "Partly <i>italic</i> &lt;3",
        ]));

        $this->assertSame(
            "{25}{50}{c:\$0000FF}{y:b}{y:i}Red|{y:u}{y:s}Under & strike|Partly italic <3\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]))
        );
    }


    public function testSingleQuotedAndUnquotedColoursBecomeColourCodes(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "<font color='#ff0000'>Red</font>"))
            ->addCue(new SubtitleCue(3, 4, "<font color=#00ff00>Green</font>"));

        $this->assertSame(
            "{25}{50}{c:\$0000FF}Red\n{75}{100}{c:\$00FF00}Green\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]))
        );
    }


    public function testColourWithOtherCaseSpacingOrAttributesBecomesColourCode(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "<FONT COLOR=\"#FF0000\">Red</FONT>"))
            ->addCue(new SubtitleCue(3, 4, "<font color = '#00ff00' >Green</font>"))
            ->addCue(new SubtitleCue(5, 6, "<font face=\"Arial\" color=\"#0000ff\">Blue</font>"));

        $this->assertSame(
            "{25}{50}{c:\$0000FF}Red\n{75}{100}{c:\$00FF00}Green\n{125}{150}{c:\$FF0000}Blue\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]))
        );
    }


    public function testStripAllTagsOptionDropsStyleCodes(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<i>Hello</i>"));

        $this->assertSame("{25}{50}Hello\n", $subtitle->toString(Format::MicroDvd, new WriteOptions(stripTags: true, formats: [new MicroDvdWriteOptions(frameRate: 25)])));
    }


    public function testStripAllTagsOptionDropsTheFontAndSizeCodesOfARealFile(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/microdvd/real/sub_microdvd_with_styles.sub", Format::MicroDvd,
            new ReadOptions(formats: [new MicroDvdReadOptions(frameRate: 23.976)]));

        $this->assertSame(
            "{3294}{3366}Passengers, the train is now|arriving at the central station.\n{89682}{89730}Thank you, conductor.\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(stripTags: true))
        );
    }


    public function testChangedStyleWritesNewCodesAndKeepsOtherCodes(): void
    {
        $subtitle = Subtitle::fromString("{1}{1}25\n{25}{50}{Y:i}{f:Arial}One|Two", Format::MicroDvd);
        $subtitle->getCues()[0]->setLines(["<b>One</b>", "<i>Two</i>"]);

        $this->assertSame(
            "{1}{1}25\n{25}{50}{y:b}{f:Arial}One|{y:i}Two\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]))
        );
    }


    public function testStylesThatSpanLinesStyleEachLine(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/srt/real/own_styled.srt", Format::SubRip);
        $output   = $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]));
        $reparsed = (new MicroDvdParser())->parse($output, new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertStringContainsString("{12000}{13500}{y:i}Italics over two lines|{y:i}that close on the second line\n", $output);
        $this->assertStringContainsString("{13500}{15000}{y:i}Italics that never close\n", $output);
        $this->assertSame(["<i>Italics over two lines</i>", "<i>that close on the second line</i>"], $reparsed->getCues()[6]->getLines());
    }


    public function testAColorAndAStyleThatSpanLinesStyleEachLine(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["<font color=\"#ff0000\"><u>Red", "still red</u></font>", "plain"]));

        $this->assertSame(
            "{25}{50}{c:\$0000FF}{y:u}Red|{c:\$0000FF}{y:u}still red|plain\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]))
        );
    }


    public function testASpeakerTagDoesNotHideTheStyleOfItsLines(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["<v Ben><i>The harbour is closed</i>", "<i>until Monday.</i>"]));

        $this->assertSame(
            "{25}{50}{y:i}The harbour is closed|{y:i}until Monday.\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]))
        );
    }


    public function testSubRipConvertsToMicroDvd(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);
        $output   = $subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]));
        $reparsed = (new MicroDvdParser())->parse($output, new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame(count($subtitle->getCues()), count($reparsed->getCues()));
        foreach ($subtitle->getCues() as $index => $cue) {
            $this->assertEqualsWithDelta($cue->getStart(), $reparsed->getCues()[$index]->getStart(), 0.02);
            $this->assertEqualsWithDelta($cue->getEnd(), $reparsed->getCues()[$index]->getEnd(), 0.02);
        }
    }
}
