<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class Cea708EncoderTest extends TestCase
{
    private const FRAMES_PER_SECOND = 30;

    // A frame at 29.97 fps holds 18 DTVCC triplets, 36 bytes, of which a packet header and a block header take 2.
    private const CAPACITY = 34;


    public function testWritesPopOnCaptionsThatTheDecoderReadsBack(): void
    {
        $subtitle = (new Subtitle())->addCues([
            new SubtitleCue(1, 2, ["<i>Caf\u{E9}</i> at the <font color=\"#ffff00\">corner</font>", "\u{2026} \u{266A}"]),
            (new SubtitleCue(2, 3, "<u>Next</u> stop"))->setAlignment(Alignment::TopLeft),
            new SubtitleCue(4, 5, "\u{0150}rs"),
        ]);

        $states = (new Cea708Decoder(1))->decode(self::frames($subtitle));

        $this->assertSame([
            [1.0, ['<i>Café</i> at the <font color="#ffff00">corner</font>', "\u{2026} \u{266A}"], Alignment::BottomCenter],
            [2.0, ["<u>Next</u> stop"], Alignment::TopLeft],
            [3.0, [], null],
            [4.0, ["Ors"], Alignment::BottomCenter],
            [5.0, [], null],
        ], array_map(fn (array $state): array => [$state["time"], $state["lines"], $state["alignment"]], $states));
    }


    public function testShowsACaptionLateWhenTheFramesBeforeItAreTooFew(): void
    {
        $subtitle = (new Subtitle())->addCues([
            new SubtitleCue(0, 1, str_repeat("A", 30)),
            new SubtitleCue(0.1, 2, [str_repeat("B", 32), str_repeat("C", 32), str_repeat("D", 32), str_repeat("E", 32)]),
        ]);

        $states = (new Cea708Decoder(1))->decode(self::frames($subtitle));

        $this->assertSame([2 / 30, 9 / 30, 2.0], array_column($states, "time"));
    }


    public function testWrapsALineLongerThan42Characters(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "The coastal train to the old harbour leaves from platform two."));

        $states = (new Cea708Decoder(1))->decode(self::frames($subtitle));

        $this->assertSame(["The coastal train to the old", "harbour leaves from platform two."], $states[0]["lines"]);
    }


    public function testKeepsEachBlockWithinTheCapacity(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0.5, 2, [str_repeat("x", 40), str_repeat("y", 40)]));

        $blocks = (new Cea708Encoder())->blocks($subtitle, fn (float $seconds): int => (int) round($seconds * self::FRAMES_PER_SECOND), 14);

        $this->assertLessThanOrEqual(14, max(array_map(strlen(...), $blocks)));
        $this->assertSame(15, array_search(Cea708::DSW, array_map(fn (string $block): int => ord($block[-2]), $blocks), true));
    }


    /**
     * Returns the frames of the service 1 blocks as packets, as Mcc::readCdp() returns them.
     *
     * @return list<array{float, string}>
     */
    private static function frames(Subtitle $subtitle): array
    {
        $blocks = (new Cea708Encoder())->blocks($subtitle, fn (float $seconds): int => (int) round($seconds * self::FRAMES_PER_SECOND), self::CAPACITY);
        $frames = [];
        foreach ($blocks as $frame => $block) {
            $packet  = chr(1 << 5 | strlen($block)) . $block;
            $packet .= strlen($packet) % 2 === 0 ? "\0" : "";
            $packet  = chr(intdiv(strlen($packet) + 1, 2)) . $packet;
            $triplets = "";
            foreach (str_split($packet, 2) as $index => $pair) {
                $triplets .= chr($index === 0 ? 3 : 2) . $pair;
            }
            $frames[] = [$frame / self::FRAMES_PER_SECOND, $triplets];
        }

        return $frames;
    }
}
