<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;

class OptionFlagsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    public static function readFlags(): array
    {
        return [
            "Whisper word timestamps" => [Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]), "whisper/real/openai_whisper_word_timestamps.json"],
            "Whisper speaker voices"  => [Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]), "whisper/real/whisperx_diarize.json"],
            "Deepgram speaker voices" => [Format::Deepgram, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]), "deepgram/real/pool_utterances_diarize.json"],
            "YouTube word timestamps" => [Format::YouTubeTimedText, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]), "youtube/real/auto.en.json3"],
            "podcast keep segments"   => [Format::PodcastTranscript, new ReadOptions(formats: [new TranscriptReadOptions(keepSegments: true)]), "podcast/real/spec_word_segments.json"],
            "podcast word timestamps" => [Format::PodcastTranscript, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]), "podcast/real/spec_word_segments.json"],
        ];
    }


    #[DataProvider("readFlags")]
    public function testReadFlagChangesTheCues(Format $format, ReadOptions $options, string $file): void
    {
        $content = file_get_contents(self::FILES . $file);

        $this->assertNotSame(Subtitle::fromString($content, $format)->toArray(), Subtitle::fromString($content, $format, $options)->toArray());
    }
}
