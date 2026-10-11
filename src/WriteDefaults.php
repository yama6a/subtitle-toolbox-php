<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\MicroDvdParser;

/**
 * Fills the CSV delimiter and the MicroDVD and iTT frame rate that Subtitle::toString() takes from the subtitle.
 *
 * @internal
 */
final class WriteDefaults
{
    public static function for(Subtitle $subtitle, Format $format, WriteOptions $options): WriteOptions
    {
        $formats   = $options->formats;
        $delimiter = match (true) {
            $format === Format::Tsv                                         => "\t",
            $format === Format::Csv && $subtitle->getFormat() === Format::Tsv => ",",
            default                                                         => null,
        };
        $csv = FormatOptionsList::find($formats, CsvWriteOptions::class) ?? new CsvWriteOptions();
        if ($delimiter !== null && $csv->delimiter === null) {
            $formats = FormatOptionsList::with($formats, OptionsCopy::with($csv, ["delimiter" => $delimiter]));
        }
        $microDvd = FormatOptionsList::find($formats, MicroDvdWriteOptions::class) ?? new MicroDvdWriteOptions();
        if ($format === Format::MicroDvd && $microDvd->frameRate === null) {
            $formats = FormatOptionsList::with($formats, OptionsCopy::with($microDvd, [
                "frameRate" => $subtitle->findFormatData(MicroDvdParser::FORMAT_DATA_KEY)["frameRate"]
                    ?? throw new InvalidArgumentException("MicroDVD output needs the frame rate of the video. Set MicroDvdWriteOptions::\$frameRate."),
            ]));
        }
        if ($format === Format::Itt && FormatOptionsList::find($formats, IttWriteOptions::class)?->frameRate === null
            && !isset($subtitle->findFormatData(IttParser::FORMAT_DATA_KEY)["frameRate"])) {
            throw new InvalidArgumentException("iTT output needs the frame rate of the video. Set IttWriteOptions::\$frameRate.");
        }

        return $formats === $options->formats ? $options : OptionsCopy::with($options, ["formats" => $formats]);
    }
}
