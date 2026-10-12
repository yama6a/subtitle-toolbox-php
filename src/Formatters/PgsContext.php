<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

/**
 * The values that PgsFormatter changes during one format() call.
 *
 * @internal
 */
final class PgsContext
{
    public int $compositionNumber = 0;

    /** @var array<string, array{int, int, int}> Kr of the matrix and 0xRRGGBB => [Y, Cr, Cb] */
    public array $ycrcb = [];
}
