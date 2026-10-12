<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class LyricsParserTest extends TestCase
{
    public function testValidLrcFileParses()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"), Format::Lyrics);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testKeepsIdTags()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), Format::Lyrics);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags_formatted.lrc"),
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testExceededMinutesIgnoredCue()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_minutes.lrc"), Format::Lyrics);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Second Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testExceededSecondsIgnoresCue()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_seconds.lrc"), Format::Lyrics);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testExceededMilliSecondAccuracyIgnoresCue()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_centi_accuracy.lrc"), Format::Lyrics);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testMissingTextIgnoresCue()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/missing_text.lrc"), Format::Lyrics);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testMissingTimestampIgnoresCue()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/missing_timestamps.lrc"), Format::Lyrics);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testEndTimeIsNextCueStartIncludingCentiseconds(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00] First\n[00:02.75] Second\n", Format::Lyrics);

        $this->assertSame(2.75, $subtitle->getCues()[0]->getEnd());
    }


    public function testIdTagsBecomeMetadataOrLrcFormatData(): void
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), Format::Lyrics);

        $this->assertSame(
            [
                Subtitle::METADATA_AUTHOR => "Creator of the Songtext",
                Subtitle::METADATA_TITLE  => "Lyrics (song) title",
                Subtitle::METADATA_ALBUM  => "Album where the song is from",
            ],
            $subtitle->getAllMetadata()
        );
        $this->assertSame(
            ["idTags" => ["by" => "Creator of the LRC file", "length" => "How long the song is"]],
            $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY)
        );
    }


    public function testIdTagKeysAreCaseInsensitiveAndValuesAreTrimmed(): void
    {
        $subtitle = Subtitle::fromString("[TI: Morning Train ]
[Ar:Station Choir]
[00:01.00] Text
", Format::Lyrics);

        $this->assertSame("Morning Train", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("Station Choir", $subtitle->findMetadata(Subtitle::METADATA_ARTIST));
    }


    public function testCommentTagsBecomeComments(): void
    {
        $subtitle = Subtitle::fromString(
            "[#:Header note]
[00:01.00] First
[#:Before second]
[00:02.00] Second
", Format::Lyrics);

        $this->assertEquals(
            [
                new Comment("Header note", 0),
                new Comment("Before second", 1),
            ],
            $subtitle->getComments()
        );
    }


    public function testCommentTagStaysBeforeItsLineWhenTheLinesAreOutOfTimeOrder(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/real/own-comment-before-earlier-line.lrc"), Format::Lyrics);

        $this->assertSame(["The door opens", "The shop is open", "The shop closes"], array_map(fn ($cue) => $cue->getText(), $subtitle->getCues()));
        $this->assertEquals([
            new Comment("The door opens before the shop", 0),
            new Comment("The shop opens at nine", 1),
            new Comment("End of the shop song", 3),
        ], $subtitle->getComments());
    }


    public function testOffsetMakesLyricsShowEarlier(): void
    {
        $subtitle = Subtitle::fromString(
            "[00:12.00] First
[offset:+500]
[00:17.20] Second <00:18.00>word
", Format::Lyrics);

        $this->assertSame(11.5, $subtitle->getCues()[0]->getStart());
        $this->assertSame(16.7, $subtitle->getCues()[0]->getEnd());
        $this->assertSame(16.7, $subtitle->getCues()[1]->getStart());
        $this->assertSame("Second <00:00:17.500>word", $subtitle->getCues()[1]->getText());
        $this->assertSame([], $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY));
    }


    public function testNegativeOffsetMakesLyricsShowLater(): void
    {
        $subtitle = Subtitle::fromString("[offset:-250]
[00:12.00] First
", Format::Lyrics);

        $this->assertSame(12.25, $subtitle->getCues()[0]->getStart());
    }


    public function testOffsetDoesNotMakeTimesNegative(): void
    {
        $subtitle = Subtitle::fromString("[offset:1000]
[00:00.50] First
", Format::Lyrics);

        $this->assertSame(0.0, $subtitle->getCues()[0]->getStart());
    }


    public function testInvalidOffsetIsKeptAsFormatData(): void
    {
        $subtitle = Subtitle::fromString("[offset:soon]
[00:12.00] First
", Format::Lyrics);

        $this->assertSame(12.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(["idTags" => ["offset" => "soon"]], $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY));
    }


    public function testLineWithSeveralTimestampsBecomesOneCuePerTimestamp(): void
    {
        $subtitle = Subtitle::fromString(
            "[00:12.00][01:15.30]Chorus
[00:17.20]Verse
", Format::Lyrics);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([12.0, 17.2, "Chorus"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([17.2, 75.3, "Verse"], [$cues[1]->getStart(), $cues[1]->getEnd(), $cues[1]->getText()]);
        $this->assertSame([75.3, 80.3, "Chorus"], [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
    }


    public function testTimestampWithoutTextEndsThePreviousCue(): void
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/missing_text.lrc"), Format::Lyrics);

        $this->assertSame(154.56, $subtitle->getCues()[0]->getEnd());
        $this->assertSame(225.67, $subtitle->getCues()[1]->getStart());
    }


    public function testTimestampWithoutTextEndsTheLastCue(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00] First
[00:03.50]
", Format::Lyrics);

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame(3.5, $subtitle->getCues()[0]->getEnd());
    }


    public function testAcceptsTimestampsWithoutFractionAndWithMilliseconds(): void
    {
        $subtitle = Subtitle::fromString("[00:01] First
[00:02.345] Second
", Format::Lyrics);

        $this->assertSame(1.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(2.345, $subtitle->getCues()[1]->getStart());
    }


    public function testTimestampNeedsADotBeforeTheFraction(): void
    {
        $subtitle = Subtitle::fromString("[00:12x00] Wrong
[00:13.00] Right
", Format::Lyrics);

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame("Right", $subtitle->getCues()[0]->getText());
    }


    public static function timeTagShapes(): array
    {
        return [
            "spaces inside the brackets" => ["spaces_in_time_tags.lrc", [
                [12.0, 15.5, "The boats come home at dusk"],
                [15.5, 20.5, "Gulls follow every one"],
            ]],
            "hours and text on the next line" => ["hours_with_text_on_next_line.lrc", [
                [155.0, 171.0, "The boats come home at dusk"],
                [171.0, 176.0, "Gulls follow every one"],
            ]],
            "1-digit fraction" => ["one_digit_fraction.lrc", [
                [12.5, 15.25, "The boats come home at dusk"],
                [15.25, 20.25, "Gulls follow every one"],
            ]],
            "1-digit minutes" => ["one_digit_minutes.lrc", [
                [1.0, 65.0, "The boats come home at dusk"],
                [65.0, 70.0, "Gulls follow every one"],
            ]],
        ];
    }


    #[DataProvider("timeTagShapes")]
    public function testReadsTimeTagShapesInStrictAndLenientMode(string $file, array $expected): void
    {
        $content = file_get_contents(__DIR__ . "/../files/lrc/" . $file);
        $this->assertSame(Format::Lyrics, Format::detect($content));
        foreach ([false, true] as $lenient) {
            $subtitle = (new LyricsParser())->parse($content, new ReadOptions(lenient: $lenient));

            $this->assertSame($expected, array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues()));
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    public function testTimeTagWithoutTextDoesNotTakeAnIdTagOrATimeTagLine(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00]\n[ar:Harbour Band]\n[00:02.00]\n[00:03.00]Gulls\n", Format::Lyrics);

        $this->assertSame([[3.0, 8.0, "Gulls"]], array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues()));
        $this->assertSame("Harbour Band", $subtitle->findMetadata(Subtitle::METADATA_ARTIST));
    }


    public function testLastCueLastsFiveSecondsByDefault(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00] First
", Format::Lyrics);

        $this->assertSame(6.0, $subtitle->getCues()[0]->getEnd());
    }


    public function testLastCueDurationIsAnOption(): void
    {
        $subtitle = (new LyricsParser())->parse("[00:01.00] First
", new ReadOptions(lastCueDuration: 2.5));

        $this->assertSame(3.5, $subtitle->getCues()[0]->getEnd());
    }


    public function testWordTimestampsBecomeCoreMarkup(): void
    {
        $subtitle = Subtitle::fromString("[00:21.10]<00:21.10>Bread <00:21.60>is <61:01.905>warm
", Format::Lyrics);

        $this->assertSame(
            "<00:00:21.100>Bread <00:00:21.600>is <01:01:01.905>warm",
            $subtitle->getCues()[0]->getText()
        );
    }


    public function testSpacesAroundWordTimestampsSeparateTheWords(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/real/handwritten-enhanced.lrc"), Format::Lyrics);

        $this->assertSame("<00:00:03.000>Slow <00:00:03.550>river <00:00:04.400>runs", $subtitle->getCues()[0]->getText());
        $this->assertStringContainsString("\nSlow river runs\n", $subtitle->toString(Format::SubRip));
    }


    public function testSpaceBeforeAWordTimestampAtTheLineEndIsDropped(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00] <00:01.00> Fish <00:01.50> chips <00:02.00>\n", Format::Lyrics);

        $this->assertSame("<00:00:01.000>Fish <00:00:01.500>chips<00:00:02.000>", $subtitle->getCues()[0]->getText());
    }


    public function testPlainTextIsEscaped(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00]I <3 bread & jam\n", Format::Lyrics);

        $this->assertSame("I &lt;3 bread &amp; jam", $subtitle->getCues()[0]->getText());
    }


    public function testTextAroundWordTimestampsIsEscaped(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00]<00:01>Fish & <00:01.50>chips <3 <1:2>\n", Format::Lyrics);

        $this->assertSame(
            "<00:00:01.000>Fish &amp; <00:00:01.500>chips &lt;3 &lt;1:2&gt;",
            $subtitle->getCues()[0]->getText()
        );
    }


    public function testTextThatIsNotUtf8KeepsItsBytes(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00]caf\xE9 & tea\n", Format::Lyrics, new ReadOptions(encoding: "UTF-8"));

        $this->assertSame("caf\xE9 &amp; tea", $subtitle->getCues()[0]->getText());
        $this->assertSame("\u{feff}[00:01.00] caf\xE9 & tea\n", $subtitle->toString(Format::Lyrics));
    }


    public function testIdTagsAndCommentsAreNotEscaped(): void
    {
        $subtitle = Subtitle::fromString(
            "[ti:Fish & Chips]\n[re:<Editor>]\n[#:a < b & c]\n[00:01.00]Text\n",
            Format::Lyrics);

        $this->assertSame("Fish & Chips", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame(["idTags" => ["re" => "<Editor>"]], $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY));
        $this->assertSame("a < b & c", $subtitle->getComments()[0]->text);
    }


    /**
     * @return array<string, array{string, int, array{float, float, string}, array{float, float, string}}>
     */
    public static function realFiles(): array
    {
        return [
            "justan-1"             => [
                "justan-1.lrc", 42, [0.0, 1.0, "火车七点出发"], [202.98, 207.98, "烤箱闻起来很香"],
            ],
            "justan-4"             => [
                "justan-4.lrc", 34, [0.0, 4.0, "火车七点出发"], [202.0, 207.0, "天气准时到站　站台下了一整天"],
            ],
            "lrc-maker-nami"       => [
                "lrc-maker-nami.lrc", 39, [0.0, 1.0, "電車は七時に出る：example"], [235.536, 243.353, "——天気は晴れです、駅は少し混む。"],
            ],
            "mantas-done-lrc"      => [
                "mantas-done-lrc.lrc", 5, [8.62, 9.64, "Trains run early"], [22.63, 27.63, "Rain comes later"],
            ],
            "whisper.cpp"          => [
                "own_whisper_cpp.lrc", 5, [0.0, 3.88, "The train to the coast leaves at seven."], [60.15, 65.15, "Thank you for listening."],
            ],
            "subsrt-sample"        => [
                "subsrt-sample.lrc", 6, [12.0, 17.2, "Line 1 about the train"], [29.02, 34.02, "Line 6 about the bread"],
            ],
            "handwritten-core"     => [
                "handwritten-core.lrc",
                5,
                [5.0, 9.4, "The train leaves at seven"],
                [32.8, 37.8, "Ring the bell, ring the bell"],
            ],
            "handwritten-enhanced" => [
                "handwritten-enhanced.lrc",
                3,
                [3.0, 5.9, "<00:00:03.000>Slow <00:00:03.550>river <00:00:04.400>runs"],
                [9.6, 14.6, "<00:00:09.600>Into <00:00:10.100>the <00:00:10.650>sea"],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, array $firstCue, array $lastCue): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/real/" . $file), Format::Lyrics);
        $cues     = $subtitle->getCues();
        $last     = $cues[count($cues) - 1];

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesRoundTrip(string $file): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/real/" . $file), Format::Lyrics);
        $again    = Subtitle::fromString(
            $subtitle->toString(Format::Lyrics), Format::Lyrics);

        $this->assertSame($this->describe($subtitle), $this->describe($again));
    }


    public function testRealFileMetadata(): void
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/lrc/real/subsrt-sample.lrc"), Format::Lyrics);

        $this->assertSame("Weather (morning) report", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("Station choir", $subtitle->findMetadata(Subtitle::METADATA_ARTIST));
        $this->assertSame("Songs from the bakery", $subtitle->findMetadata(Subtitle::METADATA_ALBUM));
        $this->assertSame("Writer of the words", $subtitle->findMetadata(Subtitle::METADATA_AUTHOR));
        $this->assertSame(
            ["length", "by", "offset", "re", "ve"],
            array_keys($subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY)["idTags"])
        );
    }


    private function describe(Subtitle $subtitle): array
    {
        $metadata = $subtitle->getAllMetadata();
        ksort($metadata);

        // the formatter writes centiseconds, so millisecond timestamps come back rounded
        return [
            array_map(
                fn ($cue) => [round($cue->getStart(), 2), round($cue->getEnd(), 2), $cue->getLines()],
                $subtitle->getCues()
            ),
            $metadata,
            $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY),
            $subtitle->getComments(),
        ];
    }
}
