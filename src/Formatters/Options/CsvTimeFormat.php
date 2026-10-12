<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

/**
 * A time column format of CSV and TSV files. The value is the pattern, such as "hh:mm:ss,mmm".
 * Seconds has the value "seconds" and writes the time as a number of seconds.
 * Milliseconds has the value "milliseconds" and writes the time as a whole number of milliseconds, as openai-whisper and whisper.cpp do.
 */
enum CsvTimeFormat: string
{
    case Seconds      = "seconds";
    case Dot          = "hh:mm:ss.mmm";
    case Comma        = "hh:mm:ss,mmm";
    case Frames       = "hh:mm:ss:ff";
    case Milliseconds = "milliseconds";
}
