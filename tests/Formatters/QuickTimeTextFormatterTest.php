<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
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


    public function testATimeJustBelowTheLimitStaysBelowIt(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(359999998.0, 359999999.999, "Last"));
        $output   = $subtitle->toString(Format::QuickTimeText);
        $this->assertSame(self::HEADER . "[99999:59:58.00]\nLast\n[99999:59:59.29]\n\n", $output);
        $this->assertSame(359999999.967, Subtitle::fromString($output)->getCues()[0]->getEnd());

        // A unit of 0.1 ms: the last unit below the limit, 99999:59:59.9999, would read as 100000 hours.
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(359999998.0, 360000000.0, "Last"));
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}{timeScale:10000}"]]);
        $output = $subtitle->toString(Format::QuickTimeText);
        $this->assertStringEndsWith("[99999:59:59.9990]\n", $output);
        $this->assertSame(359999999.999, Subtitle::fromString($output)->getCues()[0]->getEnd());
    }


    public function testWritesAStoredHeaderThatReadsBack(): void
    {
        $lenient = Subtitle::fromString("[00:00:01.00]\nOne\n[00:00:02.00]\n", Format::QuickTimeText, new ReadOptions(lenient: true));
        $this->assertSame("{QTtext}\n[00:00:00.000]\n[00:00:01.000]\nOne\n[00:00:02.000]\n", $lenient->toString(Format::QuickTimeText));

        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "First"));
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}", "a\r\n[00:00:09.00]\nInjected", "[00:00:01.00]"]]);
        $output = $subtitle->toString(Format::QuickTimeText);
        $this->assertSame("{QTtext}\na  [00:00:09.00] Injected\n[00:00:00.000]\n[00:00:01.000]\nFirst\n[00:00:02.000]\n", $output);
        $this->assertEquals([new SubtitleCue(1, 2, "First")], Subtitle::fromString($output)->getCues());
    }


    public function testARelativeTimestampOfHalfAUnitRoundsUp(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0.2, 0.7, "One"));
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}{timeScale:25}{timeStamps:relative}"]]);

        // 0.7 - 0.2 is 0.49999999999999994 in binary, but the cue lasts 500 ms, which is 12.5 units of 40 ms.
        $this->assertSame(
            "{QTtext}{timeScale:25}{timeStamps:relative}\n[00:00:00.00]\n[00:00:00.05]\nOne\n[00:00:00.13]\n",
            $subtitle->toString(Format::QuickTimeText)
        );
    }


    public function testAHugeTimeScaleWritesDigitsThatReadBack(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "One"));
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}{timeScale:99999999999999999999}"]]);
        $output = $subtitle->toString(Format::QuickTimeText);

        $this->assertSame("{QTtext}{timeScale:99999999999999999999}\n[00:00:00.0000000000000000000]\nOne\n[00:00:00.0000000000000000000]\n", $output);
        $this->assertCount(1, Subtitle::fromString($output)->getCues());
    }


    public function testRelativeTimestampsCountFromTheTimeThatTheParserReaches(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(0, 5, "A"))
            ->addCue(new SubtitleCue(3, 6, "B"))
            ->addCue(new SubtitleCue(10, 12, "C"));
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}{timeScale:1000}{timeStamps:relative}"]]);

        $this->assertEquals(
            [new SubtitleCue(0, 5, "A"), new SubtitleCue(5, 6, "B"), new SubtitleCue(10, 12, "C")],
            Subtitle::fromString($subtitle->toString(Format::QuickTimeText))->getCues()
        );

        $subtitle = new Subtitle();
        for ($index = 0; $index < 100; $index++) {
            $subtitle->addCue(new SubtitleCue($index * 1020 / 1000, ($index + 1) * 1020 / 1000, "Cue $index"));
        }
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}{timeScale:1}{timeStamps:relative}"]]);
        $cues = Subtitle::fromString($subtitle->toString(Format::QuickTimeText))->getCues();
        $this->assertSame(102.0, $cues[99]->getEnd());
    }


    public function testARelativeTimestampNearTheLimitStaysBelowIt(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(359999990.0, 359999999.999, "Last"));
        $subtitle->setFormatData("qttext", ["header" => ["{QTtext}{timeScale:1}{timeStamps:relative}"]]);

        // The end is 9.999 s after the start, which rounds to 10 s and would reach 100000 hours.
        $this->assertSame(
            "{QTtext}{timeScale:1}{timeStamps:relative}\n[00:00:00.0]\n[99999:59:50.0]\nLast\n[00:00:09.0]\n",
            $subtitle->toString(Format::QuickTimeText)
        );
    }
}
