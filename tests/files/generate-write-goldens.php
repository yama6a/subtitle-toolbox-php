<?php

declare(strict_types=1);

/*
 * Writes the write goldens of the transcript formatters again. Review each diff by hand before a commit.
 * Run: php tests/files/generate-write-goldens.php
 */

use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

require __DIR__ . "/../../vendor/autoload.php";

foreach (WriteGoldens::cases() as [$path, $inputFormat, $outputFormat, $golden]) {
    @mkdir(dirname(TestFiles::DIR . $golden));
    file_put_contents(TestFiles::DIR . $golden, WriteGoldens::render($path, $inputFormat, $outputFormat));
}

echo count(WriteGoldens::cases()) . " golden files written.\n";
