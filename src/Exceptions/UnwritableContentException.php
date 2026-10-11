<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * UnwritableContentException means that the output format cannot hold the subtitle, for example a cue with 5 lines in SCC.
 */
final class UnwritableContentException extends \RuntimeException implements SubtitleToolboxException
{
    protected const CODE = 108;


    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, self::CODE, $previous);
    }
}
