<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\HtmlTranscriptWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class HtmlTranscriptFormatterTest extends TestCase
{
    private function dialogue(): Subtitle
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, ["<v Anna><b>Hello</b>", "there."]));
        $subtitle->addCue(new SubtitleCue(2.5, 4, "<v Anna>Where are you going?"));
        $subtitle->addCue(new SubtitleCue(4, 5, ["<v Ben>Home &amp; <00:00:04.500>bed.", "<v Anna>Why?"]));
        $subtitle->addCue(new SubtitleCue(9, 10, "<v Anna>Fish &lt;3"));
        $subtitle->addCue(new SubtitleCue(3725, 3727, "No speaker."));

        return $subtitle;
    }


    public function testWritesCiteTimeAndParagraphs(): void
    {
        $this->assertSame(
            "<cite>Anna:</cite>\n<time>0:01</time>\n<p>Hello there. Where are you going?</p>\n" .
            "<cite>Ben:</cite>\n<time>0:04</time>\n<p>Home &amp; bed.</p>\n" .
            "<cite>Anna:</cite>\n<time>0:04</time>\n<p>Why?</p>\n" .
            "<cite>Anna:</cite>\n<time>0:09</time>\n<p>Fish &lt;3</p>\n" .
            "<time>1:02:05</time>\n<p>No speaker.</p>\n",
            $this->dialogue()->toString(Format::HtmlTranscript)
        );
    }


    public function testStartsAParagraphAtTheGapOfTheOption(): void
    {
        $html = $this->dialogue()->toString(Format::HtmlTranscript, new WriteOptions(formats: [new HtmlTranscriptWriteOptions(paragraphGap: 0.5)]));

        $this->assertStringStartsWith("<cite>Anna:</cite>\n<time>0:01</time>\n<p>Hello there.</p>\n<cite>Anna:</cite>\n<time>0:02</time>\n", $html);
    }


    public function testEscapesTheSpeaker(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0, 1, "<v Tom &amp; Jerry>Hi"));

        $this->assertSame("<cite>Tom &amp; Jerry:</cite>\n<time>0:00</time>\n<p>Hi</p>\n", $subtitle->toString(Format::HtmlTranscript));
    }


    public function testThrowsForANegativeGap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HtmlTranscriptWriteOptions(paragraphGap: -1);
    }
}
