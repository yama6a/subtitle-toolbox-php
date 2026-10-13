<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class QuickTimeTextFormatterTest extends TestCase
{
    private const HEADER = "{QTtext} {font:Tahoma}\n{plain} {size:20}\n{timeScale:30}\n{width:160} {height:32}\n{timestamps:absolute} {language:0}\n";


    /**
     * Adapted from testConvertingToFormat of mantas-done/subtitles, tests/formats/TxtQuickTimeTest.php
     * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/formats/TxtQuickTimeTest.php), MIT.
     */
    public function testWritesTheSubtitleEditShapeForANewFile(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(137.44, 140.375, ["The ferry leaves at nine,", "from the north pier."]))
            ->addCue(new SubtitleCue(3740.476, 3742.501, "Tickets at the kiosk."));

        $this->assertSame(
            self::HEADER . "[00:02:17.13]\nThe ferry leaves at nine,\nfrom the north pier.\n[00:02:20.11]\n\n" .
            "[01:02:20.14]\nTickets at the kiosk.\n[01:02:22.15]\n\n",
            $subtitle->toString(Format::QuickTimeText)
        );
    }


    public function testWritesStylesAsDescriptors(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Sung</i> and <b>loud</b>", "<font color=\"red\">red</font> &amp; plain"]))
            ->addCue(new SubtitleCue(3, 4, "<i>Sung</i>"))
            ->addCue(new SubtitleCue(5, 6, "<i>Sung <u>loud</u></i>"));

        $this->assertSame(
            self::HEADER . "[00:00:01.00]\n{italic}Sung{plain} and {bold}loud\n{plain}red & plain\n[00:00:02.00]\n\n" .
            "[00:00:03.00]\n{italic}Sung\n[00:00:04.00]\n\n[00:00:05.00]\nSung {underline}loud\n[00:00:06.00]\n\n",
            $subtitle->toString(Format::QuickTimeText)
        );
    }


    public function testWritesTheTimeScaleAndTheRelativeTimestampsOfTheHeader(): void
    {
        $subtitle = Subtitle::fromString("{QTtext}{timeScale:600}{timeStamps:relative}\n[00:00:01.300]\nOne\n[00:00:02.000]\n");
        $subtitle->addCue(new SubtitleCue(4.5, 6.5, "Two"));

        $this->assertSame(
            "{QTtext}{timeScale:600}{timeStamps:relative}\n[00:00:00.000]\n[00:00:01.300]\nOne\n[00:00:02.000]\n[00:00:01.000]\nTwo\n[00:00:02.000]\n",
            $subtitle->toString(Format::QuickTimeText)
        );
    }


    public function testWritesMillisecondsWithoutATimeScale(): void
    {
        $subtitle = Subtitle::fromString("{QTtext}{font:Geneva}\n[00:00:07.25]\nRain\n[00:00:09.5]\n");

        $this->assertSame("{QTtext}{font:Geneva}\n[00:00:00.000]\n[00:00:07.250]\nRain\n[00:00:09.500]\n", $subtitle->toString(Format::QuickTimeText));
    }


    public function testWritesTheTextOfAChangedCueFromItsMarkup(): void
    {
        $subtitle = Subtitle::fromString("{QTtext}{timeScale:30}\n[00:00:01.00]\n{font:Geneva}{italic}Sung\n[00:00:02.00]\n");
        $subtitle->getCues()[0]->setLines(["Spoken"]);

        $this->assertSame("{QTtext}{timeScale:30}\n[00:00:00.00]\n[00:00:01.00]\nSpoken\n[00:00:02.00]\n", $subtitle->toString(Format::QuickTimeText));
    }
}
