<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class SubViewerWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?SubViewerVersion $version The SubViewer version to write. null writes the stored version, else SubViewer 2.
     */
    public function __construct(
        public readonly ?SubViewerVersion $version = null,
    ) {
    }
}
