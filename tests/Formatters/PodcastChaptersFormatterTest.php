<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

class PodcastChaptersFormatterTest extends TestCase
{
    public function testWritesAStoredWholeNumberWithItsFraction(): void
    {
        $json     = '{"chapters": [{"startTime": 0, "location": {"name": "Paris", "lat": 52.0}}]}';
        $subtitle = Subtitle::fromString($json, Format::PodcastChapters);

        $this->assertStringContainsString('"lat": 52.0', $subtitle->toString(Format::PodcastChapters));
    }
}
