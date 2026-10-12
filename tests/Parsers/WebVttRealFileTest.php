<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttRealFileTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/vtt/real/";


    public static function realFileProvider(): array
    {
        return [
            "astisub_carriage_return" => ["astisub_carriage_return.vtt", 3, 0.0, 3.766, "The bakery on the corner opens\nat six o'clock every morning.", 10.733, 14.066, "and sells the last one\nbefore the noon train."],
            "astisub_html_entities"   => ["astisub_html_entities.vtt", 3, 0.331, 3.75, "The morning train left the station on time, \u{00A0}\n&amp; the rain stopped at noon.", 6.331, 9.675, "the temperature is &lt; ten degrees today."],
            "mantas_styles"           => ["mantas_styles.vtt", 1, 0.0, 10.0, "Hello world.", 0.0, 10.0, "Hello world."],
            "w3c_chapters"            => ["w3c_chapters.vtt", 4, 0.0, 10.7, "Title Slide", 110.1, 213.0, "Requirements of a Video text format"],
            "w3c_comments"            => ["w3c_comments.vtt", 2, 1.0, 4.0, "Never drink liquid nitrogen.", 5.0, 9.0, "— It will perforate your stomach.\n— You could die."],
            "w3c_cue_settings"        => ["w3c_cue_settings.vtt", 3, 0.0, 4.0, "Where did he go?", 4.0, 6.5, "What are you waiting for?"],
            "w3c_identifiers"         => ["w3c_identifiers.vtt", 3, 0.0, 2.0, "This is a test.", 4.0, 5.0, "Transcrit par Célestes™"],
            "w3c_regions"             => ["w3c_regions.vtt", 6, 0.0, 20.0, "<v Fred>Hi, my name is Fred", 12.5, 32.5, "<v Fred>OK, let's go."],
            "w3c_styles"              => ["w3c_styles.vtt", 1, 0.0, 10.0, "Hello <b>world</b>.", 0.0, 10.0, "Hello <b>world</b>."],
            "w3c_timestamps"          => ["w3c_timestamps.vtt", 3, 0.0, 8.0, "<c>No match (no timestamps)</c>", 16.0, 24.0, "<00:00:16.000> <c>This</c>\n<00:00:18.000> <c>can</c>\n<00:00:20.000> <c>match</c>\n<00:00:22.000> <c>:past/:future</c>\n<00:00:24.000>"],
            "w3c_voices"              => ["w3c_voices.vtt", 13, 11.0, 13.0, "<v Anna Berg>We are at the train station", 35.5, 38.0, "<v Anna Berg>You know the rain is so heavy my umbrella is leaking here."],
            "webvttpy_comments"       => ["webvttpy_comments.vtt", 3, 135.0, 140.0, "- Det regnar i dag.\n- Det är kallt ute.", 145.0, 150.0, "- Ta ett paraply"],
            "webvttpy_netflix"        => ["webvttpy_netflix.vtt", 30, 7.96, 9.48, "[Rosa] <i>En 1928,</i>", 107.76, 108.8, "Rápido."],
            "own_empty_cues"          => ["own_empty_cues.vtt", 5, 20.105, 23.292, "The ferry to the island leaves at noon.", 36.1, 39.0, "The last boat comes back at six."],
            "own_hour_digits"         => ["own_hour_digits.vtt", 7, 0.8, 2.933, "The first train leaves at six.", 3600022.86, 3600025.56, "The station closes for the night."],
            "own_settings_no_space"   => ["own_settings_without_space.vtt", 2, 0.0, 1.0, "The gate opens at eight.", 2.0, 3.5, "Boarding starts at half past."],
            "own_ytdlp_auto_captions" => ["own_ytdlp_auto_captions.vtt", 2, 0.0, 2.31, "the<00:00:00.480><c> ferry</c><00:00:00.960><c> leaves</c>", 2.31, 5.0, "at<00:00:02.800><c> noon</c>"],
            "own_ytdlp_rolling"       => ["own_ytdlp_rolling.vtt", 4, 0.16, 2.389, "welcome<00:00:00.400><c> back</c><00:00:00.800><c> to</c><00:00:01.120><c> the</c><00:00:01.440><c> garden</c>", 6.15, 8.43, "dig<00:00:06.640><c> a</c><00:00:06.800><c> small</c><00:00:07.200><c> hole</c>"],
            "own_openai_whisper"      => ["own_openai_whisper.vtt", 6, 0.0, 3.6, "This is the second part of the timetable meeting.", 3601.5, 3606.88, "Yes, the timetable starts on the first of December."],
            "own_whisper_highlight"   => ["own_openai_whisper_highlight_words.vtt", 11, 0.0, 0.32, "<u>The</u> next train leaves at nine.", 3.8, 4.4, "Platform four is <u>closed.</u>"],
            "own_ffmpeg"              => ["own_ffmpeg.vtt", 7, 0.5, 2.75, "The bakery opens at six.", 62.04, 65.96, "Thank you for shopping with us"],
            "webvttpy_youtube"        => ["webvttpy_youtube.vtt", 4, 286.07, 286.47, "okay", 305.069, 305.4, "the train<c.colorE5E5E5> leaves</c><c.colorCCCCCC> at ten today\n</c>"],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        int $cueCount,
        float $firstStart,
        float $firstEnd,
        string $firstText,
        float $lastStart,
        float $lastEnd,
        string $lastText
    ): void {
        $cues = array_values(Subtitle::fromString(file_get_contents(self::DIR . $file), Format::WebVtt)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = end($cues);
        $this->assertSame([$lastStart, $lastEnd, $lastText], [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsAllData(string $file): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::WebVtt);
        $output   = $subtitle->toString(Format::WebVtt);
        $reparsed = Subtitle::fromString($output, Format::WebVtt);

        $this->assertEquals($this->describe($subtitle), $this->describe($reparsed));
        $this->assertSame($output, $reparsed->toString(Format::WebVtt));
    }


    public function testRealFileRegionsAndCueSettings(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "w3c_regions.vtt"), Format::WebVtt);
        $cue      = $subtitle->getCues()[1];

        $this->assertSame(["fred", "bill"], array_column($subtitle->findFormatData("vtt")["regions"], "id"));
        $this->assertSame(["region" => "bill", "align" => "right", "alignment" => 3], $cue->findFormatData("vtt"));
        $this->assertSame(Alignment::BottomRight, $cue->getAlignment());
    }


    public function testCueWithoutTextIsKeptWithoutLines(): void
    {
        $cues = Subtitle::fromString(file_get_contents(self::DIR . "own_empty_cues.vtt"), Format::WebVtt)->getCues();

        $this->assertSame(["2", 23.292, 28.898, []], [$cues[1]->getIdentifier(), $cues[1]->getStart(), $cues[1]->getEnd(), $cues[1]->getLines()]);
        $this->assertSame([32.0, 36.1, [], ["align" => "middle", "line" => "90%"]],
                          [$cues[3]->getStart(), $cues[3]->getEnd(), $cues[3]->getLines(), $cues[3]->findFormatData("vtt")]);
    }


    public function testHoursWithAnyNumberOfDigitsParseAndAreWrittenWithoutLeadingZeros(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "own_hour_digits.vtt"), Format::WebVtt);

        $this->assertSame(
            [0.8, 36010.94, 36030.94, 36040.94, 360010.75, 360030.75, 3600022.86],
            array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $subtitle->getCues())
        );
        $this->assertStringContainsString("\n10:00:30.940 --> 10:00:40.750\n", $subtitle->toString(Format::WebVtt));
        $this->assertStringContainsString("\n00:00:00.800 --> 00:00:02.933\n", $subtitle->toString(Format::WebVtt));
    }


    public function testCueSettingsDirectlyAfterTheEndTimeAreRead(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "own_settings_without_space.vtt"), Format::WebVtt);

        $this->assertSame(["line" => "40%", "size" => "40%"], $subtitle->getCues()[0]->findFormatData("vtt"));
        $this->assertSame(["align" => "start"], $subtitle->getCues()[1]->findFormatData("vtt"));
        $this->assertStringContainsString("\n00:00:00.000 --> 00:00:01.000 line:40% size:40%\n", $subtitle->toString(Format::WebVtt));
    }


    public function testLineOfWhiteSpaceInsideACueDoesNotEndIt(): void
    {
        $expected = [
            ["the<00:00:00.480><c> ferry</c><00:00:00.960><c> leaves</c>"],
            ["at<00:00:02.800><c> noon</c>"],
        ];
        $lines = fn (SubtitleCue $cue): array => $cue->getLines();

        $parsed = Subtitle::fromString(file_get_contents(self::DIR . "own_ytdlp_auto_captions.vtt"), Format::WebVtt);
        $stream = (new WebVttStreamReader())->read(fopen(self::DIR . "own_ytdlp_auto_captions.vtt", "r"));

        $this->assertSame($expected, array_map($lines, $parsed->getCues()));
        $this->assertSame($expected, array_map($lines, iterator_to_array($stream, false)));
    }


    public function testYouTubeRollingCuesCollapseToOneCuePerLine(): void
    {
        $expected = [
            [0.16, 2.389, "welcome<00:00:00.400><c> back</c><00:00:00.800><c> to</c><00:00:01.120><c> the</c><00:00:01.440><c> garden</c>"],
            [2.389, 4.87, "today<00:00:02.880><c> we</c><00:00:03.200><c> plant</c><00:00:03.600><c> tomatoes</c>"],
            [4.87, 6.15, "first"],
            [6.15, 8.43, "dig<00:00:06.640><c> a</c><00:00:06.800><c> small</c><00:00:07.200><c> hole</c>"],
        ];
        $cue = fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()];

        $parsed = Subtitle::fromString(file_get_contents(self::DIR . "own_ytdlp_rolling.vtt"), Format::WebVtt);
        $stream = (new WebVttStreamReader())->read(fopen(self::DIR . "own_ytdlp_rolling.vtt", "r"));

        $this->assertSame($expected, array_map($cue, $parsed->getCues()));
        $this->assertSame($expected, array_map($cue, iterator_to_array($stream, false)));
        $this->assertSame(["align" => "start", "position" => "0%"], $parsed->getCues()[1]->findFormatData("vtt"));
    }


    public function testShortRepeatCuesWithoutWordTimestampsStay(): void
    {
        $content = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nthe ferry leaves\n\n" .
                   "00:00:02.000 --> 00:00:02.010\nthe ferry leaves\n\n00:00:02.010 --> 00:00:03.000\nthe ferry leaves\nat noon\n";

        $this->assertCount(3, Subtitle::fromString($content, Format::WebVtt)->getCues());
    }


    public function testCommentsKeepTheirCueWhenRollingCuesCollapse(): void
    {
        $content = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nthe<00:00:01.500><c> ferry</c>\n\n" .
                   "00:00:02.000 --> 00:00:02.010\nthe ferry\n\nNOTE second line\n\n" .
                   "00:00:02.010 --> 00:00:03.000\nthe ferry\nleaves<00:00:02.500><c> now</c>\n";
        $subtitle = Subtitle::fromString($content, Format::WebVtt);

        $this->assertCount(2, $subtitle->getCues());
        $this->assertSame(1, $subtitle->getComments()[0]->beforeCueIndex);
    }


    public function testRealFileYouTubeHeaderLinesAreKept(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "webvttpy_youtube.vtt"), Format::WebVtt);

        $headerLines = $subtitle->findFormatData("vtt")["headerLines"];
        $this->assertSame(["Kind: captions", "Language: en", "Style:"], array_slice($headerLines, 0, 3));
        $this->assertSame("##", end($headerLines));
    }


    public function testRealFileCommentsAndHeaderText(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "webvttpy_comments.vtt"), Format::WebVtt);

        $this->assertSame(["header" => "- Translation of a weather report"], $subtitle->findFormatData("vtt"));
        $this->assertSame(
            [0, 2, 3],
            array_column($subtitle->getComments(), "beforeCueIndex")
        );
        $this->assertSame("end of file", $subtitle->getComments()[2]->text);
    }


    private function describe(Subtitle $subtitle): array
    {
        return [
            $subtitle->findFormatData("vtt"),
            $subtitle->getComments(),
            array_map(fn (SubtitleCue $cue): array => [
                $cue->getStart(),
                $cue->getEnd(),
                $cue->getLines(),
                $cue->getAlignment(),
                $cue->findFormatData("vtt"),
            ], $subtitle->getCues()),
        ];
    }
}
