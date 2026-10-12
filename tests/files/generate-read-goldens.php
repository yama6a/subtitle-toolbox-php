<?php

declare(strict_types=1);

/*
 * Writes the read goldens of the real files again. Review each diff by hand before a commit.
 * Run: php tests/files/generate-read-goldens.php
 */

use SubtitleToolbox\Tests\Support\ReadGoldens;
use SubtitleToolbox\Tests\Support\TestFiles;

require __DIR__ . "/../../vendor/autoload.php";

foreach (ReadGoldens::goldenFiles() as $golden) {
    unlink(TestFiles::DIR . $golden);
}

foreach (ReadGoldens::cases() as [$path, $format, $options, $golden]) {
    @mkdir(dirname(TestFiles::DIR . $golden));
    file_put_contents(TestFiles::DIR . $golden, ReadGoldens::render($path, $format, $options));
}

echo count(ReadGoldens::cases()) . " golden files written.\n";
