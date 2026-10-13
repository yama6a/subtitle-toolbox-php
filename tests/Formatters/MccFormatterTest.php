<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MccWriteOptions;
use SubtitleToolbox\Parsers\Mcc;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class MccFormatterTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";


    public static function frameRates(): array
    {
        return [
            "23.976"         => [23.976, null, "24", 1, 25],
            "24"             => [24.0, null, "24", 2, 25],
            "25"             => [25.0, null, "25", 3, 24],
            "29.97 DF"       => [29.97, null, "30DF", 4, 20],
            "29.97 non-drop" => [29.97, false, "30", 4, 20],
            "30"             => [30.0, null, "30", 5, 20],
            "50"             => [50.0, null, "50", 6, 12],
            "59.94 DF"       => [59.94, null, "60DF", 7, 10],
            "60"             => [60.0, null, "60", 8, 10],
        ];
    }


    #[DataProvider("frameRates")]
    public function testWritesEachFrameRateAndReadsTheCuesBack(float $frameRate, ?bool $dropFrame, string $timeCodeRate, int $code, int $triplets): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "srt/real/own_styled.srt"), Format::SubRip)->wrapLines(32, 4);
        $scc      = Subtitle::fromString($subtitle->toString(Format::Scc), Format::Scc);

        $output = $subtitle->toString(Format::Mcc, new WriteOptions(formats: [new MccWriteOptions(frameRate: $frameRate, dropFrame: $dropFrame)]));
        $reread = Subtitle::fromString($output);

        $this->assertStringContainsString("\nTime Code Rate=$timeCodeRate\n", $output);
        preg_match('/^\d\d:\d\d:\d\d:\d\d\t(\S+)$/m', $output, $line);
        $packet = Mcc::readPacket(Mcc::decodeHex($line[1], 1), 1);
        $this->assertSame([$code, $triplets], [$packet[0], count($packet[1])]);
        foreach ($scc->getCues() as $index => $cue) {
            $this->assertSame($cue->getLines(), $reread->getCues()[$index]->getLines());
            $this->assertEqualsWithDelta($cue->getStart(), $reread->getCues()[$index]->getStart(), 0.04);
        }
        $this->assertCount(count($scc->getCues()), $reread->getCues());
    }


    public function testWritesTheChecksumsOfTheAncPacketAndTheCdp(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, "BUS 7"))->toString(Format::Mcc);

        preg_match_all('/^\d\d:\d\d:\d\d:\d\d\t(\S+)$/m', $output, $lines);
        foreach ($lines[1] as $line) {
            $anc = Mcc::decodeHex($line, 1);
            $this->assertSame(0, array_sum(unpack("C*", substr($anc, 3, -1))) & 0xFF, $line);
            $this->assertSame(ord($anc[-1]), array_sum(unpack("C*", substr($anc, 0, -1))) & 0xFF, $line);
        }
        $this->assertNotSame([], $lines[1]);
    }


    public function testKeepsTheVersionTheFrameRateAndTheHeaderOfTheInput(): void
    {
        $input  = file_get_contents(self::FILES . "mcc/real/v1_23976_timecode.mcc");
        $output = Subtitle::fromString($input)->toString(Format::Mcc);

        $this->assertStringStartsWith("File Format=MacCaption_MCC V1.0\n", $output);
        $this->assertStringContainsString("UUID=0A1B2C3D-4E5F-4061-8273-8495A6B7C8D9\nCreation Program=Subtitle Toolbox fixture generator\n" .
                                          "Creation Date=Sunday, October 11, 2026\nCreation Time=17:40:22\nTime Code Rate=24\n\n", $output);
        $this->assertSame(Subtitle::fromString($input)->findFormatData("mcc"), Subtitle::fromString($output)->findFormatData("mcc"));
    }


    public function testWritesANewFileAsVersion2At2997FpsDropFrame(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(61, 62, "BUS 7"));

        $output = $subtitle->toString(Format::Mcc);

        $this->assertStringStartsWith("File Format=MacCaption_MCC V2.0\n", $output);
        $this->assertMatchesRegularExpression('/\nUUID=[0-9A-F]{8}-[0-9A-F]{4}-5[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}\n' .
                                              'Creation Program=Subtitle Toolbox\nCreation Date=\w+day, \w+ \d+, \d{4}\nTime Code Rate=30DF\n\n00:01:00:/', $output);
        $this->assertSame($output, $subtitle->toString(Format::Mcc));
    }


    public function testWritesVersion2For5994FpsDropFrame(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "mcc/real/ffmpeg_painton_v1.mcc"));

        $output = $subtitle->toString(Format::Mcc, new WriteOptions(formats: [new MccWriteOptions(frameRate: 59.94, dropFrame: true)]));

        $this->assertStringStartsWith("File Format=MacCaption_MCC V2.0\n", $output);
        $this->assertStringContainsString("\nTime Code Rate=60DF\n", $output);
    }


    public function testThrowsForTextThatCea608CannotShow(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #0 at 1 s has the character \"日\", which CEA-608 cannot show.");

        (new Subtitle())->addCue(new SubtitleCue(1, 2, "日"))->toString(Format::Mcc);
    }


    public function testWritesOnlyTheHeaderForASubtitleWithoutCues(): void
    {
        $this->assertStringEndsWith("Time Code Rate=30DF\n\n", (new Subtitle())->toString(Format::Mcc));
    }


    public function testOptionsRejectAFrameRateThatMccLacks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The MCC formatter accepts the frame rates 23.976, 24, 25, 29.97, 30, 50, 59.94 and 60, got 48.");

        new MccWriteOptions(frameRate: 48);
    }
}
