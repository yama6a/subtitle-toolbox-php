<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use GlyphOcr\Recognizer;
use SubtitleToolbox\Dependency;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class OcrEngineChooser
{
    private const GLYPH_INSTALL_HINT = "Install php-glyph-ocr with: " . GlyphOcrEngine::INSTALL_COMMAND;


    /**
     * Returns $engine, or Tesseract when it is installed, or else Glyph when the package yama6a/php-glyph-ocr is
     * installed. Throws with install hints when the engine is missing.
     */
    public static function choose(?OcrEngineName $engine = null, string $tesseractProgram = "tesseract"): OcrEngineName
    {
        $tesseract = $engine !== OcrEngineName::Glyph && TesseractOcrEngine::isInstalled($tesseractProgram);
        $glyph     = Dependency::isAvailable(Recognizer::class);
        $problem   = match (true) {
            $engine === OcrEngineName::Tesseract && !$tesseract
                => TesseractOcrEngine::missingProgramMessage($tesseractProgram),
            $engine === OcrEngineName::Glyph && !$glyph
                => "Cannot run OCR with php-glyph-ocr: the package " . GlyphOcrEngine::PACKAGE . " is missing. " .
                   self::GLYPH_INSTALL_HINT,
            $engine === null && !$tesseract && !$glyph
                => "Cannot run OCR: neither Tesseract nor the package " . GlyphOcrEngine::PACKAGE . " is installed. " .
                   TesseractOcrEngine::INSTALL_HINT . " Or " . lcfirst(self::GLYPH_INSTALL_HINT),
            default => null,
        };
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return $engine ?? ($tesseract ? OcrEngineName::Tesseract : OcrEngineName::Glyph);
    }


    /**
     * Creates the engine that choose() names, with default options. Pass the language to Subtitle::recognizeText().
     */
    public static function create(?OcrEngineName $engine = null, string $tesseractProgram = "tesseract"): OcrEngine
    {
        return match (self::choose($engine, $tesseractProgram)) {
            OcrEngineName::Tesseract => new TesseractOcrEngine(new TesseractOcrOptions(program: $tesseractProgram)),
            OcrEngineName::Glyph     => new GlyphOcrEngine(),
        };
    }
}
