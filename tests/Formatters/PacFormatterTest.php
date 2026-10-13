<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class PacFormatterTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/pac/real/";


    public function testWritesANewFileAsSubtitleEditDoes(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1.0, 3.48, ["Fresh rolls are <i>warm</i> today.", "<i>Café crème</i>"]));

        $this->assertSame(
            "01" . str_repeat("00", 19) . "000000600000640000003801" . "2f00" . "0a" .
            "fe0203" . bin2hex("Fresh rolls are<warm>today.") . "fe02033c436166e265206372e3656d65" .
            "ff" . str_repeat("00", 11) . "1100" . bin2hex("dummy end of file"),
            bin2hex($subtitle->toString(Format::Pac))
        );
    }


    public function testWritesPacUnicodeForTextThatLatinCannotHold(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["Ναι, 東京."]));

        $output = $subtitle->toString(Format::Pac);

        $this->assertStringContainsString("\x80\x80\x80\xFE\x02\x03\x1F\xEF\xBB\xBF" . "Ναι, 東京\xFF.", $output);
        $this->assertSame("unicode", Subtitle::fromString($output)->findFormatData("pac")["codePage"]);
        $this->assertSame("Ναι, 東京.", Subtitle::fromString($output)->getCues()[0]->getText());
    }


    public static function alignments(): array
    {
        return [
            "bottom, 2 lines" => [Alignment::BottomCenter, 2, 10, 2],
            "bottom left"     => [Alignment::BottomLeft, 1, 11, 1],
            "middle right"    => [Alignment::MiddleRight, 1, 6, 0],
            "top"             => [Alignment::TopCenter, 3, 1, 2],
        ];
    }


    #[DataProvider("alignments")]
    public function testAlignmentGivesTheRowAndTheAlignmentByte(Alignment $alignment, int $lines, int $row, int $alignmentByte): void
    {
        $cue    = (new SubtitleCue(1, 2, array_fill(0, $lines, "Text")))->setAlignment($alignment);
        $output = (new Subtitle())->addCue($cue)->toString(Format::Pac);

        $this->assertSame([$row, $alignmentByte], [ord($output[34]), ord($output[36])]);
        $this->assertSame($alignment, Subtitle::fromString($output)->getCues()[0]->getAlignment());
    }


    public function testChangedTextKeepsTheCodePageOfTheFile(): void
    {
        $subtitle = Subtitle::load(self::FILES . "weather_greek.pac");
        $subtitle->getCues()[0]->setLines("Καλημέρα, 東京.");

        $reread = Subtitle::fromString($subtitle->toString(Format::Pac));

        $this->assertSame("Καλημέρα, ??.", $reread->getCues()[0]->getText());
        $this->assertSame("Ο άνεμος είναι ασθενής.", $reread->getCues()[1]->getText());
    }


    public function testStripTagsWritesNoItalics(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["<i>Hello</i> <b>there</b> <3"]));

        $this->assertStringContainsString("\xFE\x02\x03Hello there ?3\xFF", $subtitle->toString(Format::Pac, new WriteOptions(stripTags: true)));
    }


    public function testClampsHoursToTheTimeCodeRange(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(700 * 3600, 700 * 3600 + 1, ["Late"]))->toString(Format::Pac);

        $this->assertSame([654 * 100, 654 * 100], array_values(unpack("v2", substr($output, 24, 2) . substr($output, 28, 2))));
    }


    public function testSubtitleNumberOver65535Throws(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["One"]))->addCue(new SubtitleCue(3, 4, ["Two"]));
        $subtitle->setFormatData("pac", ["firstNumber" => 65535]);

        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("PAC allows subtitle numbers up to 65535.");

        $subtitle->toString(Format::Pac);
    }
}
