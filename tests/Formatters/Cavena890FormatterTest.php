<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Cavena890;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class Cavena890FormatterTest extends TestCase
{
    public function testWritesANewFileAsSubtitleEditDoes(): void
    {
        $subtitle = (new Subtitle())->addCue((new SubtitleCue(1, 4, ["<i>Grüße</i> [x]"]))->setAlignment(Alignment::BottomLeft));

        $output = $subtitle->toString(Format::Cavena890);

        $this->assertSame(Cavena890::HEADER_SIZE + Cavena890::BLOCK_SIZE - 4, strlen($output));
        $this->assertSame([1, 1, 1], array_map(fn (int $offset): int => unpack("v", $output, $offset)[1], Cavena890::COUNTS));
        $this->assertSame("00100000190000645000000000000000", bin2hex(substr($output, Cavena890::HEADER_SIZE, 16)));
        $this->assertSame("8847728675df6598" . "20e578e6" . str_repeat("7f", 39), bin2hex(substr($output, Cavena890::HEADER_SIZE + Cavena890::LINE_2, 51)));
    }


    public function testWritesMoreThan2LinesInBlocksWithTheSameNumber(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["One", "Two", "Three"]))->addCue(new SubtitleCue(3, 4, ["Four"]));

        $output = $subtitle->toString(Format::Cavena890);
        $blocks = str_split(substr($output, Cavena890::HEADER_SIZE), Cavena890::BLOCK_SIZE);

        $this->assertSame([16, 16, 32], array_map(fn (string $block): int => unpack("n", $block)[1], $blocks));
        $this->assertSame(["One\nTwo\nThree", "Four"], array_map(fn ($cue): string => $cue->getText(), Subtitle::fromString($output, Format::Cavena890)->getCues()));
    }


    public function testLineOver51BytesThrows(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cavena 890 holds 51 bytes per line, got 52");

        (new Subtitle())->addCue(new SubtitleCue(1, 2, [str_repeat("a", 52)]))->toString(Format::Cavena890);
    }


    public function testChangedTextKeepsTheVariantOfTheFile(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/cavena890/real/train_russian.890");
        $subtitle->getCues()[0]->setLines("Да, Ivan.");

        $this->assertSame("Да, ????.", Subtitle::fromString($subtitle->toString(Format::Cavena890), Format::Cavena890)->getCues()[0]->getText());
    }
}
