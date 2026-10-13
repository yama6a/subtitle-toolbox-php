<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Alignment;

/**
 * The code sets and commands of CEA-708 (DTVCC) caption services, as Cea708Decoder and Cea708Encoder use them.
 *
 * G2 table adapted from Subtitle Edit, src/libse/Cea708/Cea708.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/58fad713d9c2b322784bc2fa52148e078dbc8759/src/libse/Cea708/Cea708.cs), MIT.
 *
 * @see https://en.wikipedia.org/wiki/CEA-708
 *
 * @internal
 */
final class Cea708
{
    // C0 codes.
    public const ETX = 0x03;
    public const BS  = 0x08;
    public const FF  = 0x0C;
    public const CR  = 0x0D;
    public const HCR = 0x0E;
    public const EXT1 = 0x10;
    public const P16 = 0x18;

    // C1 commands. Each window command takes a bitmap of the 8 windows.
    public const CW0 = 0x80;
    public const CLW = 0x88;
    public const DSW = 0x89;
    public const HDW = 0x8A;
    public const TGW = 0x8B;
    public const DLW = 0x8C;
    public const DLY = 0x8D;
    public const DLC = 0x8E;
    public const RST = 0x8F;
    public const SPA = 0x90;
    public const SPC = 0x91;
    public const SPL = 0x92;
    public const SWA = 0x97;
    public const DF0 = 0x98;

    // The parameter bytes of each C1 command from 0x80 to 0x9F.
    public const C1_PARAMETERS = [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 0, 0, 2, 3, 2, 0, 0, 0, 0, 4, 6, 6, 6, 6, 6, 6, 6, 6];

    public const MUSIC_NOTE = 0x7F;

    // The G2 characters after EXT1. 0x20 and 0x21 are the transparent spaces.
    public const G2 = [
        0x20 => " ", 0x21 => " ", 0x25 => "\u{2026}", 0x2A => "Š", 0x2C => "Œ", 0x30 => "\u{2588}", 0x31 => "\u{2018}",
        0x32 => "\u{2019}", 0x33 => "\u{201C}", 0x34 => "\u{201D}", 0x35 => "\u{2022}", 0x39 => "\u{2122}", 0x3A => "š",
        0x3C => "œ", 0x3D => "\u{2120}", 0x3F => "Ÿ", 0x76 => "\u{215B}", 0x77 => "\u{215C}", 0x78 => "\u{215D}",
        0x79 => "\u{215E}", 0x7A => "\u{2502}", 0x7B => "\u{2510}", 0x7C => "\u{2514}", 0x7D => "\u{2500}",
        0x7E => "\u{2518}", 0x7F => "\u{250C}",
    ];

    // The anchor point of a window for each alignment: 0 to 8 from the top left to the bottom right.
    public const ANCHOR_POINTS = [
        Alignment::TopLeft->value    => 0, Alignment::TopCenter->value    => 1, Alignment::TopRight->value    => 2,
        Alignment::MiddleLeft->value => 3, Alignment::MiddleCenter->value => 4, Alignment::MiddleRight->value => 5,
        Alignment::BottomLeft->value => 6, Alignment::BottomCenter->value => 7, Alignment::BottomRight->value => 8,
    ];

    // The 2 bits of a color channel and their hex values.
    public const CHANNEL_HEX = ["00", "55", "aa", "ff"];

    public const WHITE = 0x3F;
}
