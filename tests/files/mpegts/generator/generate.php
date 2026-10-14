<?php

declare(strict_types=1);

// Writes the MPEG-TS fixtures to tests/files/mpegts/. Run: php tests/files/mpegts/generator/generate.php

namespace SubtitleToolbox\Container\MpegTs;

require_once __DIR__ . "/TsFixtures.php";
require_once __DIR__ . "/AribFixtures.php";

foreach ([TsFixtures::class => TsFixtures::FILES, AribFixtures::class => AribFixtures::FILES] as $class => $files) {
    foreach ($files as $file => $method) {
        file_put_contents(dirname(__DIR__) . "/$file", $class::$method());
        echo "Wrote $file\n";
    }
}
