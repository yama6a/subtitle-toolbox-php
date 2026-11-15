<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\RecognitionResult;
use GlyphOcr\RecognizedChar;
use GlyphOcr\Recognizer;
use SubtitleToolbox\Dependency;
use SubtitleToolbox\Exceptions\OcrException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Markup;
use WeakReference;

final class GlyphOcrEngine implements OcrEngine
{
    /** @internal */
    public const PACKAGE = "yama6a/php-glyph-ocr";

    /** @internal */
    public const INSTALL_COMMAND = "composer require " . self::PACKAGE;

    // The subtitle fonts database takes about 76 MB, so engines that are alive at the same time share one copy.
    private static ?WeakReference $subtitleFonts = null;

    private readonly Recognizer $recognizer;


    /**
     * Reads image cues with the pure PHP OCR of the package yama6a/php-glyph-ocr, with its subtitle fonts database by default.
     */
    public function __construct(?GlyphOcrOptions $options = null)
    {
        $options ??= new GlyphOcrOptions();
        Dependency::check(Recognizer::class, "Cannot create a GlyphOcrEngine: the package " . self::PACKAGE .
                                             " is missing. Install it with: " . self::INSTALL_COMMAND);

        $this->recognizer = new Recognizer(
            $options->database ?? self::subtitleFontsDatabase(),
            inkThreshold: $options->inkThreshold,
            spaceWidth: $options->spaceWidth,
            maxWrongPixels: $options->maxWrongPixels,
            fixLatinCase: $options->fixLatinCase,
            unknownText: $options->unknownText,
            italicSlant: $options->italicSlant,
            rightToLeft: $options->rightToLeft,
            minLineHeight: $options->minLineHeight,
            lineContext: $options->lineContext,
        );
    }


    /**
     * Reads the image with one recognizer for all cues, so it keeps the glyph heights it learned. It ignores $language.
     */
    public function recognize(CueImage $image, ?string $language): RecognizedText
    {
        try {
            $result = $this->recognizer->recognize(Image::fromPng($image->png));
        } catch (GlyphOcrException $exception) {
            throw new OcrException("The recognizer fails on the cue image at {$image->x}, {$image->y}: " .
                                   $exception->getMessage(), $exception);
        }

        return self::toRecognizedText($result);
    }


    /**
     * Maps each recognized line to one line of text. A word becomes italic when most of its characters are.
     *
     * @internal
     */
    public static function toRecognizedText(RecognitionResult $result): RecognizedText
    {
        $lines = [];
        foreach ($result->lines as $line) {
            $words = [[]];
            foreach ($line->chars as $char) {
                if ($char->isSpace) {
                    $words[] = [];
                } else {
                    $words[count($words) - 1][] = $char;
                }
            }

            $text       = "";
            $openItalic = false;
            foreach ($words as $index => $chars) {
                $italic = count($chars) > 0
                    && 2 * count(array_filter($chars, fn (RecognizedChar $char): bool => $char->italic)) > count($chars);
                $word   = Markup::escapeText(implode("", array_map(fn (RecognizedChar $char): string => $char->text, $chars)));
                if ($openItalic && !$italic) {
                    $text      .= "</i>";
                    $openItalic = false;
                }
                $text .= $index > 0 ? " " : "";
                if ($italic && !$openItalic) {
                    $text      .= "<i>";
                    $openItalic = true;
                }
                $text .= $word;
            }
            $lines[] = $openItalic ? "$text</i>" : $text;
        }

        return new RecognizedText($lines, $result->confidence());
    }


    private static function subtitleFontsDatabase(): GlyphDatabase
    {
        $database = self::$subtitleFonts?->get();
        if ($database === null) {
            $database            = GlyphDatabase::subtitleFonts();
            self::$subtitleFonts = WeakReference::create($database);
        }

        return $database;
    }
}
