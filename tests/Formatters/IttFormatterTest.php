<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class IttFormatterTest extends TestCase
{
    private const ROOT = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttm=\"http://www.w3.org/ns/ttml#metadata\""
                         . " xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\" xmlns:tts=\"http://www.w3.org/ns/ttml#styling\"";

    private const HEAD = "  <head>\n"
                         . "    <styling>\n"
                         . "      <style xml:id=\"normal\" tts:fontFamily=\"sansSerif\" tts:fontWeight=\"normal\" tts:fontStyle=\"normal\""
                         . " tts:color=\"white\" tts:fontSize=\"100%\"/>\n"
                         . "    </styling>\n"
                         . "    <layout>\n"
                         . "      <region xml:id=\"top\" tts:origin=\"0% 0%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\""
                         . " tts:displayAlign=\"before\"/>\n"
                         . "      <region xml:id=\"bottom\" tts:origin=\"0% 85%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\""
                         . " tts:displayAlign=\"after\"/>\n"
                         . "    </layout>\n"
                         . "  </head>\n";


    private function paragraphs(string $output): array
    {
        preg_match_all("/<p .*<\/p>/", $output, $matches);

        return $matches[0];
    }


    public function testSmpteTtmlIsWrittenWithSmpteTimes(): void
    {
        $ttml = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\""
                . " xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\""
                . " ttp:timeBase=\"smpte\" ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"999 1000\" ttp:dropMode=\"nonDrop\">"
                . "<body><div><p begin=\"00:00:01:12\" end=\"00:00:04:00\">Hello<br/><span tts:fontStyle=\"italic\">world</span></p></div></body></tt>";

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . self::ROOT . " xml:lang=\"en\""
            . " ttp:timeBase=\"smpte\" ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"999 1000\" ttp:dropMode=\"nonDrop\">\n"
            . self::HEAD
            . "  <body style=\"normal\">\n    <div>\n"
            . "      <p begin=\"00:00:01:12\" end=\"00:00:04:00\" region=\"bottom\">Hello<br/><span tts:fontStyle=\"italic\">world</span></p>\n"
            . "    </div>\n  </body>\n</tt>\n",
            Subtitle::fromString($ttml, Format::Itt)->toString(Format::Itt)
        );
    }


    public function testSingleQuotedColourBecomesAColourSpan(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<font color='#ff0000'>red</font>"))
                                  ->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertSame(["<p begin=\"00:00:01:00\" end=\"00:00:02:00\" region=\"bottom\"><span tts:color=\"#ff0000\">red</span></p>"],
                          $this->paragraphs($output));
    }


    public function testSubtitleFromAnotherFormatGetsRegionsAndSupportedStyles(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n{\\an8}<v Fred>Hi & <b>bold <i>both</i></b>\n"
               . "<font color=\"#FF000080\">red</font> <u>u</u> <s>s</s> <font color=\"rgba(0,0,0,0)\">x</font>\n\n"
               . "2\n00:00:03,000 --> 00:00:04,000\n{\\an4}<font color=\"Yellow\">left</font>\n\n"
               . "3\n00:00:05,000 --> 00:00:06,000\n{\\an9}top right\n";

        $output = Subtitle::fromString($srt, Format::SubRip)->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString(" ttp:timeBase=\"smpte\" ttp:frameRate=\"25\" ttp:frameRateMultiplier=\"1 1\" ttp:dropMode=\"nonDrop\">", $output);
        $this->assertSame(
            [
                "<p begin=\"00:00:01:00\" end=\"00:00:02:00\" region=\"top\">Hi &amp; <span tts:fontWeight=\"bold\">bold"
                . " <span tts:fontStyle=\"italic\">both</span></span><br/><span tts:color=\"#ff000080\">red</span>"
                . " <span tts:textDecoration=\"underline\">u</span> s <span tts:color=\"rgba(0,0,0,0)\">x</span></p>",
                "<p begin=\"00:00:03:00\" end=\"00:00:04:00\" region=\"bottom\"><span tts:color=\"yellow\">left</span></p>",
                "<p begin=\"00:00:05:00\" end=\"00:00:06:00\" region=\"top\">top right</p>",
            ],
            $this->paragraphs($output)
        );
        $this->assertStringNotContainsString("agent", $output);
    }


    public function testWritesOneDivAndOnlyTheAppleHead(): void
    {
        $ttml = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\">"
                . "<head><styling><style xml:id=\"s1\" tts:fontFamily=\"monospaceSerif\"/></styling></head>"
                . "<body><div xml:id=\"d1\"><p begin=\"1s\" end=\"2s\" style=\"s1\">one</p></div>"
                . "<div xml:id=\"d2\"><p xml:id=\"c2\" begin=\"3s\" end=\"4s\">two</p></div></body></tt>";

        $output = Subtitle::fromString($ttml, Format::Ttml)->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 30)]));

        $this->assertSame(1, substr_count($output, "<div"));
        $this->assertStringNotContainsString("monospaceSerif", $output);
        $this->assertSame(
            [
                "<p begin=\"00:00:01:00\" end=\"00:00:02:00\" region=\"bottom\">one</p>",
                "<p xml:id=\"c2\" begin=\"00:00:03:00\" end=\"00:00:04:00\" region=\"bottom\">two</p>",
            ],
            $this->paragraphs($output)
        );
    }


    public static function roundingProvider(): array
    {
        return [
            "nearest frame down"           => [25, 1.019, 2.0, "00:00:01:00", "00:00:02:00"],
            "nearest frame up"             => [25, 1.021, 2.0, "00:00:01:01", "00:00:02:00"],
            "next second"                  => [25, 1.99, 2.48, "00:00:02:00", "00:00:02:12"],
            "at least one frame"           => [25, 1.0, 1.01, "00:00:01:00", "00:00:01:01"],
            "end before start"             => [25, 1.0, 0.5, "00:00:01:00", "00:00:01:01"],
            "negative start"               => [25, -1.0, 0.04, "00:00:00:00", "00:00:00:01"],
            "23.976 fps, last frame"       => [23.976, 0.958, 1.0, "00:00:00:23", "00:00:01:00"],
            "29.97 fps, past one hour"     => [29.97, 3725.5, 3725.6, "01:02:01:23", "01:02:01:26"],
            "24000/1001 fps"               => [24000 / 1001, 0.5, 1.0, "00:00:00:12", "00:00:01:00"],
        ];
    }


    #[DataProvider("roundingProvider")]
    public function testRoundsToTheNearestFrame(float $fps, float $start, float $end, string $begin, string $expectedEnd): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue($start, $end, "a"));

        $this->assertSame(
            ["<p begin=\"$begin\" end=\"$expectedEnd\" region=\"bottom\">a</p>"],
            $this->paragraphs($subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: $fps)])))
        );
    }


    public static function hourRoundTripProvider(): array
    {
        return [
            "29.97 fps" => [29.97, ["00:59:56:12", "01:01:36:09", "01:59:52:24", "02:01:32:21"]],
            "25 fps"    => [25, ["01:00:00:00", "01:01:40:00", "02:00:00:00", "02:01:40:00"]],
        ];
    }


    #[DataProvider("hourRoundTripProvider")]
    public function testRoundTripAtOneAndTwoHours(float $fps, array $labels): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(3600, 3700, "a"))->addCue(new SubtitleCue(7200, 7300, "b"));
        $output   = $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: $fps)]));

        $this->assertSame(
            [
                "<p begin=\"$labels[0]\" end=\"$labels[1]\" region=\"bottom\">a</p>",
                "<p begin=\"$labels[2]\" end=\"$labels[3]\" region=\"bottom\">b</p>",
            ],
            $this->paragraphs($output)
        );
        $this->assertSame(
            [[3600.0, 3700.0], [7200.0, 7300.0]],
            array_map(
                fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()],
                Subtitle::fromString($output, Format::Itt)->getCues()
            )
        );
    }


    public static function frameRateProvider(): array
    {
        return [
            "23.976" => [23.976, "24", "999 1000"],
            "24"     => [24, "24", "1 1"],
            "25"     => [25, "25", "1 1"],
            "29.97"  => [29.97, "30", "999 1000"],
            "30"     => [30, "30", "1 1"],
        ];
    }


    #[DataProvider("frameRateProvider")]
    public function testWritesTheFrameRateParameters(float $fps, string $frameRate, string $multiplier): void
    {
        $output = (new Subtitle())->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: $fps)]));

        $this->assertStringContainsString(" ttp:frameRate=\"$frameRate\" ttp:frameRateMultiplier=\"$multiplier\" ", $output);
        $this->assertStringContainsString("<div/>", $output);
    }


    public function testOptionWinsOverTheFrameRateOfTheParsedFile(): void
    {
        $ttml     = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\""
                    . " ttp:timeBase=\"smpte\" ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"1000 1001\">"
                    . "<body><div><p begin=\"00:00:01:12\" end=\"00:00:02:00\">a</p></div></body></tt>";
        $subtitle = Subtitle::fromString($ttml, Format::Itt);
        $output   = $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString(" ttp:frameRate=\"25\" ttp:frameRateMultiplier=\"1 1\" ", $output);
        $this->assertSame(["<p begin=\"00:00:01:13\" end=\"00:00:02:00\" region=\"bottom\">a</p>"], $this->paragraphs($output));
    }


    public function testOptionWinsOverTheFrameRateOfARealFile(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/itt/real/fcp_23976_styles.itt"), Format::Itt);
        $output   = $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString(" ttp:frameRate=\"25\" ttp:frameRateMultiplier=\"1 1\" ", $output);
        $this->assertStringStartsWith("<p begin=\"00:00:01:13\" end=\"00:00:04:00\" ", $this->paragraphs($output)[0]);
        $this->assertSame(
            $subtitle->toString(Format::Itt),
            $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 23.976)]))
        );
    }


    public function testOptionWithTheParsedFrameRateKeepsTheParsedMultiplier(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/itt/real/avscript_testing.itt"), Format::Itt);
        $output   = $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 23.976)]));

        $this->assertStringContainsString(" ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"1000 1001\" ", $output);
        $this->assertSame($subtitle->toString(Format::Itt), $output);
    }


    public function testUnsupportedFrameRateOfTheParsedFileFallsBackToTheOption(): void
    {
        $ttml     = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\""
                    . " ttp:timeBase=\"smpte\" ttp:frameRate=\"50\"><body><div><p begin=\"00:00:01:20\" end=\"00:00:02:00\">a</p></div></body></tt>";
        $subtitle = Subtitle::fromString($ttml, Format::Itt);

        $this->assertStringContainsString(
            "<p begin=\"00:00:01:10\" end=\"00:00:02:00\" region=\"bottom\">a</p>",
            $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]))
        );
        $this->expectException(InvalidArgumentException::class);
        $subtitle->toString(Format::Itt);
    }


    public function testWithoutFrameRateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("iTT output needs the frame rate of the video. Set IttWriteOptions::\$frameRate.");

        (new Subtitle())->toString(Format::Itt);
    }


    public function testUnsupportedFrameRateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The ITT formatter accepts the frame rates 23.976, 24, 25, 29.97 and 30, got 50.");

        (new Subtitle())->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 50)]));
    }


    public function testStripAllXmlTagsOption(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<b>bold</b> &amp; <i>more</i>"));
        $output   = $subtitle->toString(
            Format::Itt,
            new WriteOptions(stripTags: true, formats: [new IttWriteOptions(frameRate: 25)])
        );

        $this->assertSame(["<p begin=\"00:00:01:00\" end=\"00:00:02:00\" region=\"bottom\">bold &amp; more</p>"], $this->paragraphs($output));
    }


    public function testTitleAndLanguageAreWritten(): void
    {
        $subtitle = (new Subtitle())->setMetadata(Subtitle::METADATA_TITLE, "Bakery")->setMetadata(Subtitle::METADATA_LANGUAGE, "fr");
        $output   = $subtitle->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString(" xml:lang=\"fr\" ", $output);
        $this->assertStringContainsString("<head>\n    <ttm:title>Bakery</ttm:title>\n    <styling>", $output);
    }


    public function testTtmlOutputKeepsMediaTimes(): void
    {
        $ttml = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\""
                . " ttp:timeBase=\"smpte\" ttp:frameRate=\"24\"><body><div><p begin=\"00:00:01:12\" end=\"00:00:02:00\">a</p></div></body></tt>";

        $output = Subtitle::fromString($ttml, Format::Itt)->toString(Format::Ttml);

        $this->assertStringContainsString("<p begin=\"00:00:01.500\" end=\"00:00:02.000\">a</p>", $output);
        $this->assertStringNotContainsString("timeBase", $output);
    }


    public function testLineEndingAndBomOptions(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "a\nb"));
        $output   = $subtitle->toString(Format::Itt, new WriteOptions(lineEnding: LineEnding::Crlf, bom: true, formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringStartsWith("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<tt ", $output);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", "", $output));
        $this->assertStringContainsString("<p begin=\"00:00:01:00\" end=\"00:00:02:00\" region=\"bottom\">a<br/>b</p>\r\n", $output);
    }
}
