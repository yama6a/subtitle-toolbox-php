<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class NegativeCueTimesTest extends TestCase
{
    public function testSubRipWritesANegativeStartAsZero(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(-0.5, 1, "Hi"));

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/real/own_negative_start.srt"),
            $subtitle->toString(Format::SubRip, new WriteOptions(bom: false))
        );
    }


    #[DataProvider("formatProvider")]
    public function testANegativeTimeIsWrittenAsZero(Format $format, ?FormatWriteOptions $formatOptions = null): void
    {
        $options  = new WriteOptions(formats: $formatOptions === null ? [] : [$formatOptions]);
        $negative = (new Subtitle())->addCues([new SubtitleCue(-2.5, -1.5, "One"), new SubtitleCue(-0.5, 1, "Two")]);
        $zero     = (new Subtitle())->addCues([new SubtitleCue(0, 0, "One"), new SubtitleCue(0, 1, "Two")]);
        // A new DCP file holds the time of the write in IssueDate, so two writes in different seconds would differ.
        $negative->setFormatData("dcp", ["header" => ["IssueDate" => "2026-10-12T00:00:00"]]);
        $zero->setFormatData("dcp", ["header" => ["IssueDate" => "2026-10-12T00:00:00"]]);

        $this->assertSame($zero->toString($format, $options), $negative->toString($format, $options));
    }


    public static function formatProvider(): array
    {
        $formats = [];
        foreach (Format::cases() as $format) {
            if ($format->canWrite() && !in_array($format, [Format::Json, Format::Pgs], true)) {
                $formats[$format->value] = [$format];
            }
        }
        $formats["itt"]          = [Format::Itt, new IttWriteOptions(frameRate: 25)];
        $formats["microdvd"]     = [Format::MicroDvd, new MicroDvdWriteOptions(frameRate: 25)];
        $formats["mpsub frames"] = [Format::MpSub, new MpSubWriteOptions(frameRate: 25)];
        $formats["csv frames"]   = [Format::Csv, new CsvWriteOptions(timeFormat: CsvTimeFormat::Frames, frameRate: 25)];

        return $formats;
    }
}
