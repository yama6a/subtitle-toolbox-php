<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class SubViewerParserTest extends TestCase
{
    public function testSubViewer2TextLinesAndBrTagsBecomeLines(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.50,00:00:04.00\nFresh bread[br]every day\nand cakes\n", new ReadOptions());

        $this->assertSame(["Fresh bread", "every day", "and cakes"], $subtitle->getCues()[0]->getLines());
    }


    public function testSubViewer2AcceptsOneToThreeFractionDigitsAsFFmpegDoes(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.5,00:00:02.250\nOne\n\n00:00:03.05,00:00:04.00\nTwo\n", new ReadOptions());

        $this->assertSame([[1.5, 2.25], [3.05, 4.0]], TestSubtitles::times($subtitle));
    }


    public function testSubViewer2DelayIsKeptAndNotApplied(): void
    {
        $subtitle = (new SubViewerParser())->parse("[INFORMATION]\n[DELAY]5\n[END INFORMATION]\n[SUBTITLE]\n00:00:01.00,00:00:02.00\nHello\n", new ReadOptions());

        $this->assertSame([[1.0, 2.0]], TestSubtitles::times($subtitle));
        $this->assertSame(["version" => 2, "header" => ["DELAY" => "5"]], $subtitle->findFormatData("subviewer"));
    }


    public function testSubViewer2HeaderValueAfterASpaceIsTrimmed(): void
    {
        $subtitle = (new SubViewerParser())->parse("[INFORMATION]\n[TITLE] Weather report\n[AUTHOR]\n[END INFORMATION]\n" .
                                                   "00:00:01.00,00:00:02.00\nHello\n", new ReadOptions());

        $this->assertSame([Subtitle::METADATA_TITLE => "Weather report"], $subtitle->getAllMetadata());
    }


    public function testSubViewer2StyleLineBetweenCuesIsNotText(): void
    {
        $subtitle = (new SubViewerParser())->parse("[SUBTITLE]\n[COLF]&HFFFFFF,[SIZE]18\n00:00:01.00,00:00:02.00\nOne\n\n" .
                                                   "[COLF]&H00FF00,[SIZE]20\n00:00:03.00,00:00:04.00\nTwo\n", new ReadOptions());

        $this->assertSame([["One"], ["Two"]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
        $this->assertSame("[COLF]&HFFFFFF,[SIZE]18", $subtitle->findFormatData("subviewer")["style"]);
    }


    public function testSubViewer2TimingLineWithoutTextIsDropped(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.00,00:00:02.00\n\n00:00:03.00,00:00:04.00\nHello\n", new ReadOptions());

        $this->assertSame([[3.0, 4.0]], TestSubtitles::times($subtitle));
    }


    public function testSubViewer2TextBeforeTheFirstTimingLineThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The line \"Hello\" is neither a header tag nor a timing line.");

        (new SubViewerParser())->parse("[SUBTITLE]\nHello\n00:00:01.00,00:00:02.00\nWorld\n", new ReadOptions());
    }


    public function testSubViewer1PipeIsALineBreak(): void
    {
        $subtitle = (new SubViewerParser())->parse("******** START SCRIPT ********\n[00:00:01]\nRain|then sun\n[00:00:04]\n\n", new ReadOptions());

        $this->assertSame([[1.0, 4.0]], TestSubtitles::times($subtitle));
        $this->assertSame(["Rain", "then sun"], $subtitle->getCues()[0]->getLines());
    }


    public function testSubViewer1CueWithoutEndLineEndsAtTheNextCue(): void
    {
        $subtitle = (new SubViewerParser())->parse("******** START SCRIPT ********\n[00:00:01]\nOne\n[00:00:03]\nTwo\n[00:00:05]\n\n", new ReadOptions());

        $this->assertSame([[1.0, 3.0], [3.0, 5.0]], TestSubtitles::times($subtitle));
    }


    public function testSubViewer1LastCueWithoutEndLineLastsTheGivenDuration(): void
    {
        $content = "******** START SCRIPT ********\n[00:00:01]\nOne\n";

        $this->assertSame([[1.0, 6.0]], TestSubtitles::times((new SubViewerParser())->parse($content, new ReadOptions())));
        $this->assertSame([[1.0, 3.5]], TestSubtitles::times((new SubViewerParser())->parse($content, new ReadOptions(lastCueDuration: 2.5))));
    }


    public function testSubViewer1HeaderValueOnTheTagLineIsRead(): void
    {
        $subtitle = (new SubViewerParser())->parse("[TITLE]Ferry times\n[DELAY]\n-1\n******** START SCRIPT ********\n[00:00:02]\nHello\n[00:00:03]\n", new ReadOptions());

        $this->assertSame("Ferry times", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame([[1.0, 2.0]], TestSubtitles::times($subtitle));
    }


    public function testSubViewer1HeaderLineWithoutTagThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The line \"Ferry times\" is not a SubViewer 1 header tag.");

        (new SubViewerParser())->parse("Ferry times\n******** START SCRIPT ********\n[00:00:02]\nHello\n", new ReadOptions());
    }

}
