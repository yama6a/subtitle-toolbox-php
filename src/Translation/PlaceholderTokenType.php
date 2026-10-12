<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

/**
 * @internal
 */
enum PlaceholderTokenType: string
{
    case Text   = "text";
    case Open   = "open";
    case Close  = "close";
    case Single = "single";
}
