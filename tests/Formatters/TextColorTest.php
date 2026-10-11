<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class TextColorTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";


    public static function webVttColorClassesInOtherFormats(): array
    {
        return [
            "srt"  => [Format::SubRip, "\n<font color=\"#ffff00\">Hi</font>\n"],
            "ass"  => [Format::Ass, ",,{\\c&H00FFFF&}Hi{\\c}\n"],
            "ttml" => [Format::Ttml, "><span tts:color=\"#ffff00\">Hi</span></p>"],
        ];
    }


    #[DataProvider("webVttColorClassesInOtherFormats")]
    public function testAWebVttColorClassBecomesTheTextColor(Format $format, string $expected): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<c.yellow>Hi</c>\n", Format::WebVtt);

        $this->assertStringContainsString($expected, $subtitle->toString($format));
    }


    public static function otherColorFormats(): array
    {
        return [
            "itt"      => [Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]), new ReadOptions()],
            "microdvd" => [
                Format::MicroDvd,
                new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]),
                new ReadOptions(formats: [new MicroDvdReadOptions(25)]),
            ],
            "sami"     => [Format::Sami, new WriteOptions(), new ReadOptions()],
            "ebu stl"  => [Format::EbuStl, new WriteOptions(), new ReadOptions()],
            "scc"      => [Format::Scc, new WriteOptions(), new ReadOptions()],
        ];
    }


    #[DataProvider("otherColorFormats")]
    public function testEveryFormatWithColorsWritesAWebVttColorClass(Format $format, WriteOptions $write, ReadOptions $read): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<c.red>Hi</c>\n", Format::WebVtt);

        $this->assertSame("<font color=\"#ff0000\">Hi</font>", Subtitle::fromString($subtitle->toString($format, $write), $format, $read)->getCues()[0]->getText());
    }


    public function testTheColorClassesOfAWebVttFileBecomeFontColorsInSubRip(): void
    {
        $subtitle = Subtitle::load(self::FILES . "vtt/real/own_color_classes.vtt", Format::WebVtt);

        $this->assertStringEqualsFile(self::FILES . "srt/real/own_color_classes_from_vtt.srt", $subtitle->toString(Format::SubRip));
    }


    public function testWebVttToWebVttKeepsEveryClass(): void
    {
        $subtitle = Subtitle::load(self::FILES . "vtt/real/own_color_classes.vtt", Format::WebVtt);

        $this->assertStringEqualsFile(self::FILES . "vtt/real/own_color_classes.vtt", $subtitle->toString(Format::WebVtt, new WriteOptions(bom: false)));
    }


    public function testABackgroundClassBecomesTheTtmlBackgroundColor(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<c.bg_black.yellow>Hi</c>\n", Format::WebVtt);

        $this->assertStringContainsString("<span tts:color=\"#ffff00\" tts:backgroundColor=\"#000000\">Hi</span>",
                                          $subtitle->toString(Format::Ttml));
    }


    public function testFontColorsWithAWebVttClassBecomeClassesAndOtherColorsDrop(): void
    {
        $subtitle = Subtitle::load(self::FILES . "srt/real/own_font_colors.srt", Format::SubRip);

        $report = (new WebVttFormatter())->formatWithReport($subtitle);

        $this->assertStringEqualsFile(self::FILES . "vtt/real/own_font_colors_from_srt.vtt", $report->content);
        $this->assertSame($report->content, $subtitle->toString(Format::WebVtt));
        $this->assertCount(1, $report->droppedColors);
        $this->assertSame(3, $report->droppedColors[0]->cueIndex);
        $this->assertSame("#123456", $report->droppedColors[0]->color);
        $this->assertSame("Cue #3 at 4 s: dropped the color \"#123456\", because WebVTT has classes for 8 colors only.",
                          $report->droppedColors[0]->message);
    }


    public function testStrippedTagsDropNoColorThatTheReportLists(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<font color=\"#123456\">Hi</font>"));

        $this->assertSame([], (new WebVttFormatter())->formatWithReport($subtitle, new WriteOptions(stripTags: true))->droppedColors);
    }


    public static function roundTripFormats(): array
    {
        return [
            "srt" => [Format::SubRip],
            "ass" => [Format::Ass],
        ];
    }


    #[DataProvider("roundTripFormats")]
    public function testTheEightColorClassesSurviveARoundTrip(Format $format): void
    {
        $vtt = "WEBVTT\n\n";
        foreach (array_keys(Markup::WEBVTT_COLORS) as $index => $class) {
            $vtt .= sprintf("00:00:%02d.000 --> 00:00:%02d.000\n<c.%s>Line %d</c>\n\n", $index + 1, $index + 2, $class, $index);
        }
        $original = Subtitle::fromString($vtt, Format::WebVtt);

        $back = Subtitle::fromString($original->toString($format), $format)->toString(Format::WebVtt, new WriteOptions(bom: false));

        $this->assertSame(array_map(fn (SubtitleCue $cue): string => $cue->getText(), $original->getCues()),
                          array_map(fn (SubtitleCue $cue): string => $cue->getText(), Subtitle::fromString($back, Format::WebVtt)->getCues()));
    }
}
