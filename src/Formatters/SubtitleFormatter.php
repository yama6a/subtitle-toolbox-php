<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\FormatOptionsList;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

/**
 * The base class of the formatters of this library. Only the library extends it.
 * Its protected members are not API and can change in any release.
 */
abstract class SubtitleFormatter
{
    /** @var class-string<FormatWriteOptions>|null the class that the formatter reads from WriteOptions::$formats, or null for none */
    protected const FORMAT_OPTIONS = null;

    /** WriteOptions::$bom when it is null. Null leaves the output as the formatter wrote it. */
    protected const DEFAULT_BOM = null;


    abstract public function format(Subtitle $subtitle, ?WriteOptions $options = null): string;


    /**
     * Applies the line ending and the BOM of $options to the LF output of a formatter.
     */
    protected function applyOutputOptions(string $output, WriteOptions $options): string
    {
        return $this->applyBom($this->applyLineEnding($output, $options), $options);
    }


    /**
     * Converts the LF output of a formatter to the line ending of $options.
     */
    protected function applyLineEnding(string $output, WriteOptions $options): string
    {
        return $options->lineEnding === LineEnding::Crlf ? preg_replace('/\r?\n/', LineEnding::Crlf->value, $output) : $output;
    }


    /**
     * Adds or removes the BOM as WriteOptions::$bom says, or as DEFAULT_BOM says when it is null.
     */
    protected function applyBom(string $output, WriteOptions $options): string
    {
        return match ($options->bom ?? static::DEFAULT_BOM) {
            true    => StringHelpers::addUtf8Bom($output),
            false   => StringHelpers::removeUtf8Bom($output),
            default => $output,
        };
    }


    /**
     * Returns the FORMAT_OPTIONS object of WriteOptions::$formats, or the defaults of FORMAT_OPTIONS when the list has none.
     * Returns null for a formatter without format options.
     */
    protected function formatOptions(WriteOptions $options): ?FormatWriteOptions
    {
        $class = static::FORMAT_OPTIONS;

        return $class === null ? null : FormatOptionsList::find($options->formats, $class) ?? new $class();
    }
}
