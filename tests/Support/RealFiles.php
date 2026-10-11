<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Helpers for the tests that read the real files of one format, such as tests/files/sbv/real.
 */
trait RealFiles
{
    /**
     * @return string the fixture folder under tests/files, for example "sbv/real/"
     */
    abstract private static function realFilesDir(): string;


    abstract private static function realFilesFormat(): Format;


    private function parseFile(string $file, ReadOptions $options = new ReadOptions()): Subtitle
    {
        return TestFiles::parse(self::realFilesDir() . $file, self::realFilesFormat(), $options);
    }


    /**
     * @return array{float, float, string, Alignment}
     */
    private function describeCue(SubtitleCue $cue): array
    {
        return TestSubtitles::describeWithAlignment([$cue])[0];
    }
}
