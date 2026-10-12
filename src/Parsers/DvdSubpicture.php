<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;

/**
 * Decodes one DVD subpicture unit (SPU): its control sequences and its 2-bit run-length bitmap.
 * VobSubParser reads the units from a .sub file or from the blocks of a Matroska S_VOBSUB track.
 *
 * SPU layout: http://sam.zoy.org/writings/dvd/subtitles/ and http://dvd.sourceforge.net/dvdinfo/spu.html
 * Decoder: https://github.com/FFmpeg/FFmpeg/blob/master/libavcodec/dvdsubdec.c
 *
 * @internal
 */
final class DvdSubpicture
{
    // SP_DCSQ_STM delays count in units of 1024 ticks of the 90 kHz clock.
    private const SECONDS_PER_DELAY_UNIT = 1024 / 90000;

    // The SP_DCSQ commands of the DVD-Video subpicture unit.
    private const FSTA_DSP   = 0x00;
    private const STA_DSP    = 0x01;
    private const STP_DSP    = 0x02;
    private const SET_COLOR  = 0x03;
    private const SET_CONTR  = 0x04;
    private const SET_DAREA  = 0x05;
    private const SET_DSPXA  = 0x06;
    private const CHG_COLCON = 0x07;
    private const CMD_END    = 0xFF;

    private const ARGUMENT_SIZES = [self::SET_COLOR => 2, self::SET_CONTR => 2, self::SET_DAREA => 6, self::SET_DSPXA => 4];

    // Turns a 4-bit contrast from 0 to 15 into an 8-bit alpha from 0 to 255.
    private const NIBBLE_TO_BYTE = 17;

    private ?float $startDelay = null;
    private ?float $stopDelay  = null;
    private bool $forced       = false;

    /** @var list<int> palette indexes by pixel value */
    private array $colorIndexes = [0, 0, 0, 0];

    /** @var list<int> contrast from 0 (transparent) to 15 (opaque) by pixel value */
    private array $contrasts = [0, 0, 0, 0];

    /** @var array{int, int, int, int}|null x1, x2, y1, y2 */
    private ?array $area = null;

    /** @var array{int, int}|null byte offsets of the top field and the bottom field */
    private ?array $fieldOffsets = null;


    private function __construct(private readonly string $unit)
    {
    }


    /**
     * Runs the control sequences of $unit and decodes its bitmap. The image is null when the unit sets no display area
     * or no bitmap, as in a unit that only clears the screen.
     *
     * @param list<int>      $pixelPalette 16 colors as 0xRRGGBB, from the .idx palette line
     * @param list<int>|null $customColors 0xRRGGBBAA by pixel value, from an enabled .idx "custom colors" line
     * @return array{startDelay: float, stopDelay: ?float, image: ?CueImage} delays in seconds
     */
    public static function decode(string $unit, array $pixelPalette, ?array $customColors, int $screenWidth, int $screenHeight): array
    {
        $subpicture = new self($unit);
        $subpicture->runSequences();

        $image = null;
        $area  = $subpicture->area;
        if ($area !== null && $subpicture->fieldOffsets !== null && $area[1] >= $area[0] && $area[3] >= $area[2]) {
            $image = $subpicture->decodeImage($customColors ?? $subpicture->pixelColors($pixelPalette), $screenWidth, $screenHeight);
        }

        return ["startDelay" => $subpicture->startDelay ?? 0.0, "stopDelay" => $subpicture->stopDelay, "image" => $image];
    }


    private function runSequences(): void
    {
        $size     = strlen($this->unit);
        $sequence = $size >= 4 ? unpack("n", $this->unit, 2)[1] : 0;
        if ($sequence < 4 || $sequence + 4 > $size) {
            throw new ParsingException("The subtitle packet of $size bytes has no valid control sequence offset.");
        }

        while (true) {
            $delay = unpack("n", $this->unit, $sequence)[1] * self::SECONDS_PER_DELAY_UNIT;
            $next  = unpack("n", $this->unit, $sequence + 2)[1];
            $this->runSequence($sequence + 4, $delay);

            if ($next <= $sequence || $next + 4 > $size) {
                break;
            }
            $sequence = $next;
        }
    }


    /**
     * Runs the commands of one control sequence from $position on.
     */
    private function runSequence(int $position, float $delay): void
    {
        $unit = $this->unit;
        $size = strlen($unit);
        // Sequences after the start sequence animate the colors or the area, which one cue image cannot hold.
        $shown = $this->startDelay !== null;

        while ($position < $size && ($command = ord($unit[$position++])) !== self::CMD_END) {
            $argumentSize = self::ARGUMENT_SIZES[$command] ?? 0;
            if ($position + $argumentSize > $size) {
                throw new ParsingException("The subtitle packet ends inside command " . sprintf("%02x", $command) . ".");
            }
            $arguments = array_values(unpack("C*", substr($unit, $position, $argumentSize)) ?: []);

            if ($command === self::FSTA_DSP || $command === self::STA_DSP) {
                $this->startDelay ??= $delay;
                $this->forced       = $this->forced || $command === self::FSTA_DSP;
            } elseif ($command === self::STP_DSP) {
                $this->stopDelay ??= $delay;
            } elseif ($command === self::CHG_COLCON) {
                $argumentSize = $position + 2 <= $size ? unpack("n", $unit, $position)[1] : $size;
            } elseif ($command > self::CHG_COLCON) {
                break;
            } elseif (!$shown) {
                $this->setDisplay($command, $arguments);
            }
            $position += $argumentSize;
        }
    }


    /**
     * Runs a SET_COLOR, SET_CONTR, SET_DAREA or SET_DSPXA command.
     *
     * @param list<int> $arguments
     */
    private function setDisplay(int $command, array $arguments): void
    {
        if ($command === self::SET_COLOR) {
            $this->colorIndexes = self::readNibbles($arguments);
        } elseif ($command === self::SET_CONTR) {
            $this->contrasts = self::readNibbles($arguments);
        } elseif ($command === self::SET_DAREA) {
            $this->area = [
                $arguments[0] << 4 | $arguments[1] >> 4,
                ($arguments[1] & 0x0F) << 8 | $arguments[2],
                $arguments[3] << 4 | $arguments[4] >> 4,
                ($arguments[4] & 0x0F) << 8 | $arguments[5],
            ];
        } elseif ($command === self::SET_DSPXA) {
            $this->fieldOffsets = [$arguments[0] << 8 | $arguments[1], $arguments[2] << 8 | $arguments[3]];
        }
    }


    /**
     * Returns the four nibbles of a SET_COLOR or SET_CONTR argument, ordered by pixel value.
     * The order is background, pattern, emphasis 1 and emphasis 2.
     *
     * @param list<int> $bytes
     * @return list<int>
     */
    private static function readNibbles(array $bytes): array
    {
        return [$bytes[1] & 0x0F, $bytes[1] >> 4, $bytes[0] & 0x0F, $bytes[0] >> 4];
    }


    /**
     * @param list<int> $pixelPalette 0xRRGGBB
     * @return list<int> 0xRRGGBBAA by pixel value
     */
    private function pixelColors(array $pixelPalette): array
    {
        return array_map(fn (int $colorIndex, int $contrast): int => ($pixelPalette[$colorIndex] ?? 0) << 8 | $contrast * self::NIBBLE_TO_BYTE,
                         $this->colorIndexes, $this->contrasts);
    }


    /**
     * Decodes the 2-bit run-length bitmap. The top field holds the even lines and the bottom field the odd lines.
     *
     * @param list<int> $pixelColors 0xRRGGBBAA by pixel value
     */
    private function decodeImage(array $pixelColors, int $screenWidth, int $screenHeight): CueImage
    {
        [$x, $x2, $y, $y2] = $this->area;
        $width             = $x2 - $x + 1;
        $height            = $y2 - $y + 1;
        try {
            CueImage::checkSize($width, $height, "read the subtitle packet");
        } catch (InvalidArgumentException $exception) {
            throw new ParsingException($exception->getMessage());
        }
        $pixels = array_fill(0, $width * $height, $pixelColors[0]);
        foreach ($this->fieldOffsets as $field => $offset) {
            $nibble = $offset * 2;
            for ($row = $field; $row < $height; $row += 2) {
                self::decodeRow($this->unit, $nibble, $row, $width, $pixelColors, $pixels);
                $nibble += $nibble & 1;
            }
        }

        return new CueImage(PngEncoder::encode($width, $height, $pixels), $x, $y, $width, $height, $screenWidth, $screenHeight, $this->forced);
    }


    /**
     * @param list<int> $pixelColors
     * @param list<int> $pixels
     */
    private static function decodeRow(string $unit, int &$nibble, int $row, int $width, array $pixelColors, array &$pixels): void
    {
        $column = 0;
        while ($column < $width) {
            $code  = self::readCode($unit, $nibble, $row);
            $run   = $code >> 2 === 0 ? $width - $column : min($code >> 2, $width - $column);
            $color = $pixelColors[$code & 0x03];
            $first = $row * $width + $column;
            for ($pixel = $first; $pixel < $first + $run; $pixel++) {
                $pixels[$pixel] = $color;
            }
            $column += $run;
        }
    }


    /**
     * Reads one run-length code of 1 to 4 nibbles.
     */
    private static function readCode(string $unit, int &$nibble, int $row): int
    {
        $nibbleEnd = strlen($unit) * 2;
        $code      = 0;
        foreach ([0x4, 0x10, 0x40, 0x100] as $limit) {
            if ($nibble >= $nibbleEnd) {
                throw new ParsingException("The bitmap of the subtitle packet ends before line $row is complete.");
            }
            $code = $code << 4 | (ord($unit[$nibble >> 1]) >> ($nibble & 1 ? 0 : 4) & 0x0F);
            $nibble++;
            if ($code >= $limit) {
                break;
            }
        }

        return $code;
    }
}
