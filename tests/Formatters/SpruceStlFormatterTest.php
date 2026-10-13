<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SpruceStlWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class SpruceStlFormatterTest extends TestCase
{
    private const HEADER = "\$FontName = Arial\n\$FontSize = 42\n\$HorzAlign = Center\n\$VertAlign = Bottom\n\$XOffset = 0\n\$YOffset = 0\n" .
                           "\$Bold = False\n\$Italic = False\n\$Underlined = False\n\$TextContrast = 15\n\$Outline1Contrast = 15\n" .
                           "\$Outline2Contrast = 15\n\$BackgroundContrast = 0\n\$ForceDisplay = False\n\$FadeIn = 0\n\$FadeOut = 0\n" .
                           "\$TapeOffset = False\n\n";


    public function testWritesTheHeaderAndFramesAt25FpsForANewFile(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(137.4, 140.36, ["The bakery opens at seven,", "every day of the week."]));

        $this->assertSame(
            self::HEADER . "00:02:17:10 , 00:02:20:09 , The bakery opens at seven,|every day of the week.\n",
            $subtitle->toString(Format::SpruceStl)
        );
    }


    public function testWritesTogglesForBoldItalicsAndUnderline(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Over the water,</i>", "<i>the <b>gulls</b></i> <u>fly</u> &amp; <font color=\"red\">sing</font>"]));

        $this->assertStringEndsWith(
            "00:00:01:00 , 00:00:02:00 , ^IOver the water,|the ^Bgulls ^B^I^Ufly^U & sing\n",
            $subtitle->toString(Format::SpruceStl)
        );
    }


    public function testWritesTogglesAgainstTheStyleOfTheHeader(): void
    {
        $subtitle = Subtitle::fromString("\$Italic = True\n00:00:01:00 , 00:00:02:00 , Sung\n", Format::SpruceStl);
        $subtitle->getCues()[0]->setLines(["Spoken <i>sung</i>"]);

        $this->assertSame("\$Italic = True\n00:00:01:00 , 00:00:02:00 , ^ISpoken ^Isung\n", $subtitle->toString(Format::SpruceStl));
    }


    public function testWritesAlignmentCommandsWhenTheAlignmentChanges(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:00 , 00:00:02:00 , One\n", Format::SpruceStl);
        $subtitle->addCue((new SubtitleCue(3, 4, "Sign"))->setAlignment(Alignment::TopLeft));
        $subtitle->addCue(new SubtitleCue(5, 6, "Two"));

        $this->assertSame(
            "00:00:01:00 , 00:00:02:00 , One\n\$HorzAlign = Left\n\$VertAlign = Top\n00:00:03:00 , 00:00:04:00 , Sign\n" .
            "\$HorzAlign = Center\n\$VertAlign = Bottom\n00:00:05:00 , 00:00:06:00 , Two\n",
            $subtitle->toString(Format::SpruceStl)
        );
    }


    public function testWritesCommentsAsCommentLines(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:00,00:00:02:00,One\n", Format::SpruceStl)
            ->addComment("Reel 2\nchecked", 1);

        $this->assertSame("00:00:01:00,00:00:02:00,One\n//Reel 2\n//checked\n", $subtitle->toString(Format::SpruceStl));
    }


    public function testTheFrameRateOfTheOptionsWins(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:15 , 00:00:02:00 , PAL\n", Format::SpruceStl);

        $this->assertSame(
            "00:00:01:18 , 00:00:02:00 , PAL\n",
            $subtitle->toString(Format::SpruceStl, new WriteOptions(formats: [new SpruceStlWriteOptions(frameRate: 30)]))
        );
    }


    public function testRoundsAFrameAtTheFrameRateUpToTheNextSecond(): void
    {
        $subtitle = Subtitle::fromString("00:00:01:00 , 00:00:02:00 , One\n", Format::SpruceStl);
        $subtitle->getCues()[0]->setEnd(2.99);

        $this->assertSame("00:00:01:00 , 00:00:03:00 , One\n", $subtitle->toString(Format::SpruceStl));
    }


    public function testRejectsAFrameRateOf0(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The Spruce STL frame rate must be greater than 0, got 0.");

        new SpruceStlWriteOptions(frameRate: 0);
    }
}
