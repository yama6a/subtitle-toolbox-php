<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class CsvFormatterTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";


    private static function english(): Subtitle
    {
        return (new Subtitle())
            ->addCue(new SubtitleCue(1, 4, "<v Anna>Where are you going?"))
            ->addCue(new SubtitleCue(4.5, 6, ["<v Ben><i>Home.</i>", "Now."]));
    }


    public function testWritesTheIssueExampleWithABom(): void
    {
        $this->assertSame(
            self::BOM . "start,end,speaker,text\n" .
            "00:00:01.000,00:00:04.000,Anna,Where are you going?\n" .
            "00:00:04.500,00:00:06.000,Ben,\"Home.\nNow.\"\n",
            self::english()->toString(Format::Csv)
        );
    }


    public function testQuotesCellsAndDecodesEntities(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Tom &amp; \"Ann\", <b>both</b>"));

        $this->assertSame(
            "start,end,text\n00:00:01.000,00:00:02.000,\"Tom & \"\"Ann\"\", both\"\n",
            $subtitle->toString(Format::Csv, new WriteOptions(bom: false))
        );
    }


    public function testWritesTheDelimiterAndTheLineEnding(): void
    {
        $this->assertSame(
            "start;end;speaker;text\r\n00:00:01.000;00:00:04.000;Anna;Where are you going?\r\n" .
            "00:00:04.500;00:00:06.000;Ben;\"Home.\nNow.\"\r\n",
            self::english()->toString(Format::Csv, new WriteOptions(lineEnding: LineEnding::Crlf, bom: false, formats: [new CsvWriteOptions(delimiter: ";")]))
        );
    }


    public static function timeFormats(): array
    {
        return [
            "seconds" => [CsvTimeFormat::Seconds, "62.48", "3599.99"],
            "dot"     => [CsvTimeFormat::Dot, "00:01:02.480", "00:59:59.990"],
            "comma"   => [CsvTimeFormat::Comma, "\"00:01:02,480\"", "\"00:59:59,990\""],
            "frames"  => [CsvTimeFormat::Frames, "00:01:02:12", "01:00:00:00"],
        ];
    }


    #[DataProvider("timeFormats")]
    public function testWritesTimeFormats(CsvTimeFormat $timeFormat, string $start, string $end): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(62.48, 3599.99, "a"));

        $this->assertSame("start,end,text\n$start,$end,a\n", $subtitle->toString(Format::Csv, new WriteOptions(bom: false, formats: [new CsvWriteOptions(timeFormat: $timeFormat, frameRate: 25)])));
    }


    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unknownStoredValues(): array
    {
        return [
            "time format" => ["timeFormat", "hh:mm:ss;fff", 'The field formatData.csv.timeFormat must be one of "seconds", "hh:mm:ss.mmm", "hh:mm:ss,mmm", "hh:mm:ss:ff".'],
            "delimiter"   => ["delimiter", "|", 'The field formatData.csv.delimiter must be one of ",", ";", "\t".'],
        ];
    }


    #[DataProvider("unknownStoredValues")]
    public function testSetFormatDataRejectsAnUnknownStoredValue(string $field, string $value, string $message): void
    {
        $subtitle = (new CsvParser())->parse("start,end,text\n1.5,2,a\n", new ReadOptions());

        try {
            $subtitle->setFormatData("csv", [$field => $value] + $subtitle->findFormatData("csv"));
            $this->fail("setFormatData() accepted the $field $value.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertSame("start,end,text\n1.5,2,a\n", $subtitle->toString(Format::Csv, new WriteOptions(bom: false)));
    }


    public function testFrameRateOptionWinsOverTheParsedFrameRate(): void
    {
        $file     = file_get_contents(__DIR__ . "/../files/csv/real/dubbing_script.csv");
        $subtitle = (new CsvParser())->parse($file, new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character"), frameRate: 25)]));

        $this->assertStringContainsString("\n10:00:05:19,BEN,- I have one.,\n",
                                          $subtitle->toString(Format::Csv, new WriteOptions(formats: [new CsvWriteOptions(frameRate: 24)])));
        $this->assertStringContainsString("\n10:00:05:20,BEN,- I have one.,\n", $subtitle->toString(Format::Csv));
    }


    public function testWritesIdentifiersAndOtherColumnsFromAnotherFormat(): void
    {
        $subtitle = Subtitle::fromStringAutoDetectFormat("WEBVTT\n\nintro\n00:00:01.000 --> 00:00:02.000\nHi\n");
        $subtitle->getCues()[0]->setFormatData("csv", ["columns" => ["Take" => "2"]]);

        $this->assertSame("identifier,start,end,text,Take\nintro,00:00:01.000,00:00:02.000,Hi,2\n", $subtitle->toString(Format::Csv, new WriteOptions(bom: false)));
    }


    public function testAddsASpeakerColumnToAParsedTableWithoutOne(): void
    {
        $subtitle = (new CsvParser())->parse("Start;Text\n1;a\n", new ReadOptions());
        $subtitle->getCues()[0]->setLines("<v Lena>a");

        $this->assertSame("Start;speaker;Text\n1;Lena;a\n", $subtitle->toString(Format::Csv, new WriteOptions(bom: false)));
    }


    public function testWritesATableWithoutAHeaderRowBack(): void
    {
        $content  = "a\t1\t2\textra\nb\t3\t4\t\n";
        $subtitle = (new CsvParser())->parse($content, new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: 1, end: 2, text: 0, header: false))]));

        $this->assertSame($content, $subtitle->toString(Format::Csv, new WriteOptions(bom: false)));
    }


    public function testKeepsAHeaderColumnThatNoRowFills(): void
    {
        $content = "start,end,text,Notes\n1,2,a\n";

        $this->assertSame("start,end,text,Notes\n1,2,a,\n", Subtitle::fromString($content, Format::Csv)->toString(Format::Csv, new WriteOptions(bom: false)));
    }


    public function testWritesABilingualTable(): void
    {
        $german = Subtitle::fromString(
            "1\n00:00:01,200 --> 00:00:03,900\nWohin gehst du?\n\n" .
            "2\n00:00:04,400 --> 00:00:09,000\nNach Hause. Jetzt.\n",
            Format::SubRip);
        $english = self::english()->addCue(new SubtitleCue(6.5, 8, "Right now."));

        $this->assertSame(
            "start,end,speaker,text,text (de)\n" .
            "00:00:01.000,00:00:04.000,Anna,Where are you going?,Wohin gehst du?\n" .
            "00:00:04.500,00:00:06.000,Ben,\"Home.\nNow.\",Nach Hause. Jetzt.\n" .
            "00:00:06.500,00:00:08.000,,Right now.,\n",
            $english->toString(Format::Csv, new WriteOptions(bom: false, formats: [new CsvWriteOptions(secondText: $german, secondTextHeader: "text (de)")]))
        );
    }


    public function testASecondCueOverRowsFillsTheFirstRowItOverlapsMost(): void
    {
        $primary = (new Subtitle())->addCue(new SubtitleCue(0, 2, "a"))->addCue(new SubtitleCue(2, 6, "b"));
        $second  = (new Subtitle())->addCue(new SubtitleCue(1, 6, "x"));

        $this->assertSame(
            "start,end,text,text2\n0,2,a,x\n2,6,b,\n",
            $primary->toString(Format::Csv, new WriteOptions(bom: false, formats: [new CsvWriteOptions(secondText: $second, timeFormat: CsvTimeFormat::Seconds)]))
        );
    }


    public function testEscapesFormulasOnlyWhenAsked(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["- Hi.", "- Hello."]))
            ->addCue(new SubtitleCue(3, 4, "=SUM(A1)"))
            ->addCue(new SubtitleCue(5, 6, "+1 @home"));
        $options = new WriteOptions(bom: false, formats: [new CsvWriteOptions(timeFormat: CsvTimeFormat::Seconds)]);

        $this->assertSame(
            "start,end,text\n1,2,\"- Hi.\n- Hello.\"\n3,4,=SUM(A1)\n5,6,+1 @home\n",
            $subtitle->toString(Format::Csv, $options)
        );
        $this->assertSame(
            "start,end,text\n1,2,\"'- Hi.\n- Hello.\"\n3,4,'=SUM(A1)\n5,6,'+1 @home\n",
            $subtitle->toString(Format::Csv, new WriteOptions(bom: false, formats: [new CsvWriteOptions(timeFormat: CsvTimeFormat::Seconds, escapeFormulas: true)]))
        );
    }


    public function testRoundTripsThroughTheParser(): void
    {
        $csv = self::english()->toString(Format::Csv);

        $this->assertSame($csv, Subtitle::fromString($csv, Format::Csv)->toString(Format::Csv));
    }


    public function testRejectsAnInvalidDelimiterWhenTheOptionsAreCreated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CsvWriteOptions(delimiter: "|");
    }


    public function testFramesWithoutAFrameRateThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The time format hh:mm:ss:ff needs a frame rate. Set CsvWriteOptions::\$frameRate.");

        self::english()->toString(Format::Csv, new WriteOptions(formats: [new CsvWriteOptions(timeFormat: CsvTimeFormat::Frames)]));
    }
}
