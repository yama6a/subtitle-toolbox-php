<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

/**
 * The ellipsis that the ellipsis fix of CommonErrorOptions writes.
 */
enum EllipsisStyle: string
{
    case ThreeDots = "...";
    case Unicode   = "\u{2026}";
}
