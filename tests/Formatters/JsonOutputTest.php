<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class JsonOutputTest extends TestCase
{
    /**
     * @return array<string, array{Format}>
     */
    public static function jsonFormats(): array
    {
        return [
            "JSON"                      => [Format::Json],
            "Podcasting 2.0 transcript" => [Format::PodcastTranscript],
            "Podcasting 2.0 chapters"   => [Format::PodcastChapters],
        ];
    }


    #[DataProvider("jsonFormats")]
    public function testTextThatIsNotUtf8ThrowsTheLibraryException(Format $format): void
    {
        // Read as UTF-8, the file keeps Latin-1 bytes that are not valid UTF-8.
        $subtitle = Subtitle::load(__DIR__ . "/../files/cli/latin1.srt", Format::SubRip, new ReadOptions(encoding: "UTF-8"));

        try {
            $subtitle->toString($format);
            $this->fail("No exception");
        } catch (UnwritableContentException $exception) {
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
            $this->assertSame("JSON cannot hold the subtitle: Malformed UTF-8 characters, possibly incorrectly encoded. " .
                              "Read a file in another encoding with its source encoding.", $exception->getMessage());
        }
    }


    public function testAnInfiniteValueThrowsWithoutTheEncodingAdvice(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/cli/latin1.srt", Format::SubRip, new ReadOptions(encoding: "ISO-8859-1"));
        $subtitle->getCues()[0]->setFormatData("custom", ["value" => INF]);

        try {
            $subtitle->toString(Format::Json);
            $this->fail("No exception");
        } catch (UnwritableContentException $exception) {
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
            $this->assertSame("JSON cannot hold the subtitle: Inf and NaN cannot be JSON encoded.", $exception->getMessage());
        }
    }
}
