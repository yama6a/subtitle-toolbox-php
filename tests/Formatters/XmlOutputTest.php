<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

class XmlOutputTest extends TestCase
{
    private const FILE = __DIR__ . "/../files/ass/own_windows_1252.ass";


    /**
     * @return array<string, array{Format, WriteOptions}>
     */
    public static function xmlFormats(): array
    {
        return [
            "TTML" => [Format::Ttml, new WriteOptions()],
            "iTT"  => [Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)])],
            "SAMI" => [Format::Sami, new WriteOptions()],
        ];
    }


    #[DataProvider("xmlFormats")]
    public function testTextThatIsNotUtf8Throws(Format $format, WriteOptions $options): void
    {
        // Read as UTF-8, the file keeps Windows-1252 bytes in the title, a speaker and the text.
        $subtitle = Subtitle::load(self::FILE, Format::Ass, new ReadOptions(encoding: "UTF-8"));

        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("The output cannot hold text that is not valid UTF-8. " .
                                      "Read a file in another encoding with ReadOptions::\$encoding set to its source encoding.");
        $subtitle->toString($format, $options);
    }


    #[DataProvider("xmlFormats")]
    public function testTextReadWithItsEncodingIsWritten(Format $format, WriteOptions $options): void
    {
        $output = Subtitle::load(self::FILE, Format::Ass, new ReadOptions(encoding: "Windows-1252"))->toString($format, $options);

        $this->assertStringContainsString("Le café ouvre à sept heures.", $output);
        $this->assertSame(3, count(Subtitle::fromString($output, $format)->getCues()));
    }
}
