<?php

declare(strict_types=1);

/*
 * Writes the pixel goldens of the PGS and VobSub fixtures, the PNG of each first cue, and the PGS output of each
 * VobSub track again. Review each diff by hand before a commit.
 * Run: php tests/files/generate-image-goldens.php
 */

use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Tests\Support\ImageGoldens;
use SubtitleToolbox\Tests\Support\TestFiles;

require __DIR__ . "/../../vendor/autoload.php";

foreach (ImageGoldens::cases() as [$fixture, $track, $golden]) {
    $subtitle = ImageGoldens::parse($fixture, $track);
    file_put_contents(TestFiles::DIR . "$golden.json", ImageGoldens::render($subtitle));
    file_put_contents(TestFiles::DIR . "$golden.png", CueImage::fromCue($subtitle->getCues()[0])->png);
    if ($track !== null) {
        file_put_contents(TestFiles::DIR . "$golden.to.sup", $subtitle->toString(Format::Pgs));
    }
}

echo count(ImageGoldens::cases()) . " goldens written.\n";
