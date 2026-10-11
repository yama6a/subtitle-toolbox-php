<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\GlyphDatabase;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\OcrProgress;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Cli\SideFile;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\OcrEngineName;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Ocr\TesseractOcrOptions;
use SubtitleToolbox\OptionsCopy;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * @internal
 */
final class OcrEdit extends Edit
{
    private bool $warnedAboutLanguage = false;


    private function __construct(
        private readonly OcrEngineName $engine,
        private readonly ?string $language,
        private readonly TesseractOcrOptions $tesseractOptions,
        private GlyphOcrOptions $glyphOptions,
        private readonly ?string $databasePath,
    ) {
    }


    public static function group(): string
    {
        return "ocr";
    }


    public static function summary(): string
    {
        return "Read the text of image cues with OCR.";
    }


    public static function options(): array
    {
        return [
            Option::flag("ocr", "Read the text of image cues, for example from PGS or VobSub. Use Tesseract when it is installed, and php-glyph-ocr when it is not."),
            Option::value("ocr-engine", "ENGINE", "OCR engine for --ocr: tesseract or glyph. Default: tesseract when it is installed."),
            Option::value("ocr-language", "CODE", "Tesseract language for --ocr, for example deu or deu+eng. Default: eng. The glyph engine ignores it."),
            Option::value("ocr-database", "FILE", "Glyph database in .nocr format for --ocr. It selects the glyph engine. Default: the subtitle fonts database of php-glyph-ocr."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "ocr", ["ocr-database", "ocr-engine", "ocr-language"]);
        if (!$arguments->has("ocr")) {
            return null;
        }

        $name   = $arguments->choice("ocr-engine", array_column(OcrEngineName::cases(), "value"));
        $engine = $name === null ? null : OcrEngineName::from($name);
        if ($arguments->has("ocr-database")) {
            if ($engine === OcrEngineName::Tesseract) {
                Command::fail("Pass --ocr-engine glyph with --ocr-database.");
            }
            $engine = OcrEngineName::Glyph;
        }
        $engine           = OcrEngineChooser::choose($engine);
        $language         = $arguments->value("ocr-language");
        $tesseractOptions = new TesseractOcrOptions(...Command::withoutNulls(["language" => $language]));
        if ($engine === OcrEngineName::Tesseract) {
            (new TesseractOcrEngine($tesseractOptions))->requireLanguages();
        }

        return new self($engine, $language, $tesseractOptions, new GlyphOcrOptions(), $arguments->value("ocr-database"));
    }


    public function loadSideFiles(): void
    {
        if ($this->databasePath !== null) {
            $this->glyphOptions = OptionsCopy::with($this->glyphOptions, [
                "database" => SideFile::parse($this->databasePath, GlyphDatabase::fromBytes(...), GlyphOcrException::class),
            ]);
        }
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $total = count(array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue) && $cue->getLines() === []));
        if ($total > 0) {
            $subtitle->recognizeText(new OcrProgress($this->engine($console), $console, $label, $total));
        }

        return $subtitle;
    }


    private function engine(Console $console): OcrEngine
    {
        if ($this->engine === OcrEngineName::Tesseract) {
            return new TesseractOcrEngine($this->tesseractOptions);
        }
        if ($this->language !== null && !$this->warnedAboutLanguage) {
            $console->err("Warning: the glyph engine ignores --ocr-language.\n");
            $this->warnedAboutLanguage = true;
        }

        // A new engine for each file, because the recognizer learns the glyph heights of one stream.
        $engine = new GlyphOcrEngine($this->glyphOptions);
        // The options keep the database of the first engine, so the run loads it once.
        $this->glyphOptions = OptionsCopy::with($this->glyphOptions, ["database" => $engine->database()]);

        return $engine;
    }
}
