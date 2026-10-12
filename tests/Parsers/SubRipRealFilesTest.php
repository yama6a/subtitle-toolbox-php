<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;

class SubRipRealFilesTest extends TestCase
{
    use RealFiles;


    private static function realFilesDir(): string
    {
        return "srt/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::SubRip;
    }


    public static function realFiles(): array
    {
        return [
            "swift-subtitle-kit BOM inside the file" => [
                "swift_subtitle_kit_embedded_bom.srt",
                3,
                [0.0, 1.5, "Synthetic fixture header"],
                [3.1, 4.5, "<i>Follow-up cue line.</i>"],
            ],
            "Own styled" => [
                "own_styled.srt",
                10,
                [17.985, 20.521, "<font color=\"#00ff00\"><b>[train horn]</b></font>"],
                [720.0, 780.0, "<i>x</i>^3 * <i>x</i> = 100"],
            ],
            "language-subtitles dots tester" => [
                "language_subtitles_dots_tester.srt",
                3,
                [2.501, 4.5, "Tester for dots instead of commas in timestamps 1.0 by ale5000\n<b><i>Use VLC 1.0.3 or higher as reference</i></b>"],
                [6.501, 8.5, "OK"],
            ],
            "language-subtitles SSA extensions" => [
                "language_subtitles_ssa_extensions.srt",
                19,
                [1.0, 4.0, "Top-left: an7"],
                [97.0, 105.0, "Back to default"],
            ],
            "Own alignment and coordinates" => [
                "own_alignment_and_coordinates.srt",
                10,
                [1.0, 3.0, "Plain first cue"],
                [19.5, 21.0, "Keep this: {\\some_unknown_tag} and {normal text}"],
            ],
            "Own CR CR LF line endings" => [
                "own_cr_cr_lf.srt",
                3,
                [1.0, 2.5, "Every line in this file\nends with CR CR LF"],
                [5.0, 6.5, "Last cue"],
            ],
            "Own timestamp without milliseconds" => [
                "own_timestamp_without_millis.srt",
                6,
                [99.0, 101.04, "(train brakes squeal)"],
                [151.4, 153.44, "(radio playing\nsoft piano music)"],
            ],
            "Own escaping" => [
                "own_escaping.srt",
                5,
                [1.0, 3.5, "I &lt;3 bread &amp; jam"],
                [12.5, 15.0, "<font color=\"#ffcc00\">Rain &amp; wind &gt;&gt; 40 km/h</font>"],
            ],
            "Own missing empty lines" => [
                "own_missing_empty_line.srt",
                4,
                [1.0, 2.5, "The bus to the airport is full."],
                [8.0, 10.0, "Mind the step."],
            ],
            "Own empty cues" => [
                "own_empty_cues.srt",
                5,
                [20.105, 23.292, "The ferry to the island leaves at noon."],
                [36.1, 39.0, "The last boat comes back at six."],
            ],
            "Own openai-whisper shape" => [
                "own_openai_whisper.srt",
                8,
                [0.0, 4.2, "Good evening and welcome to the timetable meeting."],
                [3603.24, 3609.0, "Thank you all for coming tonight."],
            ],
            "Own whisper.cpp diarize shape" => [
                "own_whisper_cpp_diarize.srt",
                6,
                [0.0, 4.12, "(speaker 0) Good morning, this is the station office."],
                [20.1, 25.88, "(speaker 0) That train now leaves at 6:50, ten minutes earlier."],
            ],
            "Own FFmpeg shape" => [
                "own_ffmpeg.srt",
                7,
                [0.5, 2.75, "The bakery opens at six."],
                [62.04, 65.96, "Thank you for shopping with us"],
            ],
            "Own WebVTT cue settings" => [
                "own_vtt_cue_settings.srt",
                4,
                [1.0, 3.5, "the tram to the old town"],
                [8.2, 10.0, "every ten minutes"],
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


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $fileName): void
    {
        $subtitle  = $this->parseFile($fileName);
        $formatted = $subtitle->toString(Format::SubRip);
        $reparsed  = Subtitle::fromString($formatted, Format::SubRip);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($formatted, $reparsed->toString(Format::SubRip));
    }


    public function testOwnFileKeepsCoordinatesAndAlignment(): void
    {
        $cues = $this->parseFile("own_alignment_and_coordinates.srt")->getCues();

        $this->assertSame(["coordinates" => ["x1" => 0, "x2" => 0, "y1" => 50, "y2" => 100]], $cues[1]->findFormatData("srt"));
        $this->assertSame(
            [2, 2, 8, 4, 7, 8, 9, 2, 2, 2],
            array_map(fn(SubtitleCue $cue) => $cue->getAlignment()->value, $cues)
        );
        $this->assertSame(["Middle left from the", "middle of a line, the second tag is ignored"], $cues[3]->getLines());
        $this->assertSame(["<i>Italic</i> and <b>bold</b>", "<s>struck</s> and <u>underlined</u>"], $cues[7]->getLines());
    }


    public function testOwnFileIsFormattedWithStandardTimestampsAndLeadingAlignment(): void
    {
        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/real/own_alignment_and_coordinates_formatted.srt"),
            $this->parseFile("own_alignment_and_coordinates.srt")->toString(Format::SubRip)
        );
    }


    public function testOwnEscapingFileSurvivesARoundTripByteForByte(): void
    {
        $raw = file_get_contents(__DIR__ . "/../files/srt/real/own_escaping.srt");

        $this->assertSame($raw, Subtitle::fromString($raw, Format::SubRip)->toString(Format::SubRip));
    }


    public function testAngleBracketTextThatIsNoTagStaysText(): void
    {
        $subtitle = $this->parseFile("own_angle_bracket_text.srt");

        $this->assertSame(
            [
                ["&lt;a sentence in angle brackets&gt;"],
                ["<i>Mind</i> the <font color=\"#ffcc00\">gap</font> <foo>here</foo>"],
                ["<span class=\"exit\">Exit</span> &lt;to the left&gt;"],
            ],
            array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues())
        );
        $subtitle->stripFormatting();
        $this->assertSame(
            ["&lt;a sentence in angle brackets&gt;", "Mind the gap here", "Exit &lt;to the left&gt;"],
            array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues())
        );
    }


    public function testAngleBracketTextThatIsNoTagIsWrittenAsText(): void
    {
        $subtitle = $this->parseFile("own_angle_bracket_text.srt");

        $this->assertStringEqualsFile(__DIR__ . "/../files/sbv/real/own_angle_bracket_text_from_srt.sbv", $subtitle->toString(Format::Sbv));
        $this->assertStringEqualsFile(__DIR__ . "/../files/vtt/real/own_angle_bracket_text_from_srt.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testTimestampWithoutMillisecondsIsWrittenWithMilliseconds(): void
    {
        $formatted = $this->parseFile("own_timestamp_without_millis.srt")->toString(Format::SubRip);

        $this->assertStringStartsWith("\u{feff}1\n00:01:39,000 --> 00:01:41,040\n(train brakes squeal)\n", $formatted);
    }


    public function testStrictModeStartsANewCueAtEveryTimingLine(): void
    {
        $expected = [
            [1.0, 2.5, ["The bus to the airport is full."]],
            [3.0, 5.0, ["The next one leaves at four."]],
            [5.5, 7.0, ["Tickets are on sale inside."]],
            [8.0, 10.0, ["Mind the step."]],
        ];
        $describe = fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()];
        $stream   = fopen(__DIR__ . "/../files/srt/real/own_missing_empty_line.srt", "r");

        $this->assertSame($expected, array_map($describe, $this->parseFile("own_missing_empty_line.srt")->getCues()));
        $this->assertSame($expected, array_map($describe, iterator_to_array((new SubRipStreamReader())->read($stream), false)));
    }


    public function testCueWithoutTextIsKeptWithoutLines(): void
    {
        $cues = $this->parseFile("own_empty_cues.srt")->getCues();

        $this->assertSame([[23.292, 28.898], [32.0, 36.1]], [[$cues[1]->getStart(), $cues[1]->getEnd()], [$cues[3]->getStart(), $cues[3]->getEnd()]]);
        $this->assertSame([[], []], [$cues[1]->getLines(), $cues[3]->getLines()]);
        $this->assertStringContainsString(
            "\n\n2\n00:00:23,292 --> 00:00:28,898\n\n3\n",
            $this->parseFile("own_empty_cues.srt")->toString(Format::SubRip)
        );
    }


    public function testWebVttCueSettingsAfterTheEndTimeSurviveAConversionToWebVtt(): void
    {
        $subtitle = $this->parseFile("own_vtt_cue_settings.srt");
        $stream   = fopen(__DIR__ . "/../files/srt/real/own_vtt_cue_settings.srt", "r");

        $this->assertSame([], $subtitle->getParseWarnings());
        $this->assertSame(["align" => "start", "position" => "0%"], $subtitle->getCues()[0]->findFormatData(Format::WebVtt->value));
        $this->assertSame(["align" => "start"], $subtitle->getCues()[2]->findFormatData(Format::WebVtt->value));
        $this->assertSame(file_get_contents(__DIR__ . "/../files/vtt/real/own_srt_cue_settings_converted.vtt"), $subtitle->toString(Format::WebVtt));
        $this->assertEquals($subtitle->getCues(), iterator_to_array((new SubRipStreamReader())->read($stream), false));
    }


    public function testSsaExtensionsSetTheAlignment(): void
    {
        $cues = $this->parseFile("language_subtitles_ssa_extensions.srt")->getCues();

        $this->assertSame(
            [7, 8, 9, 4, 5, 6, 1, 2, 3, 1, 2, 3, 7, 8, 9, 4, 5, 6, 2],
            array_map(fn(SubtitleCue $cue) => $cue->getAlignment()->value, $cues)
        );
        $this->assertSame(["[Deprecated] Middle-centre: \\a10"], $cues[16]->getLines());
    }


    private function describeCue(SubtitleCue $cue): array
    {
        // The formatter strips tags outside the SubRip set, for example <foo></foo>.
        $lines = array_values(array_filter(
            explode("\n", Markup::keepTags($cue->getText(), ["b", "u", "i", "s", "font"])),
            fn(string $line) => trim($line) !== ""
        ));

        return [$cue->getStart(), $cue->getEnd(), $lines, $cue->getAlignment(), $cue->findFormatData("srt")];
    }
}
