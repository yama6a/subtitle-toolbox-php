<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * UnknownFormatException means that format detection found no format that loads without a format argument.
 */
final class UnknownFormatException extends UnsupportedFormatException
{
    protected const CODE = 106;
}
