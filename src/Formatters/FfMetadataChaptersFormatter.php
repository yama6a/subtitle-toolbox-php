<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class FfMetadataChaptersFormatter extends SubtitleFormatter
{
    private const DEFAULT_TIME_BASE = "1/1000";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData = $subtitle->findFormatData(FfMetadataChaptersParser::FORMAT_DATA_KEY);
        $output   = ";FFMETADATA1" . LineEnding::Lf->value . $this->tags($this->globalTags($subtitle, $fileData["tags"] ?? []));
        foreach ($fileData["streams"] ?? [] as $streamTags) {
            $output .= "[STREAM]" . LineEnding::Lf->value . $this->tags($streamTags);
        }

        foreach ($subtitle->getCues() as $cue) {
            $cueData  = $cue->findFormatData(FfMetadataChaptersParser::FORMAT_DATA_KEY);
            $timeBase = $cueData["timeBase"] ?? self::DEFAULT_TIME_BASE;
            [$numerator, $denominator] = array_map("intval", explode("/", $timeBase));
            $title    = implode(LineEnding::Lf->value, $this->plainLines($cue));

            $output .= "[CHAPTER]" . LineEnding::Lf->value . "TIMEBASE=$timeBase" . LineEnding::Lf->value .
                       "START=" . $this->ticks($cue->getStart(), $numerator, $denominator) . LineEnding::Lf->value .
                       "END=" . $this->ticks($cue->getEnd(), $numerator, $denominator) . LineEnding::Lf->value .
                       $this->tags(($title === "" ? [] : ["title" => $title]) + ($cueData["tags"] ?? []));
        }

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * The stored tags keep their order, so an unchanged file comes out byte for byte.
     */
    private function globalTags(Subtitle $subtitle, array $storedTags): array
    {
        $tags = [];
        foreach ($storedTags + array_fill_keys(FfMetadataChaptersParser::METADATA_KEYS, null) as $key => $value) {
            $tags[$key] = in_array($key, FfMetadataChaptersParser::METADATA_KEYS, true) ? $subtitle->findMetadata($key) : $value;
        }

        return array_filter($tags, fn (?string $value): bool => $value !== null);
    }


    private function tags(array $tags): string
    {
        $output = "";
        foreach ($tags as $key => $value) {
            $output .= $this->escape((string) $key) . "=" . $this->escape($value) . LineEnding::Lf->value;
        }

        return $output;
    }


    private function escape(string $text): string
    {
        return preg_replace('/[#;=\\\\\n]/', '\\\\$0', $text);
    }


    private function ticks(float $seconds, int $numerator, int $denominator): int
    {
        return (int) round(max(0.0, $seconds) * $denominator / $numerator);
    }
}
