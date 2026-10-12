<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

class YouTubeTimedTextWriterTest extends TestCase
{
    private const WRITTEN = ["srv1", "srv2"];


    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("youtube") as $file) {
            if (!in_array(pathinfo($file, PATHINFO_EXTENSION), self::WRITTEN, true)) {
                continue;
            }
            $cases["$file default"] = [$file, new ReadOptions()];
            $cases["$file words"]   = [$file, self::withWords()];
        }

        return $cases;
    }


    private static function withWords(): ReadOptions
    {
        return new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]);
    }


    private static function read(string $file, ?ReadOptions $options = null): Subtitle
    {
        return TestFiles::parse("youtube/real/$file", Format::YouTubeTimedText, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): string
    {
        return (new YouTubeTimedTextWriter())->write($subtitle);
    }


    /**
     * blank-lines.en.srv3 has white space before the XML declaration, which the parser skips.
     */
    #[DataProvider("realFiles")]
    public function testARoundTripGivesTheBytesOfTheSource(string $file, ReadOptions $options): void
    {
        $this->assertSame(ltrim(file_get_contents(TestFiles::DIR . "youtube/real/$file")), self::write(self::read($file, $options)));
    }


    public function testAnEditedTextLosesItsEscapedEntitiesAndTheOthersKeepThem(): void
    {
        $subtitle = self::read("transcript.en.srv1");
        $subtitle->getCues()[0]->setLines("hello all and welcome back");

        $this->assertStringStartsWith(
            '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="0.16" dur="4.4">hello all and welcome back</text>' .
            '<text start="2.96" dur="4.48">today we&amp;#39;re planting tomatoes</text>',
            self::write($subtitle)
        );
    }


    public function testAMovedCueKeepsItsDurationAndAChangedEndGetsANewOne(): void
    {
        $subtitle = self::read("missing-d.en.srv2");
        $subtitle->getCues()[0]->setStart(1.5)->setEnd(3.5);
        $subtitle->getCues()[1]->setEnd(6.0);

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<timedtext>\n<text t=\"1500\" d=\"2000\">the market opens at eight</text>\n" .
            "<text t=\"4000\" d=\"2000\">bring a basket</text>\n<text t=\"6500\" d=\"0\">and some coins</text>\n" .
            "<text t=\"8000\">we close at noon</text>\n</timedtext>\n",
            self::write($subtitle)
        );
    }


    public function testCuesFromAnotherFormatGiveTimesFromTheCues(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1.5, 4, ["<i>Fish &amp; chips</i>", "today"]), new SubtitleCue(5, 7.25, "Done")]);
        $subtitle->setFormatData("youtube", ["format" => "srv1"]);

        $this->assertSame(
            '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="1.5" dur="2.5">Fish &amp; chips' . "\n" .
            'today</text><text start="5" dur="2.25">Done</text></transcript>',
            self::write($subtitle)
        );
    }
}
