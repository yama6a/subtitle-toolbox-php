<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

require_once __DIR__ . "/PgsFixtureWriter.php";
require_once __DIR__ . "/../../ocr/generator/TextBitmap.php";

use SubtitleToolbox\Ocr\TextBitmap;

/**
 * Builds the PGS test fixtures from simple shapes. generate.php writes them to tests/files/pgs/.
 */
final class PgsFixtures
{
    public const TRANSPARENT = 0;
    public const WHITE       = 1;
    public const BLACK       = 2;
    public const YELLOW      = 3;
    public const GRAY        = 4;

    /** Entry id => [Y, Cr, Cb, alpha]. YELLOW is RGB 255, 255, 0 in limited range BT.709. GRAY is half transparent. */
    public const PALETTE = [
        self::TRANSPARENT => [16, 128, 128, 0],
        self::WHITE       => [235, 128, 128, 255],
        self::BLACK       => [16, 128, 128, 255],
        self::YELLOW      => [219, 138, 16, 255],
        self::GRAY        => [126, 128, 128, 128],
    ];

    public const FILES = [
        "shapes_1080p.sup" => "shapes1080p",
        "shapes_576p.sup"  => "shapes576p",
        "text_1080p.sup"          => "text1080p",
        "text_cyrillic_1080p.sup" => "textCyrillic1080p",
        "authoring_layout_1080p.sup" => "authoringLayout1080p",
        "fade_layout_1080p.sup"      => "fadeLayout1080p",
    ];

    private const WHITE_RGB  = [255, 255, 255];
    private const YELLOW_RGB = [255, 255, 0];

    /** Start and end in seconds, the text lines with <i> runs, the font size in pixels, the fill and the top position. */
    public const TEXT_CUES = [
        [1.0, 3.5, ["The train to Bergen leaves at 7:45."], 52, self::WHITE_RGB, false],
        [4.0, 7.0, ["The bakery opens at six.", "Fresh bread is ready by seven."], 52, self::WHITE_RGB, false],
        [7.5, 10.0, ["Rain is likely after 3 p.m. today."], 48, self::WHITE_RGB, false],
        [10.5, 13.0, ["Platform 4, please.", "Watch your step."], 56, self::YELLOW_RGB, false],
        [13.5, 16.0, ["<i>The wind turns north tonight.</i>"], 52, self::WHITE_RGB, false],
        [16.5, 19.0, ["We sold 120 rolls before noon!"], 60, self::WHITE_RGB, false],
        [19.5, 22.5, ["Is the 9:10 bus late again?", "Yes, by about five minutes."], 48, self::WHITE_RGB, false],
        [23.0, 25.5, ["<i>Snow</i> is expected on Friday."], 52, self::WHITE_RGB, true],
        [26.0, 28.5, ["Two loaves of rye, one baguette."], 44, self::WHITE_RGB, false],
        [29.0, 31.5, ["The next stop is Central Station."], 52, self::YELLOW_RGB, false],
        [32.0, 35.0, ["Temperatures stay near 18 degrees.", "Light clouds in the evening."], 50, self::WHITE_RGB, false],
        [35.5, 38.0, ["<i>Tickets cost 4.50 each.</i>"], 56, self::WHITE_RGB, false],
    ];

    /** The cues of TEXT_CUES in Russian, for OCR of a script other than Latin. */
    public const CYRILLIC_CUES = [
        [1.0, 3.5, ["Поезд в Берген уходит в 7:45."], 52, self::WHITE_RGB, false],
        [4.0, 7.0, ["Пекарня открывается в шесть.", "Свежий хлеб готов к семи."], 52, self::WHITE_RGB, false],
        [7.5, 10.0, ["Сегодня после 15 часов ожидается дождь."], 48, self::WHITE_RGB, false],
        [10.5, 13.0, ["Платформа 4, пожалуйста.", "Осторожно, ступенька."], 56, self::YELLOW_RGB, false],
        [13.5, 16.0, ["<i>Ночью ветер повернёт на север.</i>"], 52, self::WHITE_RGB, false],
        [16.5, 19.0, ["До полудня мы продали 120 булочек!"], 60, self::WHITE_RGB, false],
        [19.5, 22.5, ["Автобус в 9:10 опять опаздывает?", "Да, примерно на пять минут."], 48, self::WHITE_RGB, false],
        [23.0, 25.5, ["<i>Снег</i> ожидается в пятницу."], 52, self::WHITE_RGB, true],
        [26.0, 28.5, ["Две буханки ржаного и один багет."], 44, self::WHITE_RGB, false],
        [29.0, 31.5, ["Следующая остановка: Центральный вокзал."], 52, self::YELLOW_RGB, false],
        [32.0, 35.0, ["Температура около 18 градусов.", "Вечером лёгкая облачность."], 50, self::WHITE_RGB, false],
        [35.5, 38.0, ["<i>Билет стоит 45 рублей.</i>"], 56, self::WHITE_RGB, false],
    ];


    /**
     * A subtitle bar: a white box with a 3 pixel black outline, a yellow square in the middle and a dotted line.
     */
    public static function bar(int $width, int $height): string
    {
        $pixels = PgsFixtureWriter::canvas($width, $height);
        $pixels = PgsFixtureWriter::outlinedBox($pixels, $width, 10, 10, $width - 20, $height - 20, 3, self::BLACK, self::WHITE);
        $pixels = PgsFixtureWriter::rectangle($pixels, $width, intdiv($width, 2) - 10, intdiv($height, 2) - 10, 20, 20, self::YELLOW);

        return PgsFixtureWriter::dottedLine($pixels, $width, 20, $height - 16, $width - 40, self::BLACK, self::GRAY);
    }


    /**
     * Six cues on a 1920x1080 screen. Each display set tests one feature, see PgsParserTest.
     */
    public static function shapes1080p(): string
    {
        $w = new PgsFixtureWriter();

        // 1 s to 3.5 s: one 640x90 object at 640, 940. A display set without objects ends it.
        $w->presentation(90000, 1920, 1080, 1, PgsFixtureWriter::STATE_EPOCH_START, 0,
                         [["id" => 0, "window" => 0, "x" => 640, "y" => 940]])
          ->windows(90000, [0 => [640, 940, 640, 90]])
          ->palette(90000, 0, 0, self::PALETTE)
          ->object(90000, 0, 0, 640, 90, self::bar(640, 90))
          ->end(90000);
        $w->presentation(315000, 1920, 1080, 2, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows(315000, [0 => [640, 940, 640, 90]])
          ->end(315000);

        // 5 s to 7 s: two objects, one at the top and one at the bottom, in two windows.
        $w->presentation(450000, 1920, 1080, 3, PgsFixtureWriter::STATE_EPOCH_START, 0, [
            ["id" => 0, "window" => 0, "x" => 760, "y" => 60],
            ["id" => 1, "window" => 1, "x" => 660, "y" => 950],
        ])
          ->windows(450000, [0 => [760, 60, 400, 70], 1 => [660, 950, 600, 80]])
          ->palette(450000, 0, 0, self::PALETTE)
          ->object(450000, 0, 0, 400, 70, self::bar(400, 70))
          ->object(450000, 1, 0, 600, 80, self::bar(600, 80))
          ->end(450000);
        $w->presentation(630000, 1920, 1080, 4, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows(630000, [0 => [760, 60, 400, 70], 1 => [660, 950, 600, 80]])
          ->end(630000);

        // 8 s to 9.5 s: a forced object at the top, split over several object definition segments.
        // 9.5 s to 11 s: a palette update turns the white fill gray.
        $w->presentation(720000, 1920, 1080, 5, PgsFixtureWriter::STATE_EPOCH_START, 1,
                         [["id" => 0, "window" => 0, "x" => 560, "y" => 50, "forced" => true]])
          ->windows(720000, [0 => [560, 50, 800, 100]])
          ->palette(720000, 1, 0, self::PALETTE)
          ->object(720000, 0, 0, 800, 100, self::bar(800, 100), 300)
          ->end(720000);
        $w->presentation(855000, 1920, 1080, 6, PgsFixtureWriter::STATE_NORMAL, 1,
                         [["id" => 0, "window" => 0, "x" => 560, "y" => 50, "forced" => true]], true)
          ->palette(855000, 1, 1, [self::WHITE => self::PALETTE[self::GRAY]])
          ->end(855000);
        $w->presentation(990000, 1920, 1080, 7, PgsFixtureWriter::STATE_NORMAL, 1, [])
          ->windows(990000, [0 => [560, 50, 800, 100]])
          ->end(990000);

        // 12 s to 16 s: a cropped object and a segment of unknown type 0x18.
        // At 14 s an acquisition point repeats the same image, so the cue goes on.
        foreach ([[1080000, 8, PgsFixtureWriter::STATE_EPOCH_START], [1260000, 9, PgsFixtureWriter::STATE_ACQUISITION_POINT]] as [$pts, $number, $state]) {
            $w->presentation($pts, 1920, 1080, $number, $state, 0,
                             [["id" => 2, "window" => 0, "x" => 860, "y" => 900, "crop" => [100, 20, 200, 60]]])
              ->windows($pts, [0 => [860, 900, 200, 60]])
              ->segment($pts, 0x18, "unknown")
              ->palette($pts, 0, 0, self::PALETTE)
              ->object($pts, 2, 0, 400, 100, self::bar(400, 100))
              ->end($pts);
        }
        $w->presentation(1440000, 1920, 1080, 10, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows(1440000, [0 => [860, 900, 200, 60]])
          ->end(1440000);

        // 20 s: a last cue that no display set ends.
        $w->presentation(1800000, 1920, 1080, 11, PgsFixtureWriter::STATE_EPOCH_START, 0,
                         [["id" => 0, "window" => 0, "x" => 640, "y" => 940]])
          ->windows(1800000, [0 => [640, 940, 640, 90]])
          ->palette(1800000, 0, 0, self::PALETTE)
          ->object(1800000, 0, 0, 640, 90, self::bar(640, 90))
          ->end(1800000);

        return $w->bytes();
    }


    /**
     * Two cues on a 720x576 screen, where the palette uses the BT.601 matrix.
     */
    public static function shapes576p(): string
    {
        $w = new PgsFixtureWriter();
        foreach ([[45000, 1, 270000], [360000, 3, null]] as [$start, $number, $end]) {
            $w->presentation($start, 720, 576, $number, PgsFixtureWriter::STATE_EPOCH_START, 0,
                             [["id" => 0, "window" => 0, "x" => 160, "y" => 480]])
              ->windows($start, [0 => [160, 480, 400, 60]])
              ->palette($start, 0, 0, self::PALETTE)
              ->object($start, 0, 0, 400, 60, self::bar(400, 60))
              ->end($start);
            if ($end !== null) {
                $w->presentation($end, 720, 576, $number + 1, PgsFixtureWriter::STATE_NORMAL, 0, [])
                  ->windows($end, [0 => [160, 480, 400, 60]])
                  ->end($end);
            }
        }

        return $w->bytes();
    }


    /**
     * Three cues in the segment layout of a Blu-ray authoring tool: every display set is an epoch start, also the one
     * that clears the screen, and every palette defines all 256 entries.
     * The cues are a line of blocks, a bar that uses each of the 256 entries, and one line of text.
     */
    public static function authoringLayout1080p(): string
    {
        $palette = [self::TRANSPARENT => self::PALETTE[self::TRANSPARENT]];
        for ($entry = 1; $entry < 256; $entry++) {
            $rgb             = [$entry * 53 % 256 / 255, $entry * 97 % 256 / 255, $entry * 29 % 256 / 255];
            $palette[$entry] = [...self::ycrcb709($rgb), $entry % 4 === 0 ? 128 : 255];
        }
        $gradient = "";
        for ($row = 0; $row < 40; $row++) {
            $gradient .= implode("", array_map("chr", range(0, 255)));
        }
        [$text, $textPalette, $textWidth, $textHeight] = self::textObject(TextBitmap::render(["Gate 12 closes at 9:40."], 52, 52 / 16, 2), self::WHITE_RGB);

        $cues = [
            [1.0, 3.0, 640, 90, self::blocks(5), $palette],
            [3.5, 6.0, 256, 40, $gradient, $palette],
            [6.5, 9.0, $textWidth, $textHeight, $text, $textPalette + array_fill(0, 256, [16, 128, 128, 0])],
        ];

        $w = new PgsFixtureWriter();
        foreach ($cues as $index => [$start, $end, $width, $height, $pixels, $entries]) {
            $x        = intdiv(1920 - $width, 2);
            $y        = 1000 - $height;
            $startPts = (int)round($start * 90000);
            $endPts   = (int)round($end * 90000);
            ksort($entries);
            $w->presentation($startPts, 1920, 1080, 2 * $index, PgsFixtureWriter::STATE_EPOCH_START, 0,
                             [["id" => 0, "window" => 0, "x" => $x, "y" => $y]])
              ->windows($startPts, [0 => [$x, $y, $width, $height]])
              ->palette($startPts, 0, 0, $entries)
              ->object($startPts, 0, 0, $width, $height, $pixels)
              ->end($startPts)
              ->presentation($endPts, 1920, 1080, 2 * $index + 1, PgsFixtureWriter::STATE_EPOCH_START, 0, [])
              ->windows($endPts, [0 => [$x, $y, $width, $height]])
              ->end($endPts);
        }

        return $w->bytes();
    }


    /**
     * Two cues in the segment layout of BDSup2Sub and SUPer: two objects in one composition, windows larger than the
     * objects, and a fade out of display sets in the normal state that only update the palette.
     */
    public static function fadeLayout1080p(): string
    {
        $objects = [["id" => 0, "window" => 0, "x" => 640, "y" => 820], ["id" => 1, "window" => 1, "x" => 640, "y" => 940]];
        $windows = [0 => [620, 810, 680, 110], 1 => [620, 930, 680, 110]];

        $w = new PgsFixtureWriter();
        foreach ([[90000, 0], [630000, 10]] as [$start, $number]) {
            $w->presentation($start, 1920, 1080, $number, PgsFixtureWriter::STATE_EPOCH_START, 0, $objects)
              ->windows($start, $windows)
              ->palette($start, 0, 0, self::PALETTE)
              ->object($start, 0, 0, 640, 90, self::blocks($number + 3))
              ->object($start, 1, 0, 640, 90, self::blocks($number + 9))
              ->end($start);
            // Fade out over 3 steps of 0.1 s. Each step sets lower alpha values and keeps the objects.
            foreach ([3, 2, 1] as $step => $quarters) {
                $pts     = $start + 180000 + 9000 * $step;
                $entries = array_map(fn (array $entry): array => [$entry[0], $entry[1], $entry[2], intdiv($entry[3] * $quarters, 4)], self::PALETTE);
                $w->presentation($pts, 1920, 1080, $number + 1 + $step, PgsFixtureWriter::STATE_NORMAL, 0, $objects, true)
                  ->palette($pts, 0, $step + 1, $entries)
                  ->end($pts);
            }
            $w->presentation($start + 207000, 1920, 1080, $number + 4, PgsFixtureWriter::STATE_NORMAL, 0, [])
              ->windows($start + 207000, $windows)
              ->end($start + 207000);
        }

        return $w->bytes();
    }


    /**
     * Text cues in Liberation Sans on a 1920x1080 screen, with a black outline and anti-aliased edges, see TEXT_CUES.
     */
    public static function text1080p(): string
    {
        return self::textCues1080p(self::TEXT_CUES);
    }


    /**
     * The Russian text cues of CYRILLIC_CUES, drawn as text1080p() draws TEXT_CUES.
     */
    public static function textCyrillic1080p(): string
    {
        return self::textCues1080p(self::CYRILLIC_CUES);
    }


    /**
     * @param list<array{float, float, list<string>, int, array{int, int, int}, bool}> $cues
     */
    private static function textCues1080p(array $cues): string
    {
        $w = new PgsFixtureWriter();
        foreach ($cues as $index => [$start, $end, $lines, $size, $fill, $top]) {
            [$pixels, $palette, $width, $height] = self::textObject(TextBitmap::render($lines, $size, $size / 16, 2), $fill);
            $x        = intdiv(1920 - $width, 2);
            $y        = $top ? 60 : 1020 - $height;
            $startPts = (int)round($start * 90000);
            $endPts   = (int)round($end * 90000);
            $w->presentation($startPts, 1920, 1080, 2 * $index, PgsFixtureWriter::STATE_EPOCH_START, 0,
                             [["id" => 0, "window" => 0, "x" => $x, "y" => $y]])
              ->windows($startPts, [0 => [$x, $y, $width, $height]])
              ->palette($startPts, 0, 0, $palette)
              ->object($startPts, 0, 0, $width, $height, $pixels)
              ->end($startPts)
              ->presentation($endPts, 1920, 1080, 2 * $index + 1, PgsFixtureWriter::STATE_NORMAL, 0, [])
              ->windows($endPts, [0 => [$x, $y, $width, $height]])
              ->end($endPts);
        }

        return $w->bytes();
    }


    /**
     * Quantizes fill and outline coverage to 16 levels each, so the palette has at most 136 entries.
     *
     * @param array{int, int, int} $fill
     * @return array{string, array<int, array{int, int, int, int}>, int, int} the pixels, the palette, the width and the height
     */
    public static function textObject(TextBitmap $bitmap, array $fill): array
    {
        $palette = [self::TRANSPARENT => self::PALETTE[self::TRANSPARENT]];
        $lookup  = [];
        $pixels  = "";
        foreach ($bitmap->fill as $index => $fillCoverage) {
            $f     = self::roundHalfUp($fillCoverage * 15) / 15;
            $o     = max($f, self::roundHalfUp($bitmap->outline[$index] * 15) / 15);
            $alpha = $f + $o * (1 - $f);
            if ($alpha <= 0.0) {
                $pixels .= chr(self::TRANSPARENT);
                continue;
            }
            $key = "$f/$o";
            if (!isset($lookup[$key])) {
                $rgb          = array_map(fn (int $channel): float => $channel * $f / $alpha / 255, $fill);
                $lookup[$key] = count($palette);
                $palette[]    = [...self::ycrcb709($rgb), self::roundHalfUp($alpha * 255)];
            }
            $pixels .= chr($lookup[$key]);
        }

        return [$pixels, $palette, $bitmap->width, $bitmap->height];
    }


    /**
     * Converts red, green and blue from 0 to 1 to limited range BT.709 [Y, Cr, Cb].
     *
     * @param list<float> $rgb
     * @return array{int, int, int}
     */
    private static function ycrcb709(array $rgb): array
    {
        [$red, $green, $blue] = $rgb;
        $luma                 = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;

        return [self::roundHalfUp(16 + 219 * $luma), self::roundHalfUp(128 + 224 * ($red - $luma) / 1.5748),
                self::roundHalfUp(128 + 224 * ($blue - $luma) / 1.8556)];
    }


    // PHP 8.4 changed round() for values close to .5, so round() gives other bytes on PHP 8.2.
    private static function roundHalfUp(float $value): int
    {
        return (int)floor($value + 0.5);
    }


    /**
     * A 640x90 line of 24 outlined blocks of different widths, close to the shape of a line of text.
     */
    public static function blocks(int $seed): string
    {
        $pixels = PgsFixtureWriter::canvas(640, 90);
        $x      = 8;
        for ($index = 0; $index < 24; $index++) {
            $width  = 12 + ($seed + 7 * $index) % 13;
            $height = 30 + ($seed + 5 * $index) % 25;
            $pixels = PgsFixtureWriter::outlinedBox($pixels, 640, $x, 75 - $height, $width, $height, 2, self::BLACK, self::WHITE);
            $x     += $width + 2 + ($index % 5 === 4 ? 10 : 0);
        }

        return $pixels;
    }


    /**
     * $count cues of a 640x90 line of blocks on a 1920x1080 screen, each shown for 2 s with a 1 s gap.
     */
    public static function manyCues(int $count): string
    {
        $w = new PgsFixtureWriter();
        for ($index = 0; $index < $count; $index++) {
            $start = $index * 270000;
            $w->presentation($start, 1920, 1080, 2 * $index, PgsFixtureWriter::STATE_EPOCH_START, 0,
                             [["id" => 0, "window" => 0, "x" => 640, "y" => 940]])
              ->windows($start, [0 => [640, 940, 640, 90]])
              ->palette($start, 0, 0, self::PALETTE)
              ->object($start, 0, 0, 640, 90, self::blocks($index))
              ->end($start)
              ->presentation($start + 180000, 1920, 1080, 2 * $index + 1, PgsFixtureWriter::STATE_NORMAL, 0, [])
              ->end($start + 180000);
        }

        return $w->bytes();
    }
}
