<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;

class TtmlRealFileTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/ttml/real/";


    private static function realFilesDir(): string
    {
        return "ttml/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Ttml;
    }


    public static function realFileProvider(): array
    {
        return [
            "astisub_breaklines"         => ["astisub_breaklines.ttml", 4, 0.0, 1.0, "First line\nSecond line", Alignment::BottomCenter, 3.0, 4.0, "Seventh line\nEighth middle line", Alignment::BottomCenter],
            "astisub_merging_style"      => ["astisub_merging_style.ttml", 4, 0.0, 60.0, "<font color=\"#ffff00\">text1.0 </font>text1.1", Alignment::TopCenter, 120.0, 180.0, "text3", Alignment::TopCenter],
            "astisub_smpte"              => ["astisub_smpte.ttml", 6, 99.0, 101.04, "(light rain)", Alignment::TopCenter, 151.4, 153.44, "<font color=\"#ffff00\"><i>(music for the</i></font>\ntraffic news)", Alignment::BottomCenter],
            "bbc_ebu_tt_d"               => ["bbc_ebu_tt_d.ttml", 20, 10.0, 13.0, "<v Anna>Good morning from the harbour.", Alignment::BottomCenter, 76.12, 79.52, "<i>(music)</i>", Alignment::TopCenter],
            "blank_lines_before_xml"     => ["blank_lines_before_xml.ttml", 2, 1.0, 3.0, "The market opens at eight.", Alignment::BottomCenter, 3.5, 6.0, "<i>Fresh bread</i> is sold first.", Alignment::BottomCenter],
            "flash_ttaf1_2006_04"        => ["flash_ttaf1_2006_04.xml", 4, 0.5, 3.0, "Welcome to the second lesson.", Alignment::BottomCenter, 8.4, 11.0, "<b>Save</b> your work before you go on.", Alignment::BottomCenter],
            "mantas_dfxp_br"             => ["mantas_dfxp_br.dfxp", 1, 0.0, 1.0, "one\ntwo\nthree", Alignment::BottomCenter, 0.0, 1.0, "one\ntwo\nthree", Alignment::BottomCenter],
            "mantas_duplicated_ids"      => ["mantas_duplicated_ids.ttml", 3, 0.0, 1.0, "First line.", Alignment::BottomCenter, 2.0, 3.0, "Third line.", Alignment::BottomCenter],
            "mantas_fps_multiplier"      => ["mantas_fps_multiplier.ttml", 1, 15.015, 17.684, "First line.", Alignment::BottomCenter, 15.015, 17.684, "First line.", Alignment::BottomCenter],
            "mantas_netflix_ticks"       => ["mantas_netflix_ticks.dfxp", 2, 137.4, 140.4, "The bakery's first bread\nis ready at six o'clock.", Alignment::BottomCenter, 3740.5, 3742.5, "The last train leaves at midnight.", Alignment::BottomCenter],
            "mantas_ttml2"               => ["mantas_ttml2.ttml", 5, 0.0, 2.0, "Hello I am your first line.", Alignment::BottomCenter, 8.0, 10.0, "<font color=\"#ff0000\">I am the last caption displayed in red and centered.</font>", Alignment::TopCenter],
            "pysubs2_regions"            => ["pysubs2_regions.ttml", 10, 1.375, 5.75, "TOP SAMPLE TEXT", Alignment::TopCenter, 45.325, 50.041, "for the weekend market.", Alignment::BottomCenter],
            "w3c_dfxp_timing"            => ["w3c_dfxp_timing.dfxp", 4, 0.0, 2.0, "<font color=\"#ff0000\"><b>Text 1</b></font>", Alignment::TopCenter, 1.0, 3.0, "<font color=\"#ff0000\"><b>Text 4</b></font>", Alignment::TopCenter],
            "w3c_imsc11_frames"          => ["w3c_imsc11_frames.ttml", 3, 1.01, 3.0, "This should appear on frame 25.", Alignment::BottomCenter, 7.33, 9.0, "This should appear on frame 176.", Alignment::BottomCenter],
            "w3c_imsc11_line_gaps"       => ["w3c_imsc11_line_gaps.ttml", 1, 0.0, 30.0, "##Line gaps##\nThe quick brown fox\njumps over the <font color=\"#000000\">lazy </font>dog\n##Line gaps##", Alignment::BottomCenter, 0.0, 30.0, "##Line gaps##\nThe quick brown fox\njumps over the <font color=\"#000000\">lazy </font>dog\n##Line gaps##", Alignment::BottomCenter],
            "w3c_imsc11_paragraphs"      => ["w3c_imsc11_paragraphs.ttml", 4, 0.0, 30.0, "Paragraph 1", Alignment::BottomCenter, 0.0, 30.0, "Paragraph 2", Alignment::TopCenter],
            "w3c_imsc11_regions"         => ["w3c_imsc11_regions.ttml", 3, 0.0, 6.0, "This region is within the editorial area.", Alignment::TopCenter, 0.0, 6.0, "<font color=\"#ffff00\">This region is not.</font>", Alignment::BottomCenter],
            "w3c_ttml1_cells"            => ["w3c_ttml1_cells.ttml", 5, 0.0, 8.0, "Lorem ipsum dolor sit", Alignment::BottomCenter, 18.0, 29.0, "Ut enim ad minim veniam quis, nostrud", Alignment::BottomCenter],
            "w3c_ttml1_timed_spans"      => ["w3c_ttml1_timed_spans.ttml", 5, 0.0, 25.0, "Lorem <00:00:01.000>ipsum <00:00:02.000>dolor <00:00:03.000>sit", Alignment::BottomCenter, 0.0, 25.0, "<00:00:18.000>Ut <00:00:19.000>enim <00:00:20.000>ad <00:00:21.000>minim <00:00:22.000>veniam <00:00:23.000>quis, <00:00:24.000>nostrud", Alignment::BottomCenter],
            "w3c_ttml1_timing"           => ["w3c_ttml1_timing.ttml", 4, 0.0, 2.0, "<font color=\"#ff0000\"><b>Text 1</b></font>", Alignment::TopCenter, 1.0, 3.0, "<font color=\"#ff0000\"><b>Text 4</b></font>", Alignment::TopCenter],
            "style_inheritance"          => ["style_inheritance.ttml", 5, 1.0, 2.0, "<i>The ferry leaves at noon.</i>", Alignment::BottomCenter, 9.0, 10.0, "<font color=\"#00ffff\"><i>Next stop </i></font>harbour", Alignment::BottomCenter],
            "word_timed_spans"           => ["word_timed_spans.ttml", 3, 1.0, 4.5, "The <00:00:02.000>tide <00:00:03.000>turns", Alignment::BottomCenter, 10.0, 13.0, "Boats <00:00:11.000>return <00:00:12.000>home", Alignment::BottomCenter],
            "smpte_drop_ntsc"            => ["smpte_drop_ntsc.ttml", 5, 57.391, 60.027, "The morning train leaves platform two.", Alignment::BottomCenter, 3599.996, 3602.999, "The evening train runs on time.", Alignment::BottomCenter],
            "own_netflix_dfxp"           => ["own_netflix_dfxp.dfxp", 5, 2.002, 4.505, "The winter timetable\nstarts on Monday.", Alignment::BottomCenter, 3600.0, 3602.503, "- Is the last train still at 11?\n- Yes, at 11:15.", Alignment::BottomCenter],
            "own_youtube_ttml"           => ["own_youtube_ttml.ttml", 5, 0.24, 3.12, "welcome back to the station channel", Alignment::BottomCenter, 12.0, 15.36, "and the bus waits ten minutes &amp; then leaves", Alignment::BottomCenter],
            "own_ttconv_imsc11"          => ["own_ttconv_imsc11.ttml", 10, 17.985, 20.521, "<font color=\"#00ff00\"><b>[train horn]</b></font>", Alignment::BottomCenter, 720.0, 780.0, "<i>x</i>^3 * <i>x</i> = 100", Alignment::BottomCenter],
            "ttconv_referential_styling" => ["ttconv_referential_styling.ttml", 2, 0.0, 2.0, "<font color=\"#008000\">one</font>", Alignment::BottomCenter, 2.0, 4.0, "<font color=\"#000000\">two</font>", Alignment::BottomCenter],
            "ttconv_lwsp_default"        => ["ttconv_lwsp_default.ttml", 2, 0.0, 2.0, "hello my name is Mathilda", Alignment::BottomCenter, 2.0, 4.0, "bonjour mon nom\nest", Alignment::BottomCenter],
            "ttconv_lwsp_preserve"       => ["ttconv_lwsp_preserve.ttml", 1, 0.0, 2.0, "hello my\nname is Mathilda", Alignment::BottomCenter, 0.0, 2.0, "hello my\nname is Mathilda", Alignment::BottomCenter],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        int $cueCount,
        float $firstStart,
        float $firstEnd,
        string $firstText,
        Alignment $firstAlignment,
        float $lastStart,
        float $lastEnd,
        string $lastText,
        Alignment $lastAlignment
    ): void {
        $cues = array_values($this->parseFile($file)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText, $firstAlignment], $this->describeCue($cues[0]));
        $this->assertSame([$lastStart, $lastEnd, $lastText, $lastAlignment], $this->describeCue(end($cues)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsCues(string $file): void
    {
        $subtitle = $this->parseFile($file);
        $output   = $subtitle->toString(Format::Ttml);
        $reparsed = Subtitle::fromString($output, Format::Ttml);

        // The formatter strips word timestamps.
        $this->assertSame(
            array_map(
                fn (SubtitleCue $cue): array => [...$this->describeCue($cue), 2 => preg_replace(Markup::WORD_TIMESTAMP_REGEX, "", $cue->getText())],
                $subtitle->getCues()
            ),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
        $this->assertSame($output, $reparsed->toString(Format::Ttml));
    }


    public function testRealFileSmpteDropFrameTimes(): void
    {
        $subtitle = $this->parseFile("smpte_drop_ntsc.ttml");

        $this->assertSame(
            [[57.391, 60.027], [60.06, 63.497], [597.997, 599.999], [599.999, 602.669], [3599.996, 3602.999]],
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues())
        );
    }


    public function testRealFileEbuTtDAgentsTitleAndNestedSpans(): void
    {
        $subtitle = $this->parseFile("bbc_ebu_tt_d.ttml");
        $cues     = array_values($subtitle->getCues());

        $this->assertSame("Harbour weather", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("en-GB", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame("sub3", $cues[2]->getIdentifier());
        $this->assertSame("<v Ben>The first ferry left\nat seven o'clock.", $cues[2]->getText());
        $this->assertSame("<v Ben>Tide tables are <b>on the <u>board</u></b>", $cues[10]->getText());
        $this->assertStringNotContainsString("ttm:title", $subtitle->findFormatData("ttml")["head"]);
        $this->assertStringContainsString("ebuttm:documentMetadata", $subtitle->findFormatData("ttml")["head"]);
    }


    public function testRealFileInheritsStylesFromRegionBodyAndDiv(): void
    {
        $this->assertSame(
            [
                "<i>The ferry leaves at noon.</i>",
                "Tickets are sold on board.",
                "<b><u>Mind the gap.</u></b>",
                "<font color=\"#ffff00\">Platform </font>two",
                "<font color=\"#00ffff\"><i>Next stop </i></font>harbour",
            ],
            array_map(fn (SubtitleCue $cue): string => $cue->getText(), $this->parseFile("style_inheritance.ttml")->getCues())
        );
    }


    public function testRealFileOutputUndoesInheritedStyles(): void
    {
        $output = $this->parseFile("style_inheritance.ttml")->toString(Format::Ttml);

        $this->assertStringContainsString(
            " tts:color=\"yellow\"><span tts:color=\"white\"><span tts:color=\"#ffff00\">Platform </span>two</span></p>",
            $output
        );
        $this->assertStringContainsString(
            "<span tts:fontStyle=\"normal\" tts:color=\"white\"><span tts:color=\"#00ffff\"><span tts:fontStyle=\"italic\">Next stop </span></span>harbour</span>",
            $output
        );
    }


    public function testRealFileTakesTheCueTimesFromTimedSpansInStrictAndLenientMode(): void
    {
        foreach ([false, true] as $lenient) {
            $subtitle = $this->parseFile("word_timed_spans.ttml", new ReadOptions(lenient: $lenient));

            $this->assertSame(
                [
                    [1.0, 4.5, "The <00:00:02.000>tide <00:00:03.000>turns", Alignment::BottomCenter],
                    [5.5, 8.0, "at <00:00:06.500><i>dusk</i>", Alignment::BottomCenter],
                    [10.0, 13.0, "Boats <00:00:11.000>return <00:00:12.000>home", Alignment::BottomCenter],
                ],
                array_map($this->describeCue(...), $subtitle->getCues())
            );
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    public function testRealFileTicksAndPreservedSpace(): void
    {
        $subtitle = $this->parseFile("mantas_netflix_ticks.dfxp");

        $this->assertSame(["region" => "bottomCenter"], $subtitle->getCues()[0]->findFormatData("ttml")["attributes"]);
        $this->assertSame(["xml:space" => "preserve"], $subtitle->getCues()[0]->findFormatData("ttml")["div"]);
    }


    public function testRealFileDfxpNamespaceIsWrittenBack(): void
    {
        $subtitle = $this->parseFile("w3c_dfxp_timing.dfxp");
        $output   = $subtitle->toString(Format::Ttml);

        $this->assertStringContainsString("<tt xmlns=\"http://www.w3.org/2006/10/ttaf1\"", $output);
        $this->assertStringContainsString("<body tts:extent=\"640px 480px\" xml:id=\"b1\">", $output);
    }


    public function testRealFile2006NamespaceResolvesStylesAndIsWrittenBack(): void
    {
        $subtitle = $this->parseFile("flash_ttaf1_2006_04.xml");
        $output   = $subtitle->toString(Format::Ttml);

        $this->assertSame("<i>Open</i> the tool panel on the left.", $subtitle->getCues()[1]->getText());
        $this->assertSame("<font color=\"#ffff00\">Pick the brush</font>\n<font color=\"#ffff00\">and draw a line.</font>", $subtitle->getCues()[2]->getText());
        $this->assertStringContainsString("<tt xmlns=\"http://www.w3.org/2006/04/ttaf1\"", $output);
        $this->assertStringContainsString("xmlns:tts=\"http://www.w3.org/2006/04/ttaf1#styling\"", $output);
    }


    public function testRealFileWithoutNamespaceWritesNoInvalidIdentifier(): void
    {
        $subtitle = $this->parseFile("astisub_breaklines.ttml");
        $output   = $subtitle->toString(Format::Ttml);

        $this->assertSame("1", $subtitle->getCues()[0]->getIdentifier());
        $this->assertStringContainsString("<p begin=\"00:00:00.000\" end=\"00:00:01.000\">First line<br/>Second line</p>", $output);
    }
}
