<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class CsvParserTest extends TestCase
{
    private function describe(string $csv, ReadOptions $options = new ReadOptions()): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()],
            array_values((new CsvParser())->parse($csv, $options)->getCues())
        );
    }


    public function testReadsTheIssueExample(): void
    {
        $csv = "start,end,speaker,text\n" .
               "00:00:01.000,00:00:04.000,Anna,\"Where are you going?\"\n" .
               "00:00:04.500,00:00:06.000,Ben,\"Home.\nNow.\"\n";

        $this->assertSame([
            [1.0, 4.0, ["<v Anna>Where are you going?"]],
            [4.5, 6.0, ["<v Ben>Home.", "Now."]],
        ], $this->describe($csv));
    }


    public static function delimiters(): array
    {
        return [
            "comma"     => [","],
            "semicolon" => [";"],
            "tab"       => ["\t"],
        ];
    }


    #[DataProvider("delimiters")]
    public function testDetectsTheDelimiterFromTheHeaderLine(string $delimiter): void
    {
        $csv = implode($delimiter, ["Start", "End", "Text"]) . "\n" . implode($delimiter, ["1", "2", "\"a, b; c\""]) . "\n";

        $this->assertSame($delimiter, (new CsvParser())->parse($csv, new ReadOptions())->findFormatData(CsvParser::FORMAT_DATA_KEY)["delimiter"]);
        $this->assertSame([[1.0, 2.0, ["a, b; c"]]], $this->describe($csv));
    }


    public function testTheDelimiterArgumentWinsOverDetection(): void
    {
        $this->assertSame([[1.0, 2.0, ["a,b"]]], $this->describe("start;end;text\n1;2;a,b\n", new ReadOptions(formats: [new CsvReadOptions(delimiter: ";")])));
        $this->assertSame([[1.0, 2.0, ["x;y"]]], $this->describe("start,end,text;more\n1,2,x;y\n", new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(text: "text;more"), ",")])));
    }


    public function testReadsRfc4180Quoting(): void
    {
        $csv = "start,end,text\r\n1,2,\"She said \"\"hi\"\",\r\nthen left.\"\r\n3,4,plain \"quote\"\r\n";

        $this->assertSame([
            [1.0, 2.0, ["She said \"hi\",", "then left."]],
            [3.0, 4.0, ["plain \"quote\""]],
        ], $this->describe($csv));
    }


    public function testEscapesMarkupCharacters(): void
    {
        $this->assertSame([[1.0, 2.0, ["&lt;i&gt;Tom &amp; Ann&lt;/i&gt;"]]], $this->describe("start,end,text\n1,2,<i>Tom & Ann</i>\n"));
    }


    public static function times(): array
    {
        return [
            "seconds"           => ["62.5", 62.5],
            "whole seconds"     => ["62", 62.0],
            "dot"               => ["00:01:02.500", 62.5],
            "comma"             => ["\"00:01:02,500\"", 62.5],
            "one hour digit"    => ["1:00:00.25", 3600.25],
            "frames at 25 fps"  => ["00:01:02:12", 62.48],
            "minutes and seconds"        => ["0:06", 6.0],
            "one second digit"           => ["00:00:1", 1.0],
            "minutes seconds fraction"   => ["01:02.500", 62.5],
            "minutes seconds comma"      => ["\"01:02,500\"", 62.5],
            "full time with fraction"    => ["00:00:01.500", 1.5],
        ];
    }


    #[DataProvider("times")]
    public function testReadsTimeFormats(string $time, float $seconds): void
    {
        $cues = $this->describe("start,text\n$time,a\n", new ReadOptions(formats: [new CsvReadOptions(frameRate: 25)]));

        $this->assertSame($seconds, $cues[0][0]);
    }


    public function testFramesUseTheFrameRateOfTheReadOptions(): void
    {
        $subtitle = (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_frame_times.csv"), new ReadOptions(formats: [new CsvReadOptions(frameRate: 25)]));

        $this->assertSame(1.48, $subtitle->getCues()[0]->getStart());
    }


    public function testFramesNeedAFrameRate(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"00:00:01:12\" counts frames. Set CsvReadOptions::\$frameRate. (line 2)");

        (new CsvParser())->parse("start,end,text\n00:00:01:12,00:00:02:00,a\n", new ReadOptions());
    }


    public function testTimeFormatOfTheNewShapesIsDot(): void
    {
        $subtitle = (new CsvParser())->parse("start,text\n0:06,a\n", new ReadOptions());

        $this->assertSame("hh:mm:ss.mmm", $subtitle->findFormatData("csv")["timeFormat"]);
    }


    public function testABadTimeNamesTheLine(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("(line 4)");

        (new CsvParser())->parse("start,end,text\n1,2,\"a\nb\"\nsoon,3,c\n", new ReadOptions());
    }


    public function testLenientModeSkipsABadRow(): void
    {
        $subtitle = (new CsvParser())->parse("start,end,text\nsoon,2,a\n3,4,b\n", new ReadOptions(lenient: true));

        $this->assertSame([[3.0, 4.0, ["b"]]], $this->describe("start,end,text\nsoon,2,a\n3,4,b\n", new ReadOptions(lenient: true)));
        $this->assertCount(1, $subtitle->getParseWarnings());
        $this->assertSame(2, $subtitle->getParseWarnings()[0]->lineNumber);
        $this->assertSame(ParseWarningAction::Skipped, $subtitle->getParseWarnings()[0]->action);
    }


    public function testLenientModeEndsAnUnclosedQuoteAtTheLineEnd(): void
    {
        $subtitle = (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_unclosed_quote.csv"), new ReadOptions(lenient: true));
        $warnings = $subtitle->getParseWarnings();

        $this->assertSame([
            [1.0, 2.5, ["The lamp is lit."]],
            [3.0, 4.5, ["Close the shutters."]],
            [5.0, 6.0, ["Good night."]],
        ], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], array_values($subtitle->getCues())));
        $this->assertCount(1, $warnings);
        $this->assertSame("A quoted CSV cell has no closing quote. The cell ends at the end of the line.", $warnings[0]->message);
        $this->assertSame(2, $warnings[0]->lineNumber);
        $this->assertSame(["1.0,2.5,\"The lamp is lit."], $warnings[0]->block);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
    }


    public function testStrictModeThrowsForAnUnclosedQuoteInAFixture(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A quoted CSV cell has no closing quote. (line 2)");

        (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_unclosed_quote.csv"), new ReadOptions());
    }


    public function testLenientModeSkipsRowsBeforeTheHeader(): void
    {
        $subtitle = (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_junk_before_header.csv"), new ReadOptions(lenient: true));
        $warnings = $subtitle->getParseWarnings();

        $this->assertSame([
            [1.0, 3.0, ["The ferry leaves at noon."]],
            [4.0, 6.5, ["Then we wait, Ana; I bring the map."]],
        ], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], array_values($subtitle->getCues())));
        $this->assertSame(";", $subtitle->findFormatData("csv")["delimiter"]);
        $this->assertSame(["start", "end", "text"], $subtitle->findFormatData("csv")["header"]);
        $this->assertCount(1, $warnings);
        $this->assertSame("The table has 2 rows before the header row.", $warnings[0]->message);
        $this->assertSame(1, $warnings[0]->lineNumber);
        $this->assertSame(["Exported by Subtitle Desk 4.2", "Project;Harbor Lights;Reel 2;"], $warnings[0]->block);
        $this->assertSame(ParseWarningAction::Skipped, $warnings[0]->action);
    }


    public function testStrictModeThrowsForRowsBeforeTheHeader(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"start\" for start.");

        (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_junk_before_header.csv"), new ReadOptions());
    }


    public function testLenientModeKeepsTheMissingColumnErrorWithoutAHeaderRow(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"start\" for start. (line 1)");

        (new CsvParser())->parse("note\nstart,end\n1,2\n", new ReadOptions(lenient: true));
    }


    public function testWithoutEndACueEndsAtTheNextStart(): void
    {
        $this->assertSame([
            [1.0, 4.0, ["b"]],
            [1.0, 4.0, ["a"]],
            [4.0, 6.0, ["c"]],
        ], $this->describe("text,start\nb,1\na,1\nc,4\n", new ReadOptions(lastCueDuration: 2)));
    }


    public function testAnEmptyEndCellEndsAtTheNextStart(): void
    {
        $this->assertSame([[1.0, 3.0, ["a"]], [3.0, 5.0, ["b"]]], $this->describe("start,end,text\n1,,a\n3,5,b\n"));
    }


    public function testReadsADurationColumn(): void
    {
        $this->assertSame([[1.5, 3.5, ["a"]]], $this->describe("Start,Duration,Text\n1.5,2,a\n"));
    }


    public function testReadsWholeNumberTimesWithLongCuesAsMilliseconds(): void
    {
        $subtitle = (new CsvParser())->parse("start\tend\ttext\n0\t2340\tFirst line\n2340\t5100\tSecond line\n", new ReadOptions());
        $warnings = $subtitle->getParseWarnings();

        $this->assertSame([[0.0, 2.34, ["First line"]], [2.34, 5.1, ["Second line"]]], $this->describe("start\tend\ttext\n0\t2340\tFirst line\n2340\t5100\tSecond line\n"));
        $this->assertSame("milliseconds", $subtitle->findFormatData("csv")["timeFormat"]);
        $this->assertCount(1, $warnings);
        $this->assertSame("The times are whole numbers, and the median cue lasts 2550 seconds. The parser reads the times as milliseconds.", $warnings[0]->message);
        $this->assertSame(2, $warnings[0]->lineNumber);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
    }


    public function testReadsAWholeNumberDurationColumnWithLongCuesAsMilliseconds(): void
    {
        $this->assertSame([[1.5, 3.5, ["a"]], [3.5, 4.0, ["b"]], [4.0, 6.25, ["c"]]], $this->describe("start,duration,text\n1500,2000,a\n3500,500,b\n4000,2250,c\n"));
    }


    public function testReadsWholeSecondTimesWithShortCuesAsSeconds(): void
    {
        $subtitle = (new CsvParser())->parse("start,end,text\n1,4,a\n5,99,b\n100,104,c\n", new ReadOptions());

        $this->assertSame([[1.0, 4.0, ["a"]], [5.0, 99.0, ["b"]], [100.0, 104.0, ["c"]]], $this->describe("start,end,text\n1,4,a\n5,99,b\n100,104,c\n"));
        $this->assertSame("seconds", $subtitle->findFormatData("csv")["timeFormat"]);
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testReadsLongCuesAsSecondsWhenOneTimeHasAFraction(): void
    {
        $this->assertSame([[0.0, 2340.0, ["a"]], [2340.0, 5100.5, ["b"]]], $this->describe("start,end,text\n0,2340,a\n2340,5100.5,b\n"));
    }


    public function testFindsColumnsByHeaderSynonyms(): void
    {
        $subtitle = (new CsvParser())->parse("Start Time,End Time,Subtitle\n1,2.5,a\n", new ReadOptions());
        $warnings = $subtitle->getParseWarnings();

        $this->assertSame([[1.0, 2.5, ["a"]]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], array_values($subtitle->getCues())));
        $this->assertCount(1, $warnings);
        $this->assertSame("The parser reads the columns \"Start Time\" as start, \"End Time\" as end and \"Subtitle\" as text.", $warnings[0]->message);
        $this->assertSame(1, $warnings[0]->lineNumber);
        $this->assertNull($warnings[0]->blockIndex);
        $this->assertSame(["Start Time,End Time,Subtitle"], $warnings[0]->block);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
    }


    public function testFindsTheDubbingScriptColumnsWithoutColumnOptions(): void
    {
        $subtitle = (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/real/dubbing_script.csv"), new ReadOptions(formats: [new CsvReadOptions(frameRate: 25)]));
        $cue      = $subtitle->getCues()[0];

        $this->assertSame(36001.48, $cue->getStart());
        $this->assertSame(["<v NARRATOR>The weather turns cold tonight."], $cue->getLines());
        $this->assertSame(["start" => 0, "speaker" => 1, "text" => 2], $subtitle->findFormatData("csv")["roles"]);
        $this->assertSame(["The parser reads the columns \"Start TC\" as start and \"Character\" as speaker."], array_map(fn ($warning): string => $warning->message, $subtitle->getParseWarnings()));
    }


    public function testAnExactHeaderNameWinsOverASynonym(): void
    {
        $subtitle = (new CsvParser())->parse("Begin,Start,Text\n9,1,a\n", new ReadOptions());

        $this->assertSame(1.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testAStartAndTextTableKeepsAnOutColumnAsData(): void
    {
        $subtitle = (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_role_names_out_column.csv"), new ReadOptions());
        $cues     = array_values($subtitle->getCues());

        $this->assertSame([[1.0, 3.0, ["Hello"]], [3.0, 8.0, ["World"]]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues));
        $this->assertSame([["out" => "yes"], ["out" => "no"]], array_map(fn (SubtitleCue $cue): array => $cue->findFormatData("csv")["columns"], $cues));
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testAStartAndTextTableReadsNoSpeakerFromANameColumn(): void
    {
        $subtitle = (new CsvParser())->parse(file_get_contents(__DIR__ . "/../files/csv/own_role_names_name_column.csv"), new ReadOptions());

        $this->assertSame(["Hello"], $subtitle->getCues()[0]->getLines());
        $this->assertSame(["start" => 0, "end" => 1, "text" => 2], $subtitle->findFormatData("csv")["roles"]);
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testAStartAndTextTableKeepsALengthColumnInARoundTrip(): void
    {
        $content  = file_get_contents(__DIR__ . "/../files/csv/own_role_names_length_column.csv");
        $subtitle = Subtitle::fromString($content, Format::Csv);

        $this->assertSame($content, $subtitle->toString(Format::Csv, new WriteOptions(bom: false)));
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testColumnOptionsTurnOffHeaderSynonyms(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"start\" for start.");

        (new CsvParser())->parse("Start Time,Stop,Subtitle\n1,2,a\n", new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(end: "Stop"))]));
    }


    public function testMapsColumnsByIndexWithoutAHeader(): void
    {
        $options = new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: 1, end: 2, text: 0, header: false))]);

        $this->assertSame([[1.0, 2.0, ["a"]], [3.0, 4.0, ["b"]]], $this->describe("a,1,2\nb,3,4\n", $options));
    }


    public function testKeepsOtherColumnsAndIdentifiers(): void
    {
        $subtitle = (new CsvParser())->parse("ID,Start,End,Text,Take\nshot-1,1,2,a,3\n\n", new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(identifier: "id"))]));
        $cue      = $subtitle->getCues()[0];

        $this->assertSame("shot-1", $cue->getIdentifier());
        $this->assertSame(["columns" => ["Take" => "3"]], $cue->findFormatData("csv"));
        $this->assertSame([
            "delimiter"  => ",",
            "header"     => ["ID", "Start", "End", "Text", "Take"],
            "roles"      => ["identifier" => 0, "start" => 1, "end" => 2, "text" => 3],
            "width"      => 5,
            "timeFormat" => CsvTimeFormat::Seconds->value,
            "frameRate"  => null,
        ], $subtitle->findFormatData("csv"));
    }


    public function testIdentifierHeaderMapsToTheCueIdentifier(): void
    {
        $this->assertSame("intro", (new CsvParser())->parse("identifier,start,text\nintro,1,a\n", new ReadOptions())->getCues()[0]->getIdentifier());
    }


    public function testSkipsEmptyRowsAndReadsAUtf8Bom(): void
    {
        $this->assertSame([[1.0, 2.0, ["a"]]], $this->describe("\xEF\xBB\xBFstart,end,text\n\n,,\n1,2,a"));
    }


    public function testAMissingColumnThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"Character\" for speaker.");

        (new CsvParser())->parse("start,end,text\n1,2,a\n", new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(speaker: "Character"))]));
    }


    public function testATableWithoutTextThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"text\" for text.");

        (new CsvParser())->parse("start,end\n1,2\n", new ReadOptions());
    }


    public function testAnOpenQuoteThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A quoted CSV cell has no closing quote. (line 2)");

        (new CsvParser())->parse("start,end,text\n1,2,\"open\n", new ReadOptions());
    }


    public function testATableWithMoreThan1000ColumnsThrowsAParsingException(): void
    {
        $columns = array_map(fn (int $index): string => "c$index", range(1, 997));
        $csv     = "start,end,text," . implode(",", $columns) . "\n1,2,a\n3,4,b," . implode(",", $columns) . ",extra\n";

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has 1001 columns. The limit is 1000. (line 3)");

        (new CsvParser())->parse($csv, new ReadOptions(lenient: true));
    }


    public function testATableWith1000ColumnsParses(): void
    {
        $csv = "start,end,text" . str_repeat(",", 997) . "\n1,2,a\n";

        $this->assertSame(1000, (new CsvParser())->parse($csv, new ReadOptions())->findFormatData("csv")["width"]);
    }


    public function testRejectsAnUnknownDelimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CsvReadOptions(delimiter: "|");
    }


    public function testColumnsWithoutAHeaderNeedIndexes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A table without a header row needs a column index for text.");

        new CsvColumns(start: 0, header: false);
    }
}
