<?php

declare(strict_types=1);

/*
 * Writes the write goldens again. Review each diff by hand before a commit.
 * Run: php tests/files/generate-write-goldens.php
 */

use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

require __DIR__ . "/../../vendor/autoload.php";

$written = 0;
foreach (WriteGoldens::cases() as [$path, $inputFormat, $outputFormat, $golden, $options, $writeOptions]) {
    $output = WriteGoldens::render($path, $inputFormat, $outputFormat, $options, $writeOptions);
    if ($inputFormat === $outputFormat && WriteGoldens::normalize($output) === WriteGoldens::normalize(file_get_contents(TestFiles::DIR . $path))) {
        @unlink(TestFiles::DIR . $golden);
        continue;
    }
    @mkdir(dirname(TestFiles::DIR . $golden));
    file_put_contents(TestFiles::DIR . $golden, $output);
    $written++;
}

echo "$written golden files written.\n";
