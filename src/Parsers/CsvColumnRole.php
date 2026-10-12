<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The role of a CSV column. The values are the keys of the "roles" format data.
 * Second is the column of CsvWriteOptions::$secondText. Other is a column without a role.
 *
 * @internal
 */
enum CsvColumnRole: string
{
    case Identifier = "identifier";
    case Start      = "start";
    case End        = "end";
    case Duration   = "duration";
    case Speaker    = "speaker";
    case Text       = "text";
    case Second     = "second";
    case Other      = "other";
}
