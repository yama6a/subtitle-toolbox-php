<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use GlyphOcr\GlyphDatabase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;

/**
 * The settings of GlyphOcrEngine. docs/ocr.md#php-glyph-ocr lists the allowed values.
 * Each field except $database matches the GlyphOcr\Recognizer parameter of the same name in php-glyph-ocr 1.0.
 */
final readonly class GlyphOcrOptions
{
    /**
     * @param GlyphDatabase|null $database       the glyphs to match, or null for GlyphDatabase::subtitleFonts()
     * @param int                $inkThreshold   the least sum of premultiplied red, green and blue that makes a pixel ink
     * @param int|null           $spaceWidth     the empty columns between two glyphs that make a space, or null to derive it from the line
     * @param int                $maxWrongPixels the pixels that a loose match may get wrong
     * @param bool               $fixLatinCase   picks upper or lower case for letters such as o and O from their height
     * @param string             $unknownText    the text of a glyph that matches no glyph of the database
     * @param float              $italicSlant    the factor by which a glyph that matches nothing is slanted back and tried again
     * @param bool               $rightToLeft    puts the glyphs of each line in right to left order
     * @param int                $minLineHeight  the minimum line height in pixels until the engine has learned the glyph heights
     * @param bool               $lineContext    compares each glyph with the other glyphs of its line, for example to pick I or l
     */
    public function __construct(
        public ?GlyphDatabase $database = null,
        public int $inkThreshold = 200,
        public ?int $spaceWidth = null,
        public int $maxWrongPixels = 25,
        public bool $fixLatinCase = true,
        public string $unknownText = "*",
        public float $italicSlant = 0.0,
        public bool $rightToLeft = false,
        public int $minLineHeight = 12,
        public bool $lineContext = true,
    ) {
        if ($inkThreshold < 1 || $inkThreshold > 765) {
            throw new InvalidArgumentException("The ink threshold must be from 1 to 765, got $inkThreshold.");
        }
        if ($spaceWidth !== null && $spaceWidth < 1) {
            throw new InvalidArgumentException("The space width must be at least 1, got $spaceWidth.");
        }
        if ($maxWrongPixels < 0) {
            throw new InvalidArgumentException("The number of wrong pixels must be at least 0, got $maxWrongPixels.");
        }
        OptionChecks::between($italicSlant, 0, 1, "The italic slant must be from 0 to 1, got %s.");
        if ($minLineHeight < 1) {
            throw new InvalidArgumentException("The minimum line height must be at least 1, got $minLineHeight.");
        }
    }
}
