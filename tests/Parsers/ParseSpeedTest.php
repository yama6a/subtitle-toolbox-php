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
            "SAMI"     => [Format::Sami, new WriteOptions()],
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


    public static function markupFormats(): array
    {
        return [
            "TTML" => [Format::Ttml],
            "SAMI" => [Format::Sami],
        ];
    }


    // TTML and SAMI read about 2 to 3 times slower than SubRip. Quadratic work per cue makes them 30 to 40 times slower.
    #[DataProvider("markupFormats")]
    public function testParsesAtMostTenTimesSlowerThanSubRip(Format $format): void
    {
        $subtitle = $this->repeatFixture(self::CUE_COUNT);
        $subRip   = $this->parseSeconds($subtitle->toString(Format::SubRip), Format::SubRip);
        $seconds  = $this->parseSeconds($subtitle->toString($format), $format);

        $this->assertLessThan(10 * $subRip, $seconds, sprintf("%s took %.2f s, SubRip %.2f s.", $format->name, $seconds, $subRip));
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
