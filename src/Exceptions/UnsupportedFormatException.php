<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * UnsupportedFormatException means that the library cannot read or write the asked format, for example save() with an unknown extension.
 */
class UnsupportedFormatException extends GenericException
{
    protected const CODE = 102;
}
