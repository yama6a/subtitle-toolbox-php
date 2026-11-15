<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;

/**
 * The settings of TesseractOcrEngine.
 */
final readonly class TesseractOcrOptions
{
    private const MAX_PAGE_SEGMENTATION_MODE = 13;

    /** The Tesseract model name, for example "deu" or "deu+eng". */
    public string $language;


    /**
     * @param OcrLanguage|string $language             an OcrLanguage case, or Tesseract model names such as
     *                                                 "deu+eng", for the cues where recognize() gets no language
     * @param int                $pageSegmentationMode the --psm value of tesseract, from 0 to 13. 6 reads the image as
     *                                                 one block of text
     * @param string             $program              the path or name of the tesseract program
     * @param float|null         $scale                the factor from 1 to 8 that scales the image up before OCR.
     *                                                 Null takes 2 on screens below 720 lines and 1 on larger screens
     * @param bool               $invert               draws light text dark on white, as Tesseract expects
     * @param int|null           $threshold            makes gray levels below this value from 1 to 255 black and the
     *                                                 others white. Null keeps the gray levels
     */
    public function __construct(
        OcrLanguage|string $language = "eng",
        public int $pageSegmentationMode = 6,
        public string $program = "tesseract",
        public ?float $scale = null,
        public bool $invert = true,
        public ?int $threshold = null,
    ) {
        $this->language = $language instanceof OcrLanguage ? $language->value : $language;
        if ($pageSegmentationMode < 0 || $pageSegmentationMode > self::MAX_PAGE_SEGMENTATION_MODE) {
            throw new InvalidArgumentException("The page segmentation mode must be from 0 to " . self::MAX_PAGE_SEGMENTATION_MODE . ", got $pageSegmentationMode.");
        }
        if ($scale !== null) {
            OptionChecks::between($scale, 1, 8, "The scale must be from 1 to 8, got %s.");
        }
        if ($threshold !== null && ($threshold < 1 || $threshold > 255)) {
            throw new InvalidArgumentException("The threshold must be from 1 to 255, got $threshold.");
        }
    }
}
