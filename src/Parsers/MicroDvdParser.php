<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class MicroDvdParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = MicroDvdReadOptions::class;
    public const FORMAT_DATA_KEY = Format::MicroDvd->value;

    private const STYLE_TAGS = ["b", "i", "u", "s"];

    // The default of mantas-done/subtitles, pysubs2 and subtitle_it.
    private const LENIENT_FRAME_RATE = 23.976;

    private const CUE_REGEX = '/^\{(\d+)\}\{(\d+)\}(.*)$/';


    protected function read(string $content): Subtitle
    {
        $rawLines = array_filter(
            array_map("trim", $this->lines($content)),
            fn (string $line): bool => $line !== ""
        );
        $blockIndexes = array_flip(array_keys($rawLines));

        [$frameRate, $hasFrameRateLine] = $this->readFrameRate($rawLines);
        try {
            $frames = new FrameRate($frameRate);
        } catch (InvalidArgumentException $exception) {
            throw new ParsingException($exception->getMessage(), null, $exception);
        }

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["frameRate" => $frameRate] + ($hasFrameRateLine ? ["frameRateLine" => true] : []));
        foreach ($rawLines as $lineIndex => $rawLine) {
            $lineNumber = $lineIndex + 1;
            try {
                if (!preg_match(self::CUE_REGEX, $rawLine, $matches)) {
                    throw new ParsingException("The line \"$rawLine\" is not a MicroDVD cue.", $lineNumber);
                }
                $start = self::boundedTime($frames->framesToSeconds((int) $matches[1]), "{{$matches[1]}}", $lineNumber);
                $end   = self::boundedTime($frames->framesToSeconds((int) $matches[2]), "{{$matches[2]}}", $lineNumber);
                [$start, $end] = $this->orderedTimes($start, $end, $lineNumber, $blockIndexes[$lineIndex], [$rawLine]);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $blockIndexes[$lineIndex], [$rawLine]);
                continue;
            }

            $parsedCues[] = $this->parseCue($start, $end, $matches[3]);
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * Returns the frame rate of the options or of the {1}{1}<fps> line, and removes that line from $rawLines.
     *
     * @param array<int, string> $rawLines
     * @return array{float, bool} the frame rate and whether the file has the line
     */
    private function readFrameRate(array &$rawLines): array
    {
        // In lenient mode, the {1}{1}<fps> line can follow lines without frames.
        $firstLine = null;
        foreach ($rawLines as $lineIndex => $rawLine) {
            if (!$this->options->lenient || preg_match(self::CUE_REGEX, $rawLine)) {
                $firstLine = $lineIndex;
                break;
            }
        }
        $frameRate = $this->formatOptions()->frameRate;
        if ($firstLine !== null && preg_match('/^\{1\}\{1\}(\d+(?:\.\d+)?)$/', $rawLines[$firstLine], $matches)) {
            $frameRate ??= (float) $matches[1];
            unset($rawLines[$firstLine]);

            return [$frameRate, true];
        }

        if ($frameRate === null && $this->options->lenient) {
            $this->warn("The file has no {1}{1}<fps> line. The parser used " . self::LENIENT_FRAME_RATE . " fps.", null, null, [], ParseWarningAction::Repaired);

            return [self::LENIENT_FRAME_RATE, false];
        }
        if ($frameRate === null) {
            throw new ParsingException("The frame rate is unknown. Set MicroDvdReadOptions::\$frameRate, start the file with {1}{1}<fps>, " .
                                       "or read the file in lenient mode for " . self::LENIENT_FRAME_RATE . " fps.");
        }

        return [$frameRate, false];
    }


    private function parseCue(float $start, float $end, string $text): SubtitleCue
    {
        $cueStyle   = ["color" => null, "tags" => []];
        $lineStyles = [];
        $lineCodes  = [];
        $lineTexts  = [];
        foreach (explode("|", $text) as $rawLine) {
            preg_match('/^(?:\{[A-Za-z]:[^}]*\})*/', $rawLine, $matches);
            $prefix    = $matches[0];
            $lineStyle = ["color" => null, "tags" => []];
            $other     = "";
            preg_match_all('/\{([A-Za-z]):([^}]*)\}/', $prefix, $codes, PREG_SET_ORDER);
            foreach ($codes as [$code, $letter, $value]) {
                $isCueCode = $letter === strtoupper($letter);
                $applied   = $this->applyCode(strtolower($letter), $value, $isCueCode ? $cueStyle : $lineStyle);
                if ($applied === null) {
                    $other .= $code;
                } elseif ($isCueCode) {
                    $cueStyle = $applied;
                } else {
                    $lineStyle = $applied;
                }
            }

            $lineText = trim(substr($rawLine, strlen($prefix)));
            if ($lineText === "") {
                continue;
            }

            $lineStyles[] = $lineStyle;
            $lineCodes[]  = ["codes" => $prefix, "otherCodes" => $other];
            $lineTexts[]  = $lineText;
        }

        $lines = [];
        foreach ($lineTexts as $index => $lineText) {
            $color   = $lineStyles[$index]["color"] ?? $cueStyle["color"];
            $tags    = array_values(array_intersect(self::STYLE_TAGS, [...$cueStyle["tags"], ...$lineStyles[$index]["tags"]]));
            $lines[] = StyleRuns::toMarkup([[$lineText, ["color" => $color, ...array_fill_keys($tags, true)]]]);

            $lineCodes[$index] += ["color" => $color, "tags" => $tags];
        }

        return (new SubtitleCue($start, $end, $lines))->setFormatData(self::FORMAT_DATA_KEY, ["lines" => $lineCodes]);
    }


    /**
     * Returns the style with the {y:...} or {c:...} code applied, or null for codes outside the core markup.
     */
    private function applyCode(string $letter, string $value, array $style): ?array
    {
        if ($letter === "y" && preg_match('/^[biusBIUS, ]+$/', $value)) {
            $style["tags"] = [...$style["tags"], ...str_split(strtolower(str_replace([",", " "], "", $value)))];

            return $style;
        }

        if ($letter === "c" && preg_match('/^\$([0-9A-Fa-f]{6})$/', $value, $bgr)) {
            $style["color"] = "#" . strtolower(MarkupColors::bgrToRgb($bgr[1]));

            return $style;
        }

        return null;
    }
}
