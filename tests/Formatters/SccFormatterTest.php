<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class SccFormatterTest extends TestCase
{
    private const HEADER = "Scenarist_SCC V1.0\n\n";

    private const FIT_FILES = __DIR__ . "/../files/scc/fit/";


    private function subtitle(SubtitleCue ...$cues): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($cues as $cue) {
            $subtitle->addCue($cue);
        }

        return $subtitle;
    }


    public function testIssueExampleRoundTrips(): void
    {
        $scc = self::HEADER . "00:00:01;12\t9420 9420 94ae 94ae 9452 9452 97a1 97a1 c8e5 ecec efa1 942f 942f\n\n00:00:04;00\t942c 942c\n";

        $this->assertSame(
            self::HEADER . "00:00:01;12\t94ae 94ae 9420 9420 9452 9452 97a1 97a1 c8e5 ecec efa1 942f 942f\n\n00:00:04;00\t942c 942c\n",
            Subtitle::fromString($scc, Format::Scc)->toString(Format::Scc)
        );
    }


    public function testLoadsTheCaptionSoThatEocFallsOnTheCueStart(): void
    {
        $output = $this->subtitle(new SubtitleCue(1.0, 3.0, "Hello!"))->toString(Format::Scc);

        // 30 frames is 1.001 s, the nearest frame to 1.0 s. The load takes the 11 frames before it.
        $this->assertSame(self::HEADER . "00:00:00;19\t94ae 94ae 9420 9420 9476 9476 97a1 97a1 c8e5 ecec efa1 942f 942f\n\n"
                          . "00:00:03;00\t942c 942c\n", $output);
        $cue = Subtitle::fromString($output, Format::Scc)->getCues()[0];
        $this->assertSame([1.001, 3.003, ["Hello!"]], [$cue->getStart(), $cue->getEnd(), $cue->getLines()]);
    }


    public function testPadsAnOddCharacterWithFillerAndDoublesControlCodes(): void
    {
        $output = $this->subtitle(new SubtitleCue(2.0, 4.0, ["Hi!", "\u{266A} A"]))->toString(Format::Scc);

        $this->assertStringContainsString("94ae 94ae 9420 9420 94d6 94d6 97a2 97a2 c8e9 a180 9476 9476 97a2 97a2 9137 9137 20c1 942f 942f", $output);
    }


    public function testWritesExtendedCharactersAfterTheirStandardFallback(): void
    {
        $text   = "Caf\u{E9} \u{201C}\u{D6}\u{201D} \u{A1}S\u{ED}!";
        $output = $this->subtitle(new SubtitleCue(2.0, 4.0, $text))->toString(Format::Scc);

        // The standard set has e and i with acute accent as 5c and 5e. The quotes, the O with umlaut
        // and the inverted exclamation mark are extended characters after their fallbacks.
        $this->assertStringContainsString("4361 e6dc 20a2 92ae 92ae 4f80 1332 1332 a280 922f 922f 20a1 92a7 92a7 d35e a180", $output);
        $this->assertSame([$text], Subtitle::fromString($output, Format::Scc)->getCues()[0]->getLines());
    }


    public function testWritesCoreMarkupAsMidRowCodesAndPacStyles(): void
    {
        $lines  = ["<i>Rain</i> and <font color=\"#ffff00\">sun <u>later</u></font>", "<font color=\"#ff0000\"><i>Red</i></font> sky"];
        $cue    = (new SubtitleCue(2.0, 4.0, $lines))->setAlignment(Alignment::BottomLeft);
        $output = $this->subtitle($cue)->toString(Format::Scc);

        // Line 1 starts with the italics PAC 94ce. Line 2 starts with the red PAC 9468 and the italics mid-row code 91ae.
        $this->assertStringContainsString("94ce 94ce 5261 e96e 9120 9120 616e 6480 912a 912a 7375 6e80 91ab 91ab", $output);
        $this->assertStringContainsString("9468 9468 91ae 91ae 52e5 6480 9120 9120 736b 7980", $output);
        $this->assertSame($lines, Subtitle::fromString($output, Format::Scc)->getCues()[0]->getLines());
    }


    public function testSingleQuotedColourBecomesAMidRowCode(): void
    {
        $output = $this->subtitle(new SubtitleCue(2.0, 4.0, "Rain and <font color='#ffff00'>sun</font>"))->toString(Format::Scc);

        $this->assertSame(["Rain and <font color=\"#ffff00\">sun</font>"], Subtitle::fromString($output, Format::Scc)->getCues()[0]->getLines());
    }


    /**
     * @return array<string, array{string, int, list<string>}>
     */
    public static function stylesOverTwoLines(): array
    {
        return [
            "italics"                => ["srt/real/own_styled.srt", 6, ["<i>Italics over two lines</i>", "<i>that close on the second line</i>"]],
            "italics that stay open" => ["fixing/web-errors.srt", 2, ["<i>The station was closed,</i>", "<i>so we walked.</i>"]],
            "color that stays open"  => ["fixing/web-errors.srt", 10, ["<font color=\"#ffff00\">Next stop:</font>", "<font color=\"#ffff00\">the harbour</font>"]],
        ];
    }


    #[DataProvider("stylesOverTwoLines")]
    public function testStyleThatSpansTwoLinesStylesBoth(string $file, int $index, array $lines): void
    {
        $scc = Subtitle::load(__DIR__ . "/../files/$file", Format::fromPath($file))->toString(Format::Scc, new WriteOptions(formats: [new SccWriteOptions(fit: true)]));

        $this->assertSame($lines, Subtitle::fromString($scc, Format::Scc)->getCues()[$index]->getLines());
    }


    public function testDropsTagsThatCea608CannotShow(): void
    {
        $output = $this->subtitle(new SubtitleCue(2.0, 4.0, "<b>Bold</b> <v Ann>and <font color=\"#123456\">grey</font>"))->toString(Format::Scc);

        $this->assertSame(["Bold and grey"], Subtitle::fromString($output, Format::Scc)->getCues()[0]->getLines());
    }


    public function testKeepsTextThatLooksLikeATag(): void
    {
        $output = $this->subtitle(new SubtitleCue(2.0, 4.0, "a < b > c"))->toString(Format::Scc);

        $this->assertStringContainsString("6120 bc20 6220 3e20 e380", $output);
        $this->assertSame(["a &lt; b &gt; c"], Subtitle::fromString($output, Format::Scc)->getCues()[0]->getLines());
    }


    public static function alignmentProvider(): array
    {
        return [
            "default is bottom centre" => [Alignment::BottomCenter, [14, 15], [12, 13]],
            "top left"                 => [Alignment::TopLeft, [1, 2], [0, 0]],
            "top centre"               => [Alignment::TopCenter, [1, 2], [12, 13]],
            "middle right"             => [Alignment::MiddleRight, [7, 8], [25, 27]],
            "bottom left"              => [Alignment::BottomLeft, [14, 15], [0, 0]],
        ];
    }


    #[DataProvider("alignmentProvider")]
    public function testAlignmentSetsRowsAndColumns(Alignment $alignment, array $rows, array $columns): void
    {
        $cue    = (new SubtitleCue(2.0, 4.0, ["Weather", "today"]))->setAlignment($alignment);
        $parsed = Subtitle::fromString($this->subtitle($cue)->toString(Format::Scc), Format::Scc)->getCues()[0];

        $this->assertSame(["rows" => $rows, "columns" => $columns], array_diff_key($parsed->findFormatData(SccParser::FORMAT_DATA_KEY), ["mode" => 0]));
        $this->assertSame(in_array($alignment, [Alignment::TopLeft, Alignment::TopCenter], true) ? Alignment::TopCenter : Alignment::BottomCenter, $parsed->getAlignment());
    }


    public function testKeepsRowsAndColumnsFromTheSccFormatData(): void
    {
        $cue = (new SubtitleCue(2.0, 4.0, ["Left", "Right"]))->setFormatData(SccParser::FORMAT_DATA_KEY, ["rows" => [10, 12], "columns" => [3, 27]]);

        $parsed = Subtitle::fromString($this->subtitle($cue)->toString(Format::Scc), Format::Scc)->getCues()[0];

        $this->assertSame([10, 12], $parsed->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]);
        $this->assertSame([3, 27], $parsed->findFormatData(SccParser::FORMAT_DATA_KEY)["columns"]);
    }


    public function testIgnoresSccFormatDataThatNoLongerFitsTheCue(): void
    {
        $cue = (new SubtitleCue(2.0, 4.0, ["One line"]))->setFormatData(SccParser::FORMAT_DATA_KEY, ["rows" => [10, 12], "columns" => [3, 27]]);

        $parsed = Subtitle::fromString($this->subtitle($cue)->toString(Format::Scc), Format::Scc)->getCues()[0];

        $this->assertSame([15], $parsed->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]);
    }


    public function testMovesTheEocLaterWhenTheLoadDoesNotFit(): void
    {
        // The cue times of https://github.com/pbs/pycaption/issues/352: a short cue, then a long cue soon after it.
        // The long cue needs 23 frames to load, so it shows 0.7 s late. Cue 3 has no room for an EDM before cue 4 shows.
        $output = $this->subtitle(
            new SubtitleCue(2.2, 2.359, "so,"),
            new SubtitleCue(2.4, 3.76, "the bus was late again today."),
            new SubtitleCue(4.7, 5.169, "And then,"),
            new SubtitleCue(5.21, 5.52, "oh")
        )->toString(Format::Scc);

        preg_match_all("/^(\d\d:\d\d:\d\d;\d\d)\t/m", $output, $matches);
        $sorted = $matches[1];
        sort($sorted);
        $this->assertSame($sorted, $matches[1]);

        $cues = Subtitle::fromString($output, Format::Scc)->getCues();
        $this->assertSame([[2.202, 2.369], [3.103, 3.77], [4.705, 5.205], [5.205, 5.506]], array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()],
            $cues
        ));
        $this->assertSame(["so,", "the bus was late again today.", "And then,", "oh"], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $cues));
    }


    public function testOverlappingCueReplacesTheEarlierCue(): void
    {
        $output = $this->subtitle(new SubtitleCue(1.0, 5.0, "First"), new SubtitleCue(3.0, 4.0, "Second"))->toString(Format::Scc);
        $cues   = Subtitle::fromString($output, Format::Scc)->getCues();

        $this->assertSame([[1.001, 3.003], [3.003, 4.004]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $cues));
    }


    public function testWritesDropFrameTimeCodesByDefaultAndNonDropAsAnOption(): void
    {
        $subtitle = $this->subtitle(new SubtitleCue(58.0, 61.0, "Hi"));

        $this->assertStringContainsString("\n00:01:01;00\t942c 942c\n", $subtitle->toString(Format::Scc));
        $this->assertStringContainsString(
            "\n00:01:00:28\t942c 942c\n",
            $subtitle->toString(Format::Scc, new WriteOptions(formats: [new SccWriteOptions(dropFrame: false)]))
        );
    }


    public function testKeepsTheTimeCodeTypeOfTheParsedFile(): void
    {
        $subtitle = Subtitle::fromString(self::HEADER . "00:00:01:00\t9420 9420 9470 9470 c8e9 942f 942f\n\n00:00:03:00\t942c 942c\n", Format::Scc);

        $this->assertStringContainsString("\n00:00:03:00\t942c 942c\n", $subtitle->toString(Format::Scc));
        $this->assertStringContainsString("\n00:00:03;00\t942c 942c\n", $subtitle->toString(Format::Scc, new WriteOptions(formats: [new SccWriteOptions(dropFrame: true)])));
    }


    public function testThrowsForMoreThanFourLines(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #1 at 3 s has 5 lines, but SCC allows 4. Call wrapLines(32, 4) first.");

        $this->subtitle(new SubtitleCue(1.0, 2.0, "Hi"), new SubtitleCue(3.0, 4.0, ["1", "2", "3", "4", "5"]))->toString(Format::Scc);
    }


    public function testThrowsForALineLongerThan32Characters(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #0 at 1 s has a line with 33 characters, but SCC allows 32. Call wrapLines(32, 4) first.");

        $this->subtitle(new SubtitleCue(1.0, 2.0, "<i>" . str_repeat("a", 33) . "</i>"))->toString(Format::Scc);
    }


    public function testNamesTheSplitStepWhenWrappingCannotFitTheCue(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #0 at 1 s has a line with 139 characters, but SCC allows 32. " .
                                      "Call Resegmenter::apply() with ResegmentMode::SplitLong and new CueLimits(32, 4), then wrapLines(32, 4).");

        $this->subtitle(new SubtitleCue(1.0, 8.0, implode(" ", array_fill(0, 20, "bakery"))))->toString(Format::Scc);
    }


    public function testSplitLongAndWrapLinesMakeTheCueFit(): void
    {
        $subtitle = $this->subtitle(new SubtitleCue(1.0, 8.0, implode(" ", array_fill(0, 20, "bakery"))));
        Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong, new CueLimits(32, 4)));
        $subtitle->wrapLines(32, 4);

        $this->assertStringStartsWith("Scenarist_SCC V1.0\n", $subtitle->toString(Format::Scc));
    }


    public function testWrapLinesMakesALongCueFit(): void
    {
        $subtitle = $this->subtitle(new SubtitleCue(1.0, 4.0, "The night train to the coast leaves from platform two at ten past eleven."));
        $subtitle->wrapLines(32, 4);

        $cue = Subtitle::fromString($subtitle->toString(Format::Scc), Format::Scc)->getCues()[0];
        $this->assertSame($subtitle->getCues()[0]->getLines(), $cue->getLines());
    }


    public function testThrowsForACharacterThatCea608Lacks(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #0 at 1 s has the character \"\u{65E5}\", which CEA-608 cannot show.");

        $this->subtitle(new SubtitleCue(1.0, 2.0, "\u{65E5}"))->toString(Format::Scc);
    }


    public function testFitChangesWhatSccCannotHoldAndReportsEachChange(): void
    {
        $subtitle = Subtitle::load(self::FIT_FILES . "own_ferry.srt", Format::SubRip);
        $options  = new WriteOptions(formats: [new SccWriteOptions(fit: true)]);
        $report   = (new SccFormatter())->formatWithReport($subtitle, $options);

        $this->assertSame(file_get_contents(self::FIT_FILES . "own_ferry.scc"), $report->content);
        $this->assertSame($report->content, $subtitle->toString(Format::Scc, $options));
        $this->assertSame([
            [0, SccFitAction::Transliterated, "Cue #0 at 1 s: replaced \"\u{160}\" with \"S\", \"\u{2026}\" with \"...\"."],
            [0, SccFitAction::Wrapped, "Cue #0 at 1 s: wrapped the text into 3 lines of at most 32 characters."],
            [0, SccFitAction::Delayed, "Cue #0 at 1 s shows at 1.969 s. The frames before its start cannot hold its caption data."],
            [2, SccFitAction::Transliterated, "Cue #2 at 4.3 s: replaced \"\u{2013}\" with \"-\"."],
            [2, SccFitAction::Wrapped, "Cue #2 at 4.3 s: wrapped the text into 2 lines of at most 32 characters."],
            [2, SccFitAction::Delayed, "Cue #2 at 4.3 s shows at 5.172 s. The frames before its start cannot hold its caption data."],
            [3, SccFitAction::Dropped, "Cue #3 at 5 s is left out. Its caption data needs the frames up to 5.839 s, but the cue ends at 5.4 s."],
        ], array_map(fn (SccFitChange $change): array => [$change->cueIndex, $change->action, $change->message], $report->changes));

        $cues = Subtitle::fromString($report->content, Format::Scc)->getCues();
        $this->assertSame([
            [1.969, 4.004, ["The ferry to Split leaves", "from the old harbour at nine.", "Sibenik is the second stop..."]],
            [4.004, 4.304, ["Wait!"]],
            [5.172, 6.006, ["Tickets cost twelve", "euros - cash only."]],
        ], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues));
    }


    public function testWithoutFitTheWriterStillThrowsAndReportsNothing(): void
    {
        $subtitle = Subtitle::load(self::FIT_FILES . "own_ferry.srt", Format::SubRip);
        $fitting  = $this->subtitle(new SubtitleCue(1.0, 3.0, "Hello!"), new SubtitleCue(3.0, 3.1, "The bus was late again today."));
        $report   = (new SccFormatter())->formatWithReport($fitting);

        $this->assertSame([$fitting->toString(Format::Scc), []], [$report->content, $report->changes]);
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #0 at 1 s has a line with 46 characters, but SCC allows 32.");
        $subtitle->toString(Format::Scc);
    }


    public function testFitThrowsForTextThatDoesNotFitAndACharacterWithoutReplacement(): void
    {
        $options = new WriteOptions(formats: [new SccWriteOptions(fit: true)]);
        try {
            $this->subtitle(new SubtitleCue(1.0, 8.0, implode(" ", array_fill(0, 20, "bakery"))))->toString(Format::Scc, $options);
            $this->fail("No exception");
        } catch (UnwritableContentException $exception) {
            $this->assertStringContainsString("has a line with 139 characters", $exception->getMessage());
        }

        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #0 at 1 s has the character \"\u{65E5}\", which CEA-608 cannot show.");
        $this->subtitle(new SubtitleCue(1.0, 2.0, "\u{160}\u{65E5}"))->toString(Format::Scc, $options);
    }


    public function testFitKeepsTagsAndTextThatSccHolds(): void
    {
        $subtitle = $this->subtitle(new SubtitleCue(1.0, 3.0, ["<i>Caf\u{E9} \u{201C}Zagreb\u{201D}</i>", "<font color=\"red\">&lt;\u{160}&gt;</font>"]));
        $options  = new WriteOptions(formats: [new SccWriteOptions(fit: true)]);
        $report   = (new SccFormatter())->formatWithReport($subtitle, $options);

        $this->assertSame(["Cue #0 at 1 s: replaced \"\u{160}\" with \"S\"."], array_column($report->changes, "message"));
        $this->assertSame(["<i>Caf\u{E9} \u{201C}Zagreb\u{201D}</i>", "<font color=\"#ff0000\">&lt;S&gt;</font>"],
                          Subtitle::fromString($report->content, Format::Scc)->getCues()[0]->getLines());
    }


    public function testWritesOnlyTheHeaderForASubtitleWithoutText(): void
    {
        $this->assertSame("Scenarist_SCC V1.0\n", $this->subtitle(new SubtitleCue(1.0, 2.0, ["<i></i>", " "]))->toString(Format::Scc));
        $this->assertSame("Scenarist_SCC V1.0\n", (new Subtitle())->toString(Format::Scc));
    }


    public function testLineEndingAndBomOptions(): void
    {
        $output = $this->subtitle(new SubtitleCue(1.0, 3.0, "Hi"))->toString(Format::Scc, new WriteOptions(lineEnding: LineEnding::Crlf, bom: true));

        $this->assertStringStartsWith("\xEF\xBB\xBFScenarist_SCC V1.0\r\n\r\n00:00:00;21\t", $output);
        $this->assertStringEndsWith("\r\n\r\n00:00:03;00\t942c 942c\r\n", $output);
    }
}
