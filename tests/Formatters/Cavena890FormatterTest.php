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


    public function testNumbersNewCuesBy16Then1SoEachCueReadsBack(): void
    {
        $cues = [];
        for ($i = 0; $i < 4110; $i++) {
            $cues[] = new SubtitleCue($i * 2, $i * 2 + 1, "Line $i");
        }

        $output = (new Subtitle())->addCues($cues)->toString(Format::Cavena890);
        $blocks = str_split(substr($output, Cavena890::HEADER_SIZE), Cavena890::BLOCK_SIZE);

        $this->assertSame([16, 65520, 65521, 65535], array_map(fn (int $index): int => unpack("n", $blocks[$index])[1], [0, 4094, 4095, 4109]));
        $this->assertCount(4110, Subtitle::fromString($output, Format::Cavena890)->getCues());
    }


    public function testMoreThan4110NewCuesThrow(): void
    {
        $cues = [];
        for ($i = 0; $i < 4111; $i++) {
            $cues[] = new SubtitleCue($i * 2, $i * 2 + 1, "Line $i");
        }

        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cavena 890 allows subtitle numbers up to 65535, so a new file holds up to 4110 cues.");

        (new Subtitle())->addCues($cues)->toString(Format::Cavena890);
    }


    public function testFinalCWritesItsAsciiByte(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1.0, 4.0, "BBC"))->toString(Format::Cavena890);

        $this->assertSame("4242437f7f", bin2hex(substr($output, Cavena890::HEADER_SIZE + Cavena890::LINE_2, 5)));
        $this->assertSame("4040407f", bin2hex(substr((new Subtitle())->addCue(new SubtitleCue(1.0, 4.0, "@@@"))->toString(Format::Cavena890),
                                                       Cavena890::HEADER_SIZE + Cavena890::LINE_2, 4)));
    }


    public function testLineOf51CharactersThatEndsInCFits(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1.0, 4.0, str_repeat("a", 50) . "C"))->toString(Format::Cavena890);

        $this->assertSame(str_repeat("a", 50) . "C", Subtitle::fromString($output, Format::Cavena890)->getCues()[0]->getText());
    }


    public function testCharacterAboveUffffReadsBack(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/cavena890/real/noodle_chinese.890");
        $subtitle->getCues()[0]->setLines(["吉野家𠮷野家"]);

        $this->assertSame(["吉野家𠮷野家"], Subtitle::fromString($subtitle->toString(Format::Cavena890), Format::Cavena890)->getCues()[0]->getLines());
    }


    public function testChineseCharacterWithTheLowByte7fAtTheEndOfALineReadsBack(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/cavena890/real/noodle_chinese.890");
        $subtitle->getCues()[0]->setLines(["岛屿"]);

        $output = $subtitle->toString(Format::Cavena890);

        $this->assertSame("5c9b5c7f0000", bin2hex(substr($output, Cavena890::HEADER_SIZE + Cavena890::LINE_2, 6)));
        $this->assertSame(["岛屿"], Subtitle::fromString($output, Format::Cavena890)->getCues()[0]->getLines());
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
