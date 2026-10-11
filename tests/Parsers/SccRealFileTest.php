<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\SccRollUp;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SccRealFileTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/scc/real/";


    public static function realFileProvider(): array
    {
        return [
            "popon_broadcast_df"    => [
                "popon_broadcast_df.scc", 10,
                3501.131, 3503.3, ["THE NEXT TRAIN TO LAKESIDE", "LEAVES AT NINE."], Alignment::BottomCenter,
                3604.868, 3607.003, ["\u{A1}HASTA MAÑANA, ZÜRICH!"], Alignment::TopCenter,
            ],
            "rollup_news_ndf"       => [
                "rollup_news_ndf.scc", 7,
                0.2, 1.335, ["&gt;&gt; GOOD MORNING."], Alignment::BottomCenter,
                8.008, 10.01, ["&gt;&gt; NOW THE TRAFFIC.", "THE BRIDGE IS OPEN."], Alignment::BottomCenter,
            ],
            "painton_corrections"   => [
                "painton_corrections.scc", 4,
                10.143, 11.144, ["BAKERY NEWS"], Alignment::BottomCenter,
                15.148, 17.017, ["<font color=\"#00ffff\">SEE YOU TOMORROW.</font>"], Alignment::BottomCenter,
            ],
            "interleaved_pac_tab"   => [
                "interleaved_pac_tab.scc", 2,
                30.998, 33.2, ["<i>[Station announcer]</i>", "<i>The ferry to the island</i>", "<i>leaves from pier four,</i>"], Alignment::BottomCenter,
                33.267, 35.035, ["<i>not from pier two</i>", "<i>as printed in the guide.</i>"], Alignment::BottomCenter,
            ],
            "out_of_order_lines"    => [
                "out_of_order_lines.scc", 4,
                2.269, 2.436, ["the bus was late again today."], Alignment::BottomCenter,
                5.138, 5.506, ["oh"], Alignment::BottomCenter,
            ],
            "raw_data_row"          => [
                "raw_data_row.scc", 3,
                0.234, 4.004, ["OK"], Alignment::BottomCenter,
                31.665, 34.034, ["THE NEXT CAPTION IS FINE."], Alignment::BottomCenter,
            ],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        int $cueCount,
        float $firstStart,
        float $firstEnd,
        array $firstLines,
        Alignment $firstAlignment,
        float $lastStart,
        float $lastEnd,
        array $lastLines,
        Alignment $lastAlignment
    ): void {
        $cues = array_values(Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Scc)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstLines, $firstAlignment], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines(), $cues[0]->getAlignment()]);
        $last = end($cues);
        $this->assertSame([$lastStart, $lastEnd, $lastLines, $lastAlignment], [$last->getStart(), $last->getEnd(), $last->getLines(), $last->getAlignment()]);
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileDetectsAsScc(string $file): void
    {
        $this->assertSame(Format::Scc, Format::detect(file_get_contents(self::DIR . $file)));
    }


    public static function popOnFileProvider(): array
    {
        return [
            "popon_broadcast_df"  => ["popon_broadcast_df.scc"],
            "painton_corrections" => ["painton_corrections.scc"],
            "interleaved_pac_tab" => ["interleaved_pac_tab.scc"],
            "raw_data_row"        => ["raw_data_row.scc"],
        ];
    }


    #[DataProvider("popOnFileProvider")]
    public function testRealFileRoundTripKeepsCues(string $file): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Scc);
        $output   = $subtitle->toString(Format::Scc);
        $reparsed = Subtitle::fromString($output, Format::Scc);

        $this->assertSame($this->describe($subtitle), $this->describe($reparsed));
        $this->assertSame($output, $reparsed->toString(Format::Scc));
    }


    /**
     * The formatter writes pop-on captions. A pop-on caption needs time to load, so a caption can show later than in the file.
     */
    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsTextAndEndTimes(string $file): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Scc);
        $reparsed = Subtitle::fromString($subtitle->toString(Format::Scc), Format::Scc);

        $this->assertSame(count($subtitle->getCues()), count($reparsed->getCues()));
        foreach ($subtitle->getCues() as $idx => $cue) {
            $copy = $reparsed->getCues()[$idx];
            $this->assertSame([$cue->getLines(), $cue->getEnd()], [$copy->getLines(), $copy->getEnd()]);
            $this->assertGreaterThanOrEqual($cue->getStart(), $copy->getStart());
            $this->assertLessThan($copy->getEnd(), $copy->getStart());
        }
    }


    public function testDropFrameTimeCodesAcrossMinutes(): void
    {
        $cues = array_values(Subtitle::fromString(file_get_contents(self::DIR . "popon_broadcast_df.scc"), Format::Scc)->getCues());

        // 00:59:00;02 is the first frame of minute 59, frame 106094. The EOC is the 24th byte pair of the line.
        $this->assertSame(["WE HAVE <i>FRESH</i> BREAD TODAY."], $cues[5]->getLines());
        $this->assertSame(round((106094 + 23) * 1001 / 30000, 3), $cues[5]->getStart());
        $this->assertSame(["PLATFORM <u>TWO</u> NOT THREE."], $cues[8]->getLines());
        $this->assertSame(["\u{266A} SOFT PIANO MUSIC \u{266A}"], $cues[3]->getLines());
        $this->assertSame(["\u{201C}CAFÉ NORD\u{201D} \u{2014} OPEN DAILY."], $cues[7]->getLines());
        $this->assertSame(["<font color=\"#ffff00\">RAIN IS LIKELY TONIGHT.</font>", "TAKE AN UMBRELLA."], $cues[6]->getLines());
        $this->assertSame(["mode" => "pop-on", "rows" => [1], "columns" => [9]], $cues[4]->findFormatData(SccParser::FORMAT_DATA_KEY));
    }


    public function testRollUpFileShowsEachScreen(): void
    {
        $cues  = array_values(Subtitle::fromString(file_get_contents(self::DIR . "rollup_news_ndf.scc"), Format::Scc)->getCues());
        $lines = array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $cues);

        $this->assertSame(["&gt;&gt; GOOD MORNING. HERE IS THE"], $lines[1]);
        $this->assertSame(["WEATHER FOR TODAY.", "CLOUDS IN THE MORNING,", "SUN IN THE AFTERNOON."], $lines[4]);
        $this->assertSame(["roll-up", [13, 14, 15]], [$cues[4]->findFormatData(SccParser::FORMAT_DATA_KEY)["mode"], $cues[4]->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"]]);
    }


    public function testRollUpFileReadByLinesGivesEachSpokenLineOnce(): void
    {
        $options = new ReadOptions(formats: [new SccReadOptions(rollUp: SccRollUp::Lines)]);
        $cues    = Subtitle::fromString(file_get_contents(self::DIR . "rollup_news_ndf.scc"), Format::Scc, $options)->getCues();

        $this->assertSame([
            [0.2, 2.069, ["&gt;&gt; GOOD MORNING. HERE IS THE"]],
            [2.202, 3.57, ["WEATHER FOR TODAY."]],
            [3.704, 5.072, ["CLOUDS IN THE MORNING,"]],
            [5.205, 6.74, ["SUN IN THE AFTERNOON."]],
            [6.874, 8.075, ["&gt;&gt; NOW THE TRAFFIC."]],
            [8.208, 10.01, ["THE BRIDGE IS OPEN."]],
        ], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues));
    }


    public function testPaintOnFileAppliesBackspaceAndDeleteToEndOfRow(): void
    {
        $cues = array_values(Subtitle::fromString(file_get_contents(self::DIR . "painton_corrections.scc"), Format::Scc)->getCues());

        $this->assertSame(["BAKERY NEWS", "BREAD IS READY"], $cues[1]->getLines());
        $this->assertSame(["BAKERY NEWS", "BREAD IS READY SOON."], $cues[2]->getLines());
    }


    public function testRawDataRowStaysInOneRow(): void
    {
        $cues = array_values(Subtitle::fromString(file_get_contents(self::DIR . "raw_data_row.scc"), Format::Scc)->getCues());

        $this->assertCount(1, $cues[1]->getLines());
        $this->assertLessThanOrEqual(32, Markup::countCharacters(Markup::decodeEntities($cues[1]->getLines()[0])));
        $this->assertSame([27.828, 30.03], [$cues[1]->getStart(), $cues[1]->getEnd()]);
    }


    private function describe(Subtitle $subtitle): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => [
                $cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->getAlignment()->value,
                $cue->findFormatData(SccParser::FORMAT_DATA_KEY)["rows"], $cue->findFormatData(SccParser::FORMAT_DATA_KEY)["columns"],
            ],
            $subtitle->getCues()
        );
    }
}
