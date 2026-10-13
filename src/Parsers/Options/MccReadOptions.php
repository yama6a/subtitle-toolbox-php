<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class MccReadOptions implements FormatReadOptions
{
    /**
     * @param ?int $service The CEA-708 caption service from 1 to 63. Its captions win over the CEA-608 captions when it has any. Null reads only the CEA-608 captions.
     */
    public function __construct(
        public readonly ?int $service = 1,
    ) {
        if ($service !== null && ($service < 1 || $service > 63)) {
            throw new InvalidArgumentException("The CEA-708 service must be from 1 to 63, got $service.");
        }
    }
}
