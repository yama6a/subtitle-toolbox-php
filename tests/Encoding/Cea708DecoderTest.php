<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;

class Cea708DecoderTest extends TestCase
{
    // Define window 0 visible at the bottom center with 2 rows of 32 columns, and window 1 hidden at the top left.
    private const VISIBLE_WINDOW_0 = "\x98\x38\xDA\x32\x71\x1F\x09";
    private const HIDDEN_WINDOW_1  = "\x99\x18\x8A\x0A\x00\x1F\x09";


    public static function serviceData(): array
    {
        return [
            "G0, G1, G2 and the music note" => [[self::VISIBLE_WINDOW_0 . "Caf\xE9 \x10\x25 \x7F"], [[0.0, ["Café \u{2026} \u{266A}"], Alignment::BottomCenter]]],
            "pop-on with a hidden window"   => [[self::HIDDEN_WINDOW_1 . "Hidden", "\x89\x02"], [[1.0, ["Hidden"], Alignment::TopLeft]]],
            "toggle and hide"               => [[self::HIDDEN_WINDOW_1 . "Two", "\x8B\x02", "\x8A\x02"],
                                                [[1.0, ["Two"], Alignment::TopLeft], [2.0, [], null]]],
            "clear and delete"              => [[self::VISIBLE_WINDOW_0 . "One", "\x88\x01", self::VISIBLE_WINDOW_0 . "Two", "\x8C\x01"],
                                                [[0.0, ["One"], Alignment::BottomCenter], [1.0, [], null], [2.0, ["Two"], Alignment::BottomCenter],
                                                 [3.0, [], null]]],
            "carriage return rolls up"      => [[self::VISIBLE_WINDOW_0 . "A\rB", "\rC"],
                                                [[0.0, ["A", "B"], Alignment::BottomCenter], [1.0, ["B", "C"], Alignment::BottomCenter]]],
            "backspace and row clear"       => [[self::VISIBLE_WINDOW_0 . "ABC\x08\x08D\rEF\x0EG"], [[0.0, ["AD", "G"], Alignment::BottomCenter]]],
            "form feed"                     => [[self::VISIBLE_WINDOW_0 . "Old\x0CNew"], [[0.0, ["New"], Alignment::BottomCenter]]],
            "pen location"                  => [[self::VISIBLE_WINDOW_0 . "\x92\x01\x00Low\x92\x00\x02Up"], [[0.0, ["Up", "Low"], Alignment::BottomCenter]]],
            "pen styles"                    => [[self::VISIBLE_WINDOW_0 . "\x90\x01\x80it\x90\x01\x40un\x90\x01\x00\x91\x30\x00\x00red"],
                                                [[0.0, ['<i>it</i><u>un</u><font color="#ff0000">red</font>'], Alignment::BottomCenter]]],
            "codes that hold no text"       => [[self::VISIBLE_WINDOW_0, "a\x11\x41b\x18\xAC\x00c\x10\x08\x41d", "\x10\x80\x01\x02\x03\x04e\x10\x90\x02\xAA\xBBf\x10\xA0g\x8D\x05h"],
                                                [[1.0, ["abcd"], Alignment::BottomCenter], [2.0, ["abcdefgh"], Alignment::BottomCenter]]],
            "reset and current window"      => [[self::VISIBLE_WINDOW_0 . self::HIDDEN_WINDOW_1 . "x\x80y", "\x8F"],
                                                [[0.0, ["y"], Alignment::BottomCenter], [1.0, [], null]]],
        ];
    }


    /**
     * @param list<string> $blocks the service 1 data of one packet per second
     */
    #[DataProvider("serviceData")]
    public function testDecodesTheTextOfTheVisibleWindows(array $blocks, array $states): void
    {
        $frames = [];
        foreach ($blocks as $second => $block) {
            $frames[] = [(float) $second, self::packet(1, $block)];
        }

        $this->assertSame($states, array_map(fn (array $state): array => [$state["time"], $state["lines"], $state["alignment"]],
                                              (new Cea708Decoder(1))->decode($frames)));
    }


    public function testReadsOnlyItsServiceAndTheExtendedServiceNumbers(): void
    {
        $frames = [[0.0, self::packet(2, self::VISIBLE_WINDOW_0 . "Two")], [1.0, self::packet(9, self::VISIBLE_WINDOW_0 . "Nine")]];

        $this->assertSame([["Two"]], array_column((new Cea708Decoder(2))->decode($frames), "lines"));
        $this->assertSame([["Nine"]], array_column((new Cea708Decoder(9))->decode($frames), "lines"));
        $this->assertSame([], (new Cea708Decoder(1))->decode($frames));
    }


    public function testReadsAPacketThatSpansFrames(): void
    {
        $packet = self::packet(1, self::VISIBLE_WINDOW_0 . "Split");

        $states = (new Cea708Decoder(1))->decode([[0.0, substr($packet, 0, 9)], [0.5, substr($packet, 9)]]);

        $this->assertSame([[0.5, ["Split"]]], array_map(fn (array $state): array => [$state["time"], $state["lines"]], $states));
    }


    public function testReadsAnAbsoluteAnchor(): void
    {
        // Row 70 of 75 with the anchor point at the bottom right.
        $states = (new Cea708Decoder(1))->decode([[0.0, self::packet(1, "\x98\x38\x46\xC8\x80\x1F\x09Right")]]);

        $this->assertSame(Alignment::BottomRight, $states[0]["alignment"]);
    }


    /**
     * Returns the triplets of a DTVCC packet with one service block, as Mcc::readCdp() returns them.
     */
    private static function packet(int $service, string $block): string
    {
        $header  = $service < 7 ? chr($service << 5 | strlen($block)) : chr(7 << 5 | strlen($block)) . chr($service);
        $packet  = $header . $block;
        $packet .= strlen($packet) % 2 === 0 ? "\0" : "";
        $packet  = chr(intdiv(strlen($packet) + 1, 2)) . $packet;

        $triplets = "";
        foreach (str_split($packet, 2) as $index => $pair) {
            $triplets .= chr($index === 0 ? 3 : 2) . $pair;
        }

        return $triplets;
    }
}
