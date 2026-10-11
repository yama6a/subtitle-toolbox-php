<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;

class FontColorTest extends TestCase
{
    private static function writers(): array
    {
        return [
            "ass"      => [Format::Ass, new WriteOptions(), new ReadOptions()],
            "ebu stl"  => [Format::EbuStl, new WriteOptions(), new ReadOptions()],
            "itt"      => [Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]), new ReadOptions()],
            "microdvd" => [
                Format::MicroDvd,
                new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]),
                new ReadOptions(formats: [new MicroDvdReadOptions(25)]),
            ],
            "scc"      => [Format::Scc, new WriteOptions(), new ReadOptions()],
            "ttml"     => [Format::Ttml, new WriteOptions(), new ReadOptions()],
        ];
    }


    public static function formatsAndTags(): array
    {
        $tags = [
            "single quotes"       => "<font color='#FF0000'>Red</font>",
            "no quotes"           => "<font color=#ff0000>Red</font>",
            "upper case"          => "<FONT COLOR=\"#FF0000\">Red</FONT>",
            "spaces around equal" => "<font color = \"#ff0000\" >Red</font>",
            "other attributes"    => "<font face=\"Arial\" color=\"#ff0000\">Red</font>",
        ];

        $cases = [];
        foreach (self::writers() as $formatName => $format) {
            foreach ($tags as $tagName => $tag) {
                $cases["$formatName, $tagName"] = [...$format, $tag];
            }
        }

        return $cases;
    }


    #[DataProvider("formatsAndTags")]
    public function testEveryFontColorSyntaxKeepsTheColor(Format $format, WriteOptions $write, ReadOptions $read, string $text): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, $text))->toString($format, $write);

        $this->assertSame("<font color=\"#ff0000\">Red</font>", Subtitle::fromString($output, $format, $read)->getCues()[0]->getText());
    }


    public static function writersAndColorSpellings(): array
    {
        $formats = [
            "webvtt" => [Format::WebVtt, new WriteOptions(), new ReadOptions(), "<c.yellow>Hi</c>"],
            ...array_map(fn (array $format): array => [...$format, "<font color=\"#ffff00\">Hi</font>"], self::writers()),
        ];

        $cases = [];
        foreach ($formats as $formatName => $format) {
            foreach (["#ffff00", "#FF0", "yellow", "Yellow", "rgb(255, 255, 0)", "&#35;ff0"] as $color) {
                $cases["$formatName, $color"] = [...$format, $color];
            }
        }

        return $cases;
    }


    #[DataProvider("writersAndColorSpellings")]
    public function testEveryWriterReadsAColorWithTheSameRule(Format $format, WriteOptions $write, ReadOptions $read, string $expected, string $color): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<font color=\"$color\">Hi</font>"))->toString($format, $write);

        $this->assertSame($expected, Subtitle::fromString($output, $format, $read)->getCues()[0]->getText());
    }


    public function testTtmlWritesEveryValidTtmlColorAsItIs(): void
    {
        $text = "<font color=\"rgb(255, 0, 0)\">a</font> <font color=\"rgba(0,0,255,128)\">b</font> <font color=\"#FFFF0080\">c</font>"
                . " <font color=\"transparent\">d</font> <font color=\"teal\">e</font>";

        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, $text))->toString(Format::Ttml);

        $this->assertStringContainsString("<span tts:color=\"rgb(255, 0, 0)\">a</span> <span tts:color=\"rgba(0,0,255,128)\">b</span>"
                                          . " <span tts:color=\"#FFFF0080\">c</span> <span tts:color=\"transparent\">d</span>"
                                          . " <span tts:color=\"teal\">e</span>", $output);
    }


    public function testTtmlInputWithRgbAndRgbaColorsKeepsItsColors(): void
    {
        $ttml = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:tts=\"http://www.w3.org/ns/ttml#styling\"><body><div>"
                . "<p begin=\"00:00:01.000\" end=\"00:00:02.000\"><span tts:color=\"rgb(255,0,0)\">Red</span> and <span tts:color=\"rgba(0,0,255,128)\">blue</span></p>"
                . "<p begin=\"00:00:02.000\" end=\"00:00:03.000\" tts:color=\"rgba(0, 255, 0, 255)\">Green</p>"
                . "</div></body></tt>";

        $output = Subtitle::fromString($ttml, Format::Ttml)->toString(Format::Ttml);

        $this->assertStringContainsString("<span tts:color=\"#ff0000\">Red</span> and <span tts:color=\"#0000ff\">blue</span>", $output);
        $this->assertStringContainsString("tts:color=\"rgba(0, 255, 0, 255)\"><span tts:color=\"#00ff00\">Green</span>", $output);
    }


    public function testTtmlNormalizesAColorThatIsNotValidTtmlAndKeepsAnUnknownValue(): void
    {
        $text = "<font color=\"#FF0\">a</font> <font color=\"Yellow\">b</font> <font color=\"RGB(0,0,255)\">c</font> <font color=\"grey\">d</font>"
                . " <font color=\"rgba(255, 0, 0, 0.5)\">e</font> <font color=\"Transparent\">f</font>";

        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, $text))->toString(Format::Ttml);

        $this->assertStringContainsString("<span tts:color=\"#ffff00\">a</span> <span tts:color=\"yellow\">b</span>"
                                          . " <span tts:color=\"#0000ff\">c</span> <span tts:color=\"grey\">d</span>"
                                          . " <span tts:color=\"#ff000080\">e</span> <span tts:color=\"transparent\">f</span>", $output);
    }


    public function testIttKeepsEveryValidTtmlColorAndTheAlphaChannel(): void
    {
        $text = "<font color=\"rgba(0,0,0,0)\">a</font> <font color=\"transparent\">b</font> <font color=\"#FF000080\">c</font>"
                . " <font color=\"rgba(255,0,0,0.5)\">d</font> <font color=\"#FFFF00\">e</font> <font color=\"grey\">f</font>";

        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, $text))->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString("<span tts:color=\"rgba(0,0,0,0)\">a</span> <span tts:color=\"transparent\">b</span>"
                                          . " <span tts:color=\"#ff000080\">c</span> <span tts:color=\"#ff000080\">d</span>"
                                          . " <span tts:color=\"#ffff00\">e</span> f</p>", $output);
    }
}
