<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class Mpl2FormatterTest extends TestCase
{
    public function testWritesTenthsOfASecondAndLineBreaks(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1.24, 4.46, ["Where are you?", "Home."]));

        $this->assertSame("[12][45]Where are you?|Home.\n", $subtitle->toString(Format::Mpl2));
    }


    public function testWritesAnItalicLineWithASlash(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>One</i>", "Two"]))
            ->addCue(new SubtitleCue(3, 4, ["<i>Three", "Four</i>"]))
            ->addCue(new SubtitleCue(5, 6, ["<b><i>Five</i></b>", "<i>Six</i> and seven"]));

        $this->assertSame(
            "[10][20]/One|Two\n[30][40]/Three|/Four\n[50][60]/Five|Six and seven\n",
            $subtitle->toString(Format::Mpl2)
        );
    }


    public function testStripsOtherTagsAndDecodesEntities(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0, 1, ["<font color=\"#ff0000\">Red</font> &amp; <v Anna>blue", "<b> </b>"]));

        $this->assertSame("[0][10]Red & blue\n", $subtitle->toString(Format::Mpl2));
    }


    public function testAppliesTheOutputOptions(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0, 1, "Hello"));

        $this->assertSame(
            "\xEF\xBB\xBF[0][10]Hello\r\n",
            $subtitle->toString(Format::Mpl2, new WriteOptions(lineEnding: LineEnding::Crlf, bom: true))
        );
    }


    public function testATimeJustBelowTheLimitStaysBelowItAndReadsBack(): void
    {
        $output = Subtitle::fromString("WEBVTT\n\n99999:59:59.900 --> 99999:59:59.999\nx\n", Format::WebVtt)->toString(Format::Mpl2);

        $this->assertSame("[3599999999][3599999999]x\n", $output);
        $this->assertSame(359999999.9, Subtitle::fromString($output, Format::Mpl2)->getCues()[0]->getEnd());
    }


    public function testALineFeedAndABarInsideALineReadBackAsOneLineEach(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["a\nb", "Rock | Pop"]))
            ->addCue(new SubtitleCue(3, 4, ["|"]));
        $output = $subtitle->toString(Format::Mpl2);

        $this->assertSame("[10][20]a|b|Rock \u{00A6} Pop\n[30][40]\u{00A6}\n", $output);
        $this->assertSame(["a", "b", "Rock \u{00A6} Pop"], Subtitle::fromString($output, Format::Mpl2)->getCues()[0]->getLines());
    }
}
