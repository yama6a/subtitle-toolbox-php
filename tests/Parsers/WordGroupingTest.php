<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;

class WordGroupingTest extends TestCase
{
    public function testWritesNoWordTimestampEarlierThanTheOneBefore(): void
    {
        $json     = '{"words": [{"text": "one", "start": 1000, "end": 1300}, {"text": "two", "start": 500, "end": 800}, {"text": "three", "start": 1500, "end": 1800}]}';
        $subtitle = (new AssemblyAiParser())->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));

        $this->assertSame("<00:00:01.000>one two <00:00:01.500>three", $subtitle->getCues()[0]->getText());
    }
}
