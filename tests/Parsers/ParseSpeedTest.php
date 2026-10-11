<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\WriteOptions;

class ParseSpeedTest extends TestCase
{
    private const CUE_COUNT = 20000;


    public static function formats(): array
    {
        return [
            "SubRip"   => [Format::SubRip, new WriteOptions()],
            "WebVTT"   => [Format::WebVtt, new WriteOptions()],
            "SBV"      => [Format::Sbv, new WriteOptions()],
            "ASS"      => [Format::Ass, new WriteOptions()],
            "MicroDVD" => [Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25, writeFrameRateLine: true)])],
            "TTML"     => [Format::Ttml, new WriteOptions()],
            "EBU STL"  => [Format::EbuStl, new WriteOptions()],
        ];
    }


    // A parser that sorts the cues after each added cue takes minutes here.
    #[DataProvider("formats")]
    public function testParsesTwentyThousandCuesUnderTwentySeconds(Format $format, WriteOptions $options): void
    {
        $content = $this->repeatFixture(self::CUE_COUNT)->toString($format, $options);

        $start    = microtime(true);
        $subtitle = Subtitle::fromString($content, $format);

        $this->assertLessThan(20, microtime(true) - $start);
        $this->assertCount(self::CUE_COUNT, $subtitle->getCues());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    // TTML reads about 3 times slower than SubRip. Quadratic work per paragraph makes it 40 times slower.
    public function testParsesTtmlAtMostTenTimesSlowerThanSubRip(): void
    {
        $subtitle = $this->repeatFixture(self::CUE_COUNT);
        $subRip   = $this->parseSeconds($subtitle->toString(Format::SubRip), Format::SubRip);
        $ttml     = $this->parseSeconds($subtitle->toString(Format::Ttml), Format::Ttml);

        $this->assertLessThan(10 * $subRip, $ttml, sprintf("TTML took %.2f s, SubRip %.2f s.", $ttml, $subRip));
    }


    private function parseSeconds(string $content, Format $format): float
    {
        $start = microtime(true);
        Subtitle::fromString($content, $format);

        return microtime(true) - $start;
    }


    private function repeatFixture(int $cueCount): Subtitle
    {
        $cues     = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/real/own_escaping.srt"), Format::SubRip)->getCues();
        $period   = ceil(end($cues)->getEnd()) + 1;
        $subtitle = new Subtitle();
        $added    = [];
        for ($index = 0; $index < $cueCount; $index++) {
            $cue    = $cues[$index % count($cues)];
            $offset = intdiv($index, count($cues)) * $period;
            $added[] = new SubtitleCue($cue->getStart() + $offset, $cue->getEnd() + $offset, $cue->getLines());
        }
        $subtitle->addCues($added);

        return $subtitle;
    }
}
