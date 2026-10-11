<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

final class InvalidArgumentException extends \InvalidArgumentException implements SubtitleToolboxException
{
    protected const CODE = 104;


    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, self::CODE, $previous);
    }
}
