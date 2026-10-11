<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class StreamWriterTest extends TestCase
{
    private const SUBRIP_FIXTURE = __DIR__ . "/../files/srt/real/own_styled.srt";
    private const WEBVTT_FIXTURE = __DIR__ . "/../files/vtt/real/w3c_regions.vtt";

    private string $path;


    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), "stream-writer-");
    }


    protected function tearDown(): void
    {
        unlink($this->path);
    }


    public function testShiftsASubRipFileIntoAWebVttFile(): void
    {
        $writer = new WebVttStreamWriter($this->path);
        foreach ((new SubRipStreamReader())->read(self::SUBRIP_FIXTURE) as $cue) {
            $writer->write($cue->setStart($cue->getStart() + 2)->setEnd($cue->getEnd() + 2));
        }
        $writer->close();

        $cues = iterator_to_array((new WebVttStreamReader())->read($this->path), false);
        $this->assertCount(10, $cues);
        $this->assertSame(19.985, $cues[0]->getStart());
        $this->assertSame(22.521, $cues[0]->getEnd());
        $this->assertSame(782.0, $cues[9]->getEnd());
    }


    public function testCopiesTheWebVttHeaderFromReaderToWriter(): void
    {
        $reader = new WebVttStreamReader();
        $cues   = $reader->read(self::WEBVTT_FIXTURE);
        $cues->current();
        $writer = new WebVttStreamWriter($this->path, header: $reader->getHeader());
        foreach ($cues as $cue) {
            $writer->write($cue);
        }
        $writer->close();

        $copy = new WebVttStreamReader();
        iterator_to_array($copy->read($this->path));
        $this->assertSame($reader->getHeader(), $copy->getHeader());
        $this->assertNotSame([], $copy->getHeader()["regions"]);
    }


    public function testEscapesTheTimingArrowInCueText(): void
    {
        $writer = new WebVttStreamWriter($this->path, new WriteOptions(bom: false));
        $writer->write(new SubtitleCue(1, 2, ["a --> b", "", "c"]));
        $writer->close();

        $this->assertSame(file_get_contents(__DIR__ . "/../files/vtt/real/own_arrow_in_text.vtt"), file_get_contents($this->path));
    }


    public function testListsTheColorsThatTheWebVttOutputDrops(): void
    {
        $fixture = __DIR__ . "/../files/srt/real/own_font_colors.srt";
        $writer  = new WebVttStreamWriter($this->path, new WriteOptions(bom: false));
        foreach ((new SubRipStreamReader())->read($fixture) as $cue) {
            $writer->write($cue);
        }
        $writer->close();

        $expected = (new WebVttFormatter())->formatWithReport(Subtitle::load($fixture, Format::SubRip), new WriteOptions(bom: false));
        $this->assertSame($expected->content, file_get_contents($this->path));
        $this->assertEquals($expected->droppedColors, $writer->findDroppedColors());
        $this->assertCount(1, $writer->findDroppedColors());
        $this->assertSame("#123456", $writer->findDroppedColors()[0]->color);
    }


    public function testListsNoDroppedColorWhenTheWriterStripsTags(): void
    {
        $writer = new WebVttStreamWriter($this->path, new WriteOptions(stripTags: true));
        $writer->write(new SubtitleCue(1, 2, "<font color=\"#123456\">Hi</font>"));
        $writer->close();

        $this->assertSame([], $writer->findDroppedColors());
    }


    public function testWritesCrlfWithoutBom(): void
    {
        $writer = new SubRipStreamWriter($this->path, new WriteOptions(lineEnding: LineEnding::Crlf, bom: false));
        $writer->write(new SubtitleCue(1, 2, "A"));
        $writer->write(new SubtitleCue(3, 4.5, "B"));
        $writer->close();

        $this->assertSame(
            "1\r\n00:00:01,000 --> 00:00:02,000\r\nA\r\n\r\n2\r\n00:00:03,000 --> 00:00:04,500\r\nB\r\n",
            file_get_contents($this->path)
        );
    }


    public function testCloseKeepsAStreamThatTheCallerOpened(): void
    {
        $stream = fopen($this->path, "wb");
        $writer = new SubRipStreamWriter($stream);
        $writer->close();

        $this->assertTrue(is_resource($stream));
        fclose($stream);
    }


    public function testRejectsAWriteAfterClose(): void
    {
        $writer = new WebVttStreamWriter($this->path);
        $writer->close();

        $this->expectException(InvalidArgumentException::class);
        $writer->write(new SubtitleCue(1, 2, "A"));
    }


    public function testIgnoresTheOptionsOfOtherFormats(): void
    {
        $writer = new SubRipStreamWriter($this->path, new WriteOptions(bom: false, formats: [new CsvWriteOptions(delimiter: ";")]));
        $writer->write(new SubtitleCue(1, 2, "A"));
        $writer->close();

        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nA\n", file_get_contents($this->path));
    }
}
