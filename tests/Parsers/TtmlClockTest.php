<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class TtmlClockTest extends TestCase
{
    /**
     * Each case holds the ttp attributes of the root, the clock that they give, a time expression and its seconds.
     *
     * @return array<string, array{string, TtmlClock, string, float}>
     */
    public static function timeExpressions(): array
    {
        return [
            "clock time"               => ["", new TtmlClock(), "01:02:03.5", 3723.5],
            "clock time with comma"    => ["", new TtmlClock(), "00:00:01,500", 1.5],
            "clock time with frames"   => ["", new TtmlClock(), "00:00:01:15", 1.5],
            "offset in hours"          => ["", new TtmlClock(), "1.5h", 5400.0],
            "offset in minutes"        => ["", new TtmlClock(), "2m", 120.0],
            "offset in seconds"        => ["", new TtmlClock(), "3.5s", 3.5],
            "offset in milliseconds"   => ["", new TtmlClock(), "250ms", 0.25],
            "offset in frames"         => ['ttp:frameRate="25"', new TtmlClock(frameRate: 25, smpteFrameRate: 25, tickRate: 25), "50f", 2.0],
            "offset in ticks"          => ['ttp:tickRate="10000000"', new TtmlClock(tickRate: 10000000), "15000000t", 1.5],
            "ticks at the frame rate"  => ['ttp:frameRate="25"', new TtmlClock(frameRate: 25, smpteFrameRate: 25, tickRate: 25), "50t", 2.0],
            "SMPTE drop NTSC"          => [
                'ttp:timeBase="smpte" ttp:frameRate="30" ttp:frameRateMultiplier="1000 1001" ttp:dropMode="dropNTSC"',
                new TtmlClock(frameRate: 30000 / 1001, smpteTimeBase: true, dropMode: "dropNTSC", tickRate: 30000 / 1001),
                "01:10:00:00",
                125874 * 1001 / 30000,
            ],
            "SMPTE drop PAL"           => [
                'ttp:timeBase="smpte" ttp:frameRate="25" ttp:dropMode="dropPAL"',
                new TtmlClock(frameRate: 25, smpteFrameRate: 25, smpteTimeBase: true, dropMode: "dropPAL", tickRate: 25),
                "01:10:00:00",
                4194.88,
            ],
            "sub-frames"               => [
                'ttp:frameRate="30" ttp:subFrameRate="2"',
                new TtmlClock(subFrameRate: 2, tickRate: 30),
                "00:00:01:10.1",
                1.35,
            ],
            "frame rate multiplier"    => [
                'ttp:frameRate="30" ttp:frameRateMultiplier="1000 1001"',
                new TtmlClock(frameRate: 30000 / 1001, tickRate: 30000 / 1001),
                "00:00:00:30",
                1.001,
            ],
            "last field as hundredths" => ["", new TtmlClock(lastFieldIsHundredths: true), "00:00:01:75", 1.75],
        ];
    }


    #[DataProvider("timeExpressions")]
    public function testReadsTheTimeExpression(string $parameters, TtmlClock $clock, string $expression, float $seconds): void
    {
        self::assertEqualsWithDelta($seconds, $clock->seconds($expression), 1e-9);
    }


    #[DataProvider("timeExpressions")]
    public function testTheParserReadsTheTimeExpression(string $parameters, TtmlClock $clock, string $expression, float $seconds): void
    {
        $subtitle = Subtitle::fromString(
            '<tt xmlns="http://www.w3.org/ns/ttml" xmlns:ttp="http://www.w3.org/ns/ttml#parameter" ' . $parameters . '>'
            . '<body><div><p begin="' . $expression . '" dur="1s">Text</p></div></body></tt>',
            Format::Ttml,
            new ReadOptions(lenient: true)
        );

        self::assertEqualsWithDelta($seconds, $subtitle->getCues()[0]->getStart(), 0.0005);
    }


    public function testRejectsAnInvalidExpression(): void
    {
        $this->expectException(ParsingException::class);

        (new TtmlClock())->seconds("1:2:3");
    }


    /**
     * @return array<string, array{string, ?float}>
     */
    public static function looseExpressions(): array
    {
        return [
            "one-digit fields"       => ["1:2:3", 3723.0],
            "minutes and seconds"    => ["01:05.5", 65.5],
            "milliseconds as frames" => ["00:00:01:500", 1.5],
            "bare milliseconds"      => ["1500", 1.5],
            "no time"                => ["soon", null],
        ];
    }


    #[DataProvider("looseExpressions")]
    public function testReadsALooseExpression(string $expression, ?float $seconds): void
    {
        self::assertSame($seconds, TtmlClock::looseSeconds($expression));
    }
}
