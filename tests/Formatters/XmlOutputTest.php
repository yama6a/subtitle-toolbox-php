<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMDocument;
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
    private const FILE  = __DIR__ . "/../files/ass/own_windows_1252.ass";
    private const JSON3 = __DIR__ . "/../files/youtube/real/manual.en.json3";


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


    /**
     * @return array<string, array{Format, WriteOptions}>
     */
    public static function ttmlFormats(): array
    {
        return array_intersect_key(self::xmlFormats(), ["TTML" => true, "iTT" => true]);
    }


    #[DataProvider("ttmlFormats")]
    public function testCharactersThatXmlForbidsAreRemoved(Format $format, WriteOptions $options): void
    {
        // The JSON reader keeps control characters, which XML 1.0 forbids in any form.
        $json     = str_replace("Welcome to the bakery tour.", "Wel\\u0001come to\\u000bthe bakery tour.\u{FFFF}", file_get_contents(self::JSON3));
        $subtitle = Subtitle::fromString($json, Format::YouTubeTimedText);
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, "Bakery\x0Ctour");
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "en\x1F");

        $output = $subtitle->toString($format, $options);

        $this->assertTrue((new DOMDocument())->loadXML($output));
        $this->assertStringContainsString("Bakerytour", $output);
        $this->assertStringContainsString('xml:lang="en"', $output);
        $this->assertSame("Welcome tothe bakery tour.", Subtitle::fromString($output, $format)->getCues()[0]->getText());
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
