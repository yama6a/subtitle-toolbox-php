<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\OcrException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Markup;

final class TesseractOcrEngine implements OcrEngine
{
    public const INSTALL_HINT = "Install Tesseract with: apt install tesseract-ocr (Debian, Ubuntu), apk add " .
                                "tesseract-ocr tesseract-ocr-data-eng (Alpine), dnf install tesseract (Fedora), brew install tesseract " .
                                "(macOS), or the installer from https://github.com/UB-Mannheim/tesseract/wiki (Windows).";

    // Tesseract finds no text that touches the image edge, so the image gets a white border.
    private const BORDER = 10;

    // The columns of the TSV output of tesseract, and the level of a row with one word.
    private const COLUMN_LEVEL      = 0;
    private const COLUMN_BLOCK      = 2;
    private const COLUMN_PARAGRAPH  = 3;
    private const COLUMN_LINE       = 4;
    private const COLUMN_CONFIDENCE = 10;
    private const COLUMN_TEXT       = 11;
    private const LEVEL_WORD        = "5";

    // The red, green and blue weights of luma in ITU-R BT.601.
    private const LUMA_WEIGHTS = [0.299, 0.587, 0.114];

    // The default scale is 2 on screens below 720 lines, which gave the fewest errors on the test files.
    private const SD_MAX_HEIGHT = 720;
    private const SD_SCALE      = 2.0;

    /** @var array<string, list<string>> the installed languages by program */
    private static array $languages = [];

    private readonly TesseractOcrOptions $options;


    public function __construct(?TesseractOcrOptions $options = null)
    {
        $options ??= new TesseractOcrOptions();
        $this->options = $options;
    }


    /**
     * Returns true when $program runs and prints a Tesseract version.
     *
     * @internal
     */
    public static function isInstalled(string $program = "tesseract"): bool
    {
        // Tesseract 4.0 and older print the version to standard error.
        [$code, $output, $error] = self::run([$program, "--version"]);

        return $code === 0 && str_starts_with(ltrim($output . $error), "tesseract");
    }


    public function recognize(CueImage $image, ?string $language): RecognizedText
    {
        $language ??= $this->options->language;
        $this->requireLanguages($language);

        $file = tempnam(sys_get_temp_dir(), "subtitle-toolbox-ocr-");
        try {
            file_put_contents($file, $this->toPgm($image));
            [$code, $output, $error] = self::run([$this->options->program, $file, "stdout", "-l", $language,
                                                  "--psm", (string)$this->options->pageSegmentationMode, "-c",
                                                  "tessedit_create_tsv=1"]);
        } finally {
            unlink($file);
        }
        if ($code !== 0) {
            throw new OcrException("Tesseract exits with code $code for the cue image at {$image->x}, {$image->y}: " . trim($error));
        }

        return self::fromTsv($output);
    }


    /**
     * Builds the result from the TSV output of tesseract: one line of text per text line, and the mean word confidence.
     *
     * @internal
     */
    public static function fromTsv(string $tsv): RecognizedText
    {
        $lines       = [];
        $confidences = [];
        foreach (array_slice(explode("\n", $tsv), 1) as $row) {
            $columns = explode("\t", rtrim($row, "\r"));
            if (count($columns) <= self::COLUMN_TEXT || $columns[self::COLUMN_LEVEL] !== self::LEVEL_WORD
                || trim($columns[self::COLUMN_TEXT]) === "") {
                continue;
            }
            $key           = $columns[self::COLUMN_BLOCK] . "/" . $columns[self::COLUMN_PARAGRAPH] . "/" . $columns[self::COLUMN_LINE];
            $lines[$key][] = Markup::escapeText(trim($columns[self::COLUMN_TEXT]));
            $confidences[] = max(0.0, min(100.0, (float)$columns[self::COLUMN_CONFIDENCE])) / 100;
        }

        return new RecognizedText(array_values(array_map(fn (array $words): string => implode(" ", $words), $lines)),
                             $confidences === [] ? null : array_sum($confidences) / count($confidences));
    }


    /**
     * @internal
     */
    public static function missingProgramMessage(string $program): string
    {
        return "Cannot run OCR with Tesseract: the program \"$program\" is missing. " . self::INSTALL_HINT;
    }


    /**
     * Throws when the program or the language data of $language is missing.
     * A null $language checks the language of the options.
     *
     * @internal
     */
    public function requireLanguages(?string $language = null): void
    {
        $language ??= $this->options->language;
        $program = $this->options->program;
        if (!isset(self::$languages[$program])) {
            if (!self::isInstalled($program)) {
                throw new InvalidArgumentException(self::missingProgramMessage($program));
            }
            [, $output, $error]        = self::run([$program, "--list-langs"]);
            $lines                     = array_map(trim(...), explode("\n", trim($output . $error)));
            self::$languages[$program] = array_values(array_filter(array_slice($lines, 1)));
        }

        $missing = array_diff(explode("+", $language), self::$languages[$program]);
        if ($missing !== []) {
            throw new InvalidArgumentException("Cannot run OCR with Tesseract in the language \"$language\": " .
                                               "the language data of " . implode(", ", $missing) . " is missing. " .
                                               "Install it, for example with apt install tesseract-ocr-" .
                                               reset($missing) . ". The installed languages are: " .
                                               implode(", ", self::$languages[$program]) . ".");
        }
    }


    /**
     * Draws the image on black as a grey image, inverts it for dark text on white, scales it and adds a border.
     */
    private function toPgm(CueImage $image): string
    {
        ['width' => $width, 'height' => $height, 'pixels' => $pixels] = PngDecoder::decode($image->png);

        $grey = [];
        foreach ($pixels as $pixel) {
            $alpha  = ($pixel & 0xFF) / 255;
            $luma   = self::LUMA_WEIGHTS[0] * ($pixel >> 24 & 0xFF) + self::LUMA_WEIGHTS[1] * ($pixel >> 16 & 0xFF)
                      + self::LUMA_WEIGHTS[2] * ($pixel >> 8 & 0xFF);
            $value  = $luma * $alpha;
            $grey[] = $this->options->invert ? 255 - $value : $value;
        }

        $scale     = $this->options->scale ?? ($image->screenHeight < self::SD_MAX_HEIGHT ? self::SD_SCALE : 1.0);
        $outWidth  = (int)round($width * $scale);
        $outHeight = (int)round($height * $scale);
        $border    = str_repeat("\xFF", $outWidth + 2 * self::BORDER);
        $rows      = array_fill(0, self::BORDER, $border);
        for ($y = 0; $y < $outHeight; $y++) {
            $sourceY = min($height - 1.0, max(0.0, ($y + 0.5) / $scale - 0.5));
            $y0      = (int)$sourceY;
            $y1      = min($height - 1, $y0 + 1);
            $fy      = $sourceY - $y0;
            $row     = str_repeat("\xFF", self::BORDER);
            for ($x = 0; $x < $outWidth; $x++) {
                $sourceX = min($width - 1.0, max(0.0, ($x + 0.5) / $scale - 0.5));
                $x0      = (int)$sourceX;
                $x1      = min($width - 1, $x0 + 1);
                $fx      = $sourceX - $x0;
                $value   = ($grey[$y0 * $width + $x0] * (1 - $fx) + $grey[$y0 * $width + $x1] * $fx) * (1 - $fy)
                         + ($grey[$y1 * $width + $x0] * (1 - $fx) + $grey[$y1 * $width + $x1] * $fx) * $fy;
                if ($this->options->threshold !== null) {
                    $value = $value < $this->options->threshold ? 0 : 255;
                }
                $row .= chr((int)round($value));
            }
            $rows[] = $row . str_repeat("\xFF", self::BORDER);
        }
        array_push($rows, ...array_fill(0, self::BORDER, $border));

        return "P5\n" . ($outWidth + 2 * self::BORDER) . " " . ($outHeight + 2 * self::BORDER) . "\n255\n" . implode("", $rows);
    }


    /**
     * @param list<string> $command
     * @return array{int, string, string} the exit code, standard output and standard error
     */
    private static function run(array $command): array
    {
        $process = @proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if ($process === false) {
            return [-1, "", ""];
        }
        $output = (string)stream_get_contents($pipes[1]);
        $error  = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
