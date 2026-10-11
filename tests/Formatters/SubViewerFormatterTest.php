<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class SubViewerFormatterTest extends TestCase
{
    public function testWritesSubViewer2ByDefault(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Bakery")
            ->addCue(new SubtitleCue(1.5, 4.004, ["<b>Fresh bread</b>", "every day"]))
            ->addCue(new SubtitleCue(3725.006, 3727.995, "Closed on Sunday"));

        $this->assertSame(
            "[INFORMATION]\n[TITLE]Bakery\n[AUTHOR]\n[SOURCE]\n[PRG]\n[FILEPATH]\n[DELAY]0\n[CD TRACK]0\n[COMMENT]\n" .
            "[END INFORMATION]\n[SUBTITLE]\n" .
            "00:00:01.50,00:00:04.00\nFresh bread[br]every day\n\n" .
            "01:02:05.01,01:02:08.00\nClosed on Sunday\n",
            $subtitle->toString(Format::SubViewer)
        );
    }


    public function testWritesSubViewer1WithTheVersionOption(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Jane Doe")
            ->addCue(new SubtitleCue(1.5, 4.4, ["Fresh bread", "every day"]));

        $this->assertSame(
            "[TITLE]\n[AUTHOR]\nJane Doe\n[SOURCE]\n[PRG]\n[FILEPATH]\n[DELAY]\n0\n[CD TRACK]\n0\n[BEGIN]\n" .
            "******** START SCRIPT ********\n" .
            "[00:00:02]\nFresh bread|every day\n[00:00:04]\n\n" .
            "[end]\n******** END SCRIPT ********\n",
            $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V1)]))
        );
    }


    public function testStripsMarkupDecodesEntitiesAndSkipsCuesWithoutText(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Tea &amp; cake &lt;3</i>"]))
            ->addCue(new SubtitleCue(3, 4, ["<i> </i>"]));

        $this->assertStringEndsWith(
            "[SUBTITLE]\n00:00:01.00,00:00:02.00\nTea & cake <3\n",
            $subtitle->toString(Format::SubViewer)
        );
    }


    public function testSubViewer1WritesDelayZeroBecauseTheParserAppliedIt(): void
    {
        $subtitle = Subtitle::fromString("[DELAY]\n3\n******** START SCRIPT ********\n[00:00:01]\nHello\n[00:00:02]\n", Format::SubViewer);

        $this->assertStringStartsWith(
            "[TITLE]\n[AUTHOR]\n[DELAY]\n0\n******** START SCRIPT ********\n[00:00:04]\nHello\n[00:00:05]\n",
            $subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V1)]))
        );
    }
}
