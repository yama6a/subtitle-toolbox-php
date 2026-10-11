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

    private const VERSION_1_DEFAULT_HEADER = ["SOURCE" => "", "PRG" => "", "FILEPATH" => "", "DELAY" => "0", "CD TRACK" => "0", "BEGIN" => ""];
    private const VERSION_2_DEFAULT_HEADER = ["SOURCE" => "", "PRG" => "", "FILEPATH" => "", "DELAY" => "0", "CD TRACK" => "0", "COMMENT" => ""];


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $version = $this->formatOptions($options)->version;
        $output  = $version === SubViewerVersion::V1 ? $this->formatVersion1($subtitle) : $this->formatVersion2($subtitle);

        return $this->applyOutputOptions($output, $options);
    }


    private function formatVersion1(Subtitle $subtitle): string
    {
        $header = $this->headerTags($subtitle, self::VERSION_1_DEFAULT_HEADER);
        // The parser applies a SubViewer 1 delay to the times, so the formatter writes 0.
        if (array_key_exists("DELAY", $header)) {
            $header["DELAY"] = "0";
        }

        $output = "";
        foreach ($header as $tag => $value) {
            $output .= "[$tag]" . LineEnding::Lf->value . ($value === "" ? "" : $value . LineEnding::Lf->value);
        }
        $output .= SubViewerParser::START_SCRIPT . LineEnding::Lf->value;

        foreach ($subtitle->getCues() as $cue) {
            $lines = $this->plainLines($cue);
            // An empty line after a time ends a SubViewer 1 cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $output .= sprintf(self::VERSION_1_TIME_PATTERN, ...Timecode::seconds($cue->getStart())) . LineEnding::Lf->value .
                       implode("|", $lines) . LineEnding::Lf->value .
                       sprintf(self::VERSION_1_TIME_PATTERN, ...Timecode::seconds($cue->getEnd())) . LineEnding::Lf->value .
                       LineEnding::Lf->value;
        }

        return $output . "[end]" . LineEnding::Lf->value . "******** END SCRIPT ********" . LineEnding::Lf->value;
    }


    private function formatVersion2(Subtitle $subtitle): string
    {
        $output = "[INFORMATION]" . LineEnding::Lf->value;
        foreach ($this->headerTags($subtitle, self::VERSION_2_DEFAULT_HEADER) as $tag => $value) {
            $output .= "[$tag]$value" . LineEnding::Lf->value;
        }
        $output .= "[END INFORMATION]" . LineEnding::Lf->value . "[SUBTITLE]" . LineEnding::Lf->value;

        $style = $subtitle->findFormatData(SubViewerParser::FORMAT_DATA_KEY)["style"] ?? null;
        if ($style !== null) {
            $output .= $style . LineEnding::Lf->value;
        }

        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = $this->plainLines($cue);
            // An empty line after the timing line would leave the cue without text.
            if ($lines === []) {
                continue;
            }

            $blocks[] = sprintf(self::VERSION_2_TIME_PATTERN, ...Timecode::centiseconds($cue->getStart())) . "," .
                        sprintf(self::VERSION_2_TIME_PATTERN, ...Timecode::centiseconds($cue->getEnd())) .
                        LineEnding::Lf->value .
                        implode("[br]", $lines) . LineEnding::Lf->value;
        }

        return $output . implode(LineEnding::Lf->value, $blocks);
    }


    /**
     * @param array<string, string> $defaultHeader
     * @return array<string, string>
     */
    private function headerTags(Subtitle $subtitle, array $defaultHeader): array
    {
        $header = [];
        foreach (SubViewerParser::METADATA_TAGS as $tag => $metadataKey) {
            $header[$tag] = $subtitle->findMetadata($metadataKey) ?? "";
        }

        return $header + ($subtitle->findFormatData(SubViewerParser::FORMAT_DATA_KEY)["header"] ?? $defaultHeader);
    }
}
