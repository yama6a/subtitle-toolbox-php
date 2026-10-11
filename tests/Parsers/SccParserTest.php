<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\SccRollUp;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SccParserTest extends TestCase
{
    private const ISSUE_EXAMPLE = "Scenarist_SCC V1.0\n\n"
                                  . "00:00:01;12\t9420 9420 94ae 94ae 9452 9452 97a1 97a1 c8e5 ecec efa1 942f 942f\n\n"
                                  . "00:00:04;00\t942c 942c\n";


    /**
     * Packs ASCII text two characters per word with odd parity, and pads an odd character with 80.
     */
    private static function text(string $text): string
    {
        $bytes = array_map(fn (string $character): int => self::parity(ord($character)), str_split($text));
        if (count($bytes) % 2 === 1) {
            $bytes[] = 0x80;
        }

        return implode(" ", array_map(fn (array $pair): string => sprintf("%02x%02x", ...$pair), array_chunk($bytes, 2)));
    }


    private static function parity(int $value): int
    {
        return substr_count(decbin($value), "1") % 2 === 1 ? $value : $value | 0x80;
    }


    /**
     * @return list<SubtitleCue>
     */
    private function cues(string ...$lines): array
    {
        return array_values(Subtitle::fromString("Scenarist_SCC V1.0\n\n" . implode("\n\n", $lines) . "\n", Format::Scc)->getCues());
    }


    /**
     * @return list<SubtitleCue>
     */
    private function lineCues(string ...$lines): array
    {
        $options = new ReadOptions(formats: [new SccReadOptions(rollUp: SccRollUp::Lines)]);

        return array_values(Subtitle::fromString("Scenarist_SCC V1.0\n\n" . implode("\n\n", $lines) . "\n", Format::Scc, $options)->getCues());
    }


    /**
     * @param list<SubtitleCue> $cues
     * @return list<array{float, float, list<string>}>
     */
    private static function describe(array $cues): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues);
    }


    private static function seconds(int $frame): float
    {
        return round($frame * 1001 / 30000, 3);
    }


    private function popOn(string $data): string
    {
        return "00:00:01:00\t94ae 94ae 9420 9420 $data 942f 942f";
    }


    public function testIssueExample(): void
    {
        $subtitle = Subtitle::fromString(self::ISSUE_EXAMPLE, Format::Scc);
        $cue      = $subtitle->getCues()[0];

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame([1.768, 4.004], [$cue->getStart(), $cue->getEnd()]);
        $this->assertSame([["Hello!"], null], [$cue->getLines(), $cue->getAlignment()]);
        $this->assertSame(["mode" => "pop-on", "rows" => [14], "columns" => [5]], $cue->findFormatData(SccParser::FORMAT_DATA_KEY));
        $this->assertSame(["dropFrame" => true], $subtitle->findFormatData(SccParser::FORMAT_DATA_KEY));
    }


    public static function timecodeProvider(): array
    {
        return [
            "non-drop"                         => ["00:01:00:00", 1800],
            "drop-frame in the first minute"   => ["00:00:59;29", 1799],
            "drop-frame skips ;00 and ;01"     => ["00:01:00;02", 1800],
            "drop-frame does not skip at :10"  => ["00:10:00;00", 17982],
            "drop-frame after one hour"        => ["01:00:00;00", 107892],
            "non-drop after one hour"          => ["01:00:00:00", 108000],
        ];
    }


    #[DataProvider("timecodeProvider")]
    public function testTimecodeCountsFramesAt2997(string $timecode, int $frames): void
    {
        $cues = $this->cues("$timecode\t9420 9470 " . self::text("Hi") . " 942f");

        $this->assertSame(round(($frames + 3) * 1001 / 30000, 3), $cues[0]->getStart());
    }


    public function testEachBytePairTakesOneFrame(): void
    {
        $cues = $this->cues("00:00:00:00\t9420 9470 " . self::text("A") . " 942f 8080 8080 942c " . self::text("B") . " 942f");

        $this->assertSame([round(3 * 1001 / 30000, 3), round(6 * 1001 / 30000, 3)], [$cues[0]->getStart(), $cues[0]->getEnd()]);
        $this->assertSame([["A"], ["B"], round(8 * 1001 / 30000, 3)], [$cues[0]->getLines(), $cues[1]->getLines(), $cues[1]->getStart()]);
    }


    public function testLastCaptionWithoutEraseLastsFiveSeconds(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("Hi")))[0];

        $this->assertSame(round($cue->getStart() + 5, 3), $cue->getEnd());
    }


    public function testDoubledControlCodeActsOnce(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("ABC") . " 94a1 94a1"), "00:00:05:00\t942c 942c")[0];

        $this->assertSame(["AB"], $cue->getLines());
    }


    public function testThirdCopyOfAControlCodeActsAgain(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("ABCD") . " 94a1 94a1 94a1"), "00:00:05:00\t942c 942c")[0];

        $this->assertSame(["AB"], $cue->getLines());
    }


    public function testControlCodeWithParityErrorInTheSecondByteIsIgnoredAndTheCopyActs(): void
    {
        // af is the EOC second byte 0x2f with a parity error.
        $this->assertSame([], $this->cues("00:00:00:00\t9420 9470 " . self::text("Hi") . " 94af"));

        $cues = $this->cues("00:00:05:00\t9420 9470 " . self::text("Hi") . " 94af 942f");
        $this->assertSame(round(154 * 1001 / 30000, 3), $cues[0]->getStart());
    }


    public function testRedundantCopyWithParityErrorInTheFirstByteIsIgnored(): void
    {
        // 0x14 without its parity bit fails the check. Its second byte equals the code before it.
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("ABC") . " 94a1 14a1"), "00:00:05:00\t942c 942c")[0];

        $this->assertSame(["AB"], $cue->getLines());
    }


    public function testCharacterWithParityErrorIsDropped(): void
    {
        // 0x48 "H" has even parity without bit 7, so c8 is correct and 48 is a parity error.
        $cue = $this->cues($this->popOn("9470 9470 48e9 c8e9"))[0];

        $this->assertSame(["iHi"], $cue->getLines());
    }


    public function testSpecialAndExtendedCharacters(): void
    {
        // 9137 is the music note. 2a, 7e and 7c are á, ñ and the division sign of the standard set.
        // "E" then 92a1 gives É, "O" then 13b3 gives ö: an extended character replaces the character before it.
        $data = "9470 9470 9137 9137 a280 92ae 92ae 2afe 7c80 4580 92a1 92a1 4f80 13b3 13b3 a280 922f 922f";
        $cue  = $this->cues($this->popOn($data))[0];

        $this->assertSame(["\u{266A}\u{201C}áñ\u{F7}Éö\u{201D}"], $cue->getLines());
    }


    public function testTransparentSpaceAndFillerBytes(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 c180 91b9 91b9 c280 8080"))[0];

        $this->assertSame(["A B"], $cue->getLines());
    }


    public function testMidRowCodesBecomeCoreMarkup(): void
    {
        // 91ae italics, 9120 white, 912a yellow, 91ab yellow underline, 9129 red underline then 91ae italics keeps red.
        $data = "9470 9470 " . self::text("A") . " 91ae 91ae " . self::text("B") . " 9120 9120 " . self::text("C")
                . " 912a 912a " . self::text("D") . " 91ab 91ab " . self::text("E") . " 9129 9129 91ae 91ae " . self::text("F");
        $cue  = $this->cues($this->popOn($data))[0];

        $this->assertSame(
            ["A <i>B</i> C <font color=\"#ffff00\">D <u>E</u></font> <font color=\"#ff0000\"><i>F</i></font>"],
            $cue->getLines()
        );
    }


    public static function pacProvider(): array
    {
        return [
            "row 1 white"            => ["9140", 1, 0, "Hi", 8],
            "row 2 green"            => ["9162", 2, 0, "<font color=\"#00ff00\">Hi</font>", 8],
            "row 3 blue underline"   => ["9245", 3, 0, "<font color=\"#0000ff\"><u>Hi</u></font>", 8],
            "row 4 cyan"             => ["92e6", 4, 0, "<font color=\"#00ffff\">Hi</font>", 8],
            "row 5 red"              => ["15c8", 5, 0, "<font color=\"#ff0000\">Hi</font>", null],
            "row 6 yellow"           => ["15ea", 6, 0, "<font color=\"#ffff00\">Hi</font>", null],
            "row 7 magenta"          => ["164c", 7, 0, "<font color=\"#ff00ff\">Hi</font>", null],
            "row 8 italics"          => ["166e", 8, 0, "<i>Hi</i>", null],
            "row 9 italics underline" => ["974f", 9, 0, "<i><u>Hi</u></i>", null],
            "row 10 indent 28"       => ["97fe", 10, 28, "Hi", null],
            "row 11 indent 4"        => ["1052", 11, 4, "Hi", null],
            "row 12 indent 8 underline" => ["13d5", 12, 8, "<u>Hi</u>", null],
            "row 13 indent 12"       => ["1376", 13, 12, "Hi", null],
            "row 14 indent 16"       => ["9458", 14, 16, "Hi", null],
            "row 15 indent 20"       => ["947a", 15, 20, "Hi", null],
        ];
    }


    #[DataProvider("pacProvider")]
    public function testPreambleAddressCodeSetsRowColumnAndStyle(string $pac, int $row, int $column, string $line, ?int $alignment): void
    {
        $cue = $this->cues($this->popOn("$pac $pac " . self::text("Hi")))[0];

        $this->assertSame([[$line], $alignment], [$cue->getLines(), $cue->getAlignment()]);
        $this->assertSame(["rows" => [$row], "columns" => [$column]], array_diff_key($cue->findFormatData(SccParser::FORMAT_DATA_KEY), ["mode" => 0]));
    }


    public function testTopRowSetsTheAlignmentOfATwoRowCaption(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("B") . " 9140 9140 " . self::text("A")))[0];

        $this->assertSame([["A", "B"], 8], [$cue->getLines(), $cue->getAlignment()]);
        $this->assertSame([1, 15], $cue->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]);
    }


    public function testTabOffsetsMoveTheCursor(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 9723 9723 " . self::text("Hi")))[0];

        $this->assertSame(3, $cue->findFormatData(SccParser::FORMAT_DATA_KEY)["columns"][0]);
    }


    public function testCharactersAfterColumn32ReplaceTheLastColumn(): void
    {
        $cue = $this->cues($this->popOn("94fe 94fe " . self::text("ABCDEFGH")))[0];

        $this->assertSame(["ABCH"], $cue->getLines());
    }


    public function testBackspaceAndDeleteToEndOfRow(): void
    {
        $data = "9470 9470 " . self::text("ABCDEF") . " 94a1 94a1 9470 9470 9723 9723 94a4 94a4 " . self::text("X");
        $cue  = $this->cues($this->popOn($data))[0];

        $this->assertSame(["ABCX"], $cue->getLines());
    }


    public function testPopOnCaptionReplacesTheDisplayedCaptionWithoutErase(): void
    {
        $cues = $this->cues(
            "00:00:01:00\t9420 9420 9470 9470 " . self::text("ONE") . " 942f 942f",
            "00:00:03:00\t94ae 94ae 9420 9420 9470 9470 " . self::text("TWO") . " 942f 942f",
            "00:00:05:00\t942c 942c"
        );

        $this->assertSame([["ONE"], ["TWO"]], [$cues[0]->getLines(), $cues[1]->getLines()]);
        $this->assertSame($cues[1]->getStart(), $cues[0]->getEnd());
    }


    public function testRollUpGivesOneCuePerLineOfTheFile(): void
    {
        $cues = $this->cues(
            "00:00:01:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("ONE"),
            "00:00:02:00\t" . self::text(" TWO"),
            "00:00:03:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("THREE"),
            "00:00:04:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("FOUR"),
            "00:00:05:00\t942c 942c"
        );

        $this->assertSame([["ONE"], ["ONE TWO"], ["ONE TWO", "THREE"], ["THREE", "FOUR"]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $cues));
        $this->assertSame(round(36 * 1001 / 30000, 3), $cues[0]->getStart());
        $this->assertSame(["roll-up", [14, 15]], [$cues[3]->findFormatData(SccParser::FORMAT_DATA_KEY)["mode"], $cues[3]->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]]);
        $this->assertSame(round(150 * 1001 / 30000, 3), $cues[3]->getEnd());
    }


    public function testRollUpLinesGivesOneCuePerRowUntilItRollsUp(): void
    {
        $cues = $this->lineCues(
            "00:00:01:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("ONE"),
            "00:00:02:00\t" . self::text(" TWO"),
            "00:00:03:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("THREE"),
            "00:00:04:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("FOUR"),
            "00:00:05:00\t942c 942c"
        );

        $this->assertSame([
            [self::seconds(36), self::seconds(92), ["ONE TWO"]],
            [self::seconds(96), self::seconds(122), ["THREE"]],
            [self::seconds(126), self::seconds(150), ["FOUR"]],
        ], self::describe($cues));
        $this->assertSame(["mode" => "roll-up", "rows" => [15], "columns" => [0]], $cues[0]->findFormatData(SccParser::FORMAT_DATA_KEY));
    }


    public function testRollUpLinesFollowsTheWindowToTheRowOfANewPac(): void
    {
        $cues = $this->lineCues(
            "00:00:01:00\t9426 9426 94ad 94ad 9470 9470 " . self::text("ONE"),
            "00:00:02:00\t1370 1370",
            "00:00:03:00\t9426 9426 94ad 94ad 1370 1370 " . self::text("THREE"),
            "00:00:04:00\t942c 942c"
        );

        $this->assertSame([["ONE"], ["THREE"]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $cues));
        $this->assertSame([13, 13], array_map(fn (SubtitleCue $cue): int => $cue->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"][0], $cues));
        $this->assertSame(self::seconds(92), $cues[0]->getEnd());
    }


    public function testRollUpLinesKeepsPopOnCaptionsAndEndsARowAtEndOfCaption(): void
    {
        $content = [
            "00:00:01:00\t9420 9420 9470 9470 " . self::text("POP") . " 942f 942f",
            "00:00:03:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("ROLL"),
            "00:00:05:00\t9420 9420 9470 9470 " . self::text("NEXT") . " 942f 942f",
            "00:00:07:00\t942c 942c",
        ];

        $screen = $this->cues(...$content);
        $lines  = $this->lineCues(...$content);

        $this->assertEquals([$screen[0], $screen[2]], [$lines[0], $lines[2]]);
        $this->assertSame([self::seconds(96), self::seconds(156), ["ROLL"]], self::describe($lines)[1]);
        $this->assertCount(3, $lines);
    }


    public function testRollUpLinesRowOnTheScreenAtTheEndOfTheDataLastsTheLastCueDuration(): void
    {
        $cues = $this->lineCues(
            "00:00:01:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("ONE"),
            "00:00:02:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("TWO")
        );

        $this->assertSame([[self::seconds(36), self::seconds(62), ["ONE"]], [self::seconds(66), round(self::seconds(66) + 5, 3), ["TWO"]]], self::describe($cues));
    }


    public function testRollUpWindowMovesToTheRowOfANewPac(): void
    {
        $cues = $this->cues(
            "00:00:01:00\t9426 9426 94ad 94ad 9470 9470 " . self::text("ONE"),
            "00:00:02:00\t9426 9426 94ad 94ad 9470 9470 " . self::text("TWO"),
            "00:00:03:00\t9426 9426 94ad 94ad 1370 1370 " . self::text("THREE")
        );

        $this->assertSame([14, 15], $cues[1]->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]);
        $this->assertSame([11, 12, 13], $cues[2]->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]);
        $this->assertSame(["ONE", "TWO", "THREE"], $cues[2]->getLines());
    }


    public function testRollUpCommandErasesAPopOnCaption(): void
    {
        $cues = $this->cues(
            "00:00:01:00\t9420 9420 9470 9470 " . self::text("POP") . " 942f 942f",
            "00:00:03:00\t9425 9425 94ad 94ad 9470 9470 " . self::text("ROLL")
        );

        $this->assertSame([["POP"], ["ROLL"]], [$cues[0]->getLines(), $cues[1]->getLines()]);
        $this->assertSame(round(90 * 1001 / 30000, 3), $cues[0]->getEnd());
    }


    public function testPaintOnShowsEachLineOfTheFileAsItArrives(): void
    {
        $cues = $this->cues(
            "00:00:01:00\t9429 9429 9470 9470 " . self::text("ONE"),
            "00:00:02:00\t" . self::text(" TWO"),
            "00:00:03:00\t942c 942c"
        );

        $this->assertSame([["ONE"], ["ONE TWO"]], [$cues[0]->getLines(), $cues[1]->getLines()]);
        $this->assertSame("paint-on", $cues[0]->findFormatData(SccParser::FORMAT_DATA_KEY)["mode"]);
        $this->assertSame([round(34 * 1001 / 30000, 3), round(60 * 1001 / 30000, 3)], [$cues[0]->getStart(), $cues[0]->getEnd()]);
    }


    public function testSecondDataChannelIsIgnoredByDefaultAndReadWithChannel2(): void
    {
        $content = "Scenarist_SCC V1.0\n\n00:00:01:00\t9420 9420 9470 9470 " . self::text("ONE") . " 1c20 1c20 1c70 1c70 "
                   . self::text("TWO") . " 1c2f 1c2f 942f 942f\n";

        $this->assertSame(["ONE"], Subtitle::fromString($content, Format::Scc)->getCues()[0]->getLines());
        $this->assertSame(["TWO"], (new SccParser())->parse($content, new ReadOptions(formats: [new SccReadOptions(channel: 2)]))->getCues()[0]->getLines());
    }


    public function testInvalidChannelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SccReadOptions(3);
    }


    public function testExtendedDataServicePacketsAndTextModeAreIgnored(): void
    {
        // 0105 starts an XDS packet and 8f.. ends it. 942a is Text Restart, so the text after it is not caption text.
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("A") . " 0105 " . self::text("XDS") . " 8f10 942a 942a "
                                        . self::text("TEXT") . " 9420 9420 " . self::text("B")))[0];

        $this->assertSame(["AB"], $cue->getLines());
    }


    public function testLinesAreDecodedInTimeOrder(): void
    {
        $cues = $this->cues(
            "00:00:03:00\t942c 942c",
            "00:00:01:00\t9420 9420 9470 9470 " . self::text("Hi") . " 942f 942f"
        );

        $this->assertSame([round(35 * 1001 / 30000, 3), round(90 * 1001 / 30000, 3)], [$cues[0]->getStart(), $cues[0]->getEnd()]);
    }


    public function testTextIsEscaped(): void
    {
        $cue = $this->cues($this->popOn("9470 9470 " . self::text("<a> & b")))[0];

        $this->assertSame(["&lt;a&gt; &amp; b"], $cue->getLines());
    }


    public function testNonDropFrameIsKeptInTheFormatData(): void
    {
        $subtitle = Subtitle::fromString("Scenarist_SCC V1.0\r\n\r\n00:00:01:00\t942c 942c\r\n", Format::Scc);

        $this->assertSame([[], ["dropFrame" => false]], [$subtitle->getCues(), $subtitle->findFormatData(SccParser::FORMAT_DATA_KEY)]);
    }


    public static function invalidFileProvider(): array
    {
        return [
            "no header"         => ["\n00:00:01:00\t942c 942c\n", 2],
            "other header"      => ["Scenarist_SCC V2.0\n\n00:00:01:00\t942c 942c\n", 1],
            "no time code"      => ["Scenarist_SCC V1.0\n\n942c 942c\n", 3],
            "short byte pair"   => ["Scenarist_SCC V1.0\n\n00:00:01:00\t942c 94c\n", 3],
            "byte pair not hex" => ["Scenarist_SCC V1.0\r\n\r\n00:00:01:00\t942c\n\n00:00:02:00\t94zz\n", 5],
        ];
    }


    #[DataProvider("invalidFileProvider")]
    public function testInvalidFileThrowsWithTheLineNumber(string $content, ?int $lineNumber): void
    {
        try {
            (new SccParser())->parse($content, new ReadOptions());
            $this->fail("No ParsingException");
        } catch (ParsingException $exception) {
            $this->assertSame($lineNumber, $exception->getLineNumber());
        }
    }
}
