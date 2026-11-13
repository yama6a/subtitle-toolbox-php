<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class XmlLoaderTest extends TestCase
{
    private bool $previous;


    protected function setUp(): void
    {
        $this->previous = libxml_use_internal_errors();
    }


    protected function tearDown(): void
    {
        libxml_use_internal_errors($this->previous);
    }


    public static function failedLoads(): array
    {
        return [
            "TTML"             => [fn () => (new TtmlParser())->parse("<tt><body>", new ReadOptions())],
            "YouTube XML"      => [fn () => (new YouTubeTimedTextParser())->parse("<transcript><text>", new ReadOptions())],
            "OCR replace list" => [fn () => OcrReplaceList::fromSubtitleEditXml("<OCRFixReplaceList><WholeWords>")],
        ];
    }


    #[DataProvider("failedLoads")]
    public function testAFailedLoadKeepsTheLibxmlErrorSetting(Closure $load): void
    {
        foreach ([false, true] as $setting) {
            libxml_use_internal_errors($setting);
            try {
                $load();
                $this->fail("The load did not throw.");
            } catch (ParsingException) {
            }

            $this->assertSame($setting, libxml_use_internal_errors());
            $this->assertFalse(libxml_get_last_error());
        }
    }


    /**
     * @return array<string, array{Format, string, string, list<string>}>
     */
    public static function legacyEncodings(): array
    {
        $ttml = "<?xml version=\"1.0\" encoding=\"%s\"?>\n<tt xmlns=\"http://www.w3.org/ns/ttml\" xml:lang=\"fr\"><body><div>\n"
                . "<p begin=\"00:00:01.000\" end=\"00:00:02.000\">%s</p>\n</div></body></tt>";

        return [
            "TTML in ISO-8859-1"   => [Format::Ttml, sprintf($ttml, "ISO-8859-1", "Caf\u{e9} cr\u{e8}me, s\u{fb}r ?"), "ISO-8859-1", ["Caf\u{e9} cr\u{e8}me, s\u{fb}r ?"]],
            "TTML in Windows-1252" => [Format::Ttml, sprintf($ttml, "windows-1252", "\u{c0} bient\u{f4}t, 5 \u{20ac}."), "Windows-1252", ["\u{c0} bient\u{f4}t, 5 \u{20ac}."]],
            "YouTube srv3"         => [Format::YouTubeTimedText,
                                       "<?xml version='1.0' encoding='ISO-8859-1'?>\n<timedtext format=\"3\"><body><p t=\"1000\" d=\"1000\">Caf\u{e9}</p></body></timedtext>",
                                       "ISO-8859-1", ["Caf\u{e9}"]],
        ];
    }


    /**
     * @param list<string> $lines
     */
    #[DataProvider("legacyEncodings")]
    public function testReadsUtf8ContentThatDeclaresALegacyEncoding(Format $format, string $xml, string $encoding, array $lines): void
    {
        $subtitle = Subtitle::fromString(mb_convert_encoding($xml, $encoding, "UTF-8"), $format, new ReadOptions(encoding: $encoding));

        $this->assertSame($lines, $subtitle->getCues()[0]->getLines());
    }


    public function testReportsTheLineOfAnElementAfterLine65535(): void
    {
        $ttml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body>\n<div>\n"
                . str_repeat("<!-- filler -->\n", 69997)
                . "<p begin=\"00:00:0x.000\" end=\"00:00:02.000\">Late line</p>\n</div>\n</body>\n</tt>\n";

        $subtitle = (new TtmlParser())->parse($ttml, new ReadOptions(lenient: true));

        $this->assertSame(70002, $subtitle->getParseWarnings()[0]->lineNumber);
    }


    public function testReturnsNullAndTheErrorForXmlThatIsNotWellFormed(): void
    {
        $this->assertNull(XmlLoader::xml("<tt><p>", $error));
        $this->assertSame(1, $error->line);
        $this->assertNull(XmlLoader::xml("", $error));
        $this->assertNull($error);
    }
}
