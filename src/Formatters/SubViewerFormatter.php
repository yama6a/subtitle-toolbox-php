<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class SubViewerFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = SubViewerWriteOptions::class;

    private const VERSION_1_TIME_PATTERN = "[%02d:%02d:%02d]";
    private const VERSION_2_TIME_PATTERN = "%02d:%02d:%02d.%02d";

    private const VERSION_1_TIME_LINE = '/^\s*\[\d+:\d{2}:\d{2}\]\s*$/';
    private const TIME_FIELD           = '/^\d+:\d{1,2}:\d{1,2}(?:\.\d{1,4})?$/';
    private const STYLE_TAGS           = ["[COLF]", "[SIZE]", "[FONT]", "[STYLE]"];
    private const VERSION_2_BLOCK_KEYS = ["INFORMATION", "END INFORMATION", "SUBTITLE", "COLF", "SIZE", "FONT", "STYLE"];

    private const VERSION_1_DEFAULT_HEADER = ["SOURCE" => "", "PRG" => "", "FILEPATH" => "", "DELAY" => "0", "CD TRACK" => "0", "BEGIN" => ""];
    private const VERSION_2_DEFAULT_HEADER = ["SOURCE" => "", "PRG" => "", "FILEPATH" => "", "DELAY" => "0", "CD TRACK" => "0", "COMMENT" => ""];


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $version = $this->formatOptions($options)->version ?? self::storedVersion($subtitle);
        $output  = $version === SubViewerVersion::V1 ? $this->formatVersion1($subtitle) : $this->formatVersion2($subtitle);

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Returns the version that SubViewerParser stored, or SubViewer 2 for a subtitle from another format.
     */
    private static function storedVersion(Subtitle $subtitle): SubViewerVersion
    {
        $version = $subtitle->findFormatData(SubViewerParser::FORMAT_DATA_KEY)["version"] ?? null;

        return is_int($version) ? SubViewerVersion::tryFrom($version) ?? SubViewerVersion::V2 : SubViewerVersion::V2;
    }


    private function formatVersion1(Subtitle $subtitle): string
    {
        $header = $this->headerTags($subtitle, self::VERSION_1_DEFAULT_HEADER, false);
        // The parser applies a SubViewer 1 delay to the times, so the formatter writes 0.
        if (array_key_exists("DELAY", $header)) {
            $header["DELAY"] = "0";
        }

        $output = "";
        foreach ($header as $tag => $value) {
            // The parser takes the line after an empty tag as its value, unless that line starts with "[" or is the script line.
            $onTagLine = str_starts_with($value, "[") || $value === SubViewerParser::START_SCRIPT;
            $output   .= "[$tag]" . ($onTagLine ? $value : "") . LineEnding::Lf->value . ($value === "" || $onTagLine ? "" : $value . LineEnding::Lf->value);
        }
        $output .= SubViewerParser::START_SCRIPT . LineEnding::Lf->value;

        foreach ($subtitle->getCues() as $cue) {
            $lines = self::splitAtLineFeeds($this->plainLines($cue));
            // An empty line after a time ends a SubViewer 1 cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $output .= sprintf(self::VERSION_1_TIME_PATTERN, ...Timecode::seconds($cue->getStart())) . LineEnding::Lf->value .
                       self::keepAsText(self::joinWithBars($lines), self::VERSION_1_TIME_LINE) . LineEnding::Lf->value .
                       sprintf(self::VERSION_1_TIME_PATTERN, ...Timecode::seconds($cue->getEnd())) . LineEnding::Lf->value .
                       LineEnding::Lf->value;
        }

        return $output . "[end]" . LineEnding::Lf->value . "******** END SCRIPT ********" . LineEnding::Lf->value;
    }


    private function formatVersion2(Subtitle $subtitle): string
    {
        $output = "[INFORMATION]" . LineEnding::Lf->value;
        foreach ($this->headerTags($subtitle, self::VERSION_2_DEFAULT_HEADER, true) as $tag => $value) {
            $line    = "[$tag]$value";
            $output .= ($line === self::keepVersion2LineAsText($line) ? $line : "[$tag]") . LineEnding::Lf->value;
        }
        $output .= "[END INFORMATION]" . LineEnding::Lf->value . "[SUBTITLE]" . LineEnding::Lf->value;

        $style = $subtitle->findFormatData(SubViewerParser::FORMAT_DATA_KEY)["style"] ?? null;
        if ($style !== null) {
            $output .= $style . LineEnding::Lf->value;
        }

        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = self::splitAtLineFeeds($this->plainLines($cue));
            // An empty line after the timing line would leave the cue without text.
            if ($lines === []) {
                continue;
            }

            $blocks[] = sprintf(self::VERSION_2_TIME_PATTERN, ...Timecode::centiseconds($cue->getStart())) . "," .
                        sprintf(self::VERSION_2_TIME_PATTERN, ...Timecode::centiseconds($cue->getEnd())) .
                        LineEnding::Lf->value .
                        self::keepVersion2LineAsText(implode("[br]", str_replace("[br]", "[\u{2060}br]", $lines))) . LineEnding::Lf->value;
        }

        return $output . implode(LineEnding::Lf->value, $blocks);
    }


    /**
     * Puts U+2060 in front of a text line that the parser reads as a timing line, a style line or the script line.
     * A timing line has 2 fields split by a comma, where the parser checks both. So the joiner goes before each field in the shape of a time.
     */
    private static function keepVersion2LineAsText(string $line): string
    {
        $fields = explode(",", $line);
        if (count($fields) === 2) {
            foreach ($fields as $index => $field) {
                if (preg_match(self::TIME_FIELD, trim($field)) === 1) {
                    $fields[$index] = "\u{2060}" . $field;
                }
            }
            $line = implode(",", $fields);
        }

        $trimmed = trim($line);
        $isStyle = str_starts_with($trimmed, "[") && array_filter(self::STYLE_TAGS, fn(string $tag) => str_contains($trimmed, $tag)) !== [];

        return $trimmed === SubViewerParser::START_SCRIPT || $isStyle ? "\u{2060}" . $line : $line;
    }


    /**
     * Returns the header tags that the parser reads back as they are. A CR or LF in a value becomes a space.
     * The formatter skips a key that the parser reads as another key.
     *
     * @param array<string, string> $defaultHeader
     * @return array<string, string>
     */
    private function headerTags(Subtitle $subtitle, array $defaultHeader, bool $version2): array
    {
        $header = [];
        foreach (SubViewerParser::METADATA_TAGS as $tag => $metadataKey) {
            $header[$tag] = $subtitle->findMetadata($metadataKey) ?? "";
        }
        $header += $subtitle->findFormatData(SubViewerParser::FORMAT_DATA_KEY)["header"] ?? $defaultHeader;

        $readable = [];
        foreach ($header as $tag => $value) {
            $tag = (string) $tag;
            if ($tag !== "" && $tag === trim($tag) && preg_match('/[a-z\]\r\n]/', $tag) !== 1 && !($version2 && in_array($tag, self::VERSION_2_BLOCK_KEYS, true))) {
                $readable[$tag] = str_replace(["\r", "\n"], " ", $value);
            }
        }

        return $readable;
    }
}
