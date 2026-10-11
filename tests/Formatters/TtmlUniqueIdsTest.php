<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

class TtmlUniqueIdsTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/ttml/real/";


    /**
     * Returns the xml:id values in document order and fails on any libxml error.
     */
    private function ids(string $output): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded   = $document->loadXML($output, LIBXML_NONET);
        $errors   = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded);
        $this->assertSame([], array_map(fn ($error): string => trim($error->message), $errors));

        $ids = [];
        foreach ((new DOMXPath($document))->query("//@xml:id") as $attribute) {
            $ids[] = $attribute->value;
        }
        $this->assertSame(array_unique($ids), $ids);

        return $ids;
    }


    private function write(Subtitle $subtitle, Format $format): string
    {
        $options  = $format === Format::Itt ? new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]) : new WriteOptions();
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $output = $subtitle->toString($format, $options);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);

        return $output;
    }


    public static function duplicateIdFiles(): array
    {
        return [
            "astisub ttml" => ["astisub_merging_style.ttml", Format::Ttml, ["sub_1", "sub_2", "sub_3", "sub_2_2"]],
            "astisub itt"  => ["astisub_merging_style.ttml", Format::Itt, ["sub_1", "sub_2", "sub_3", "sub_2_2"]],
            "mantas ttml"  => ["mantas_duplicated_ids.ttml", Format::Ttml, ["c1", "c1_2", "c3"]],
            "mantas itt"   => ["mantas_duplicated_ids.ttml", Format::Itt, ["c1", "c1_2", "c3"]],
            "w3c ttml"     => ["w3c_imsc11_paragraphs.ttml", Format::Ttml, ["subtitle1", "subtitle1_2", "subtitle1_3", "subtitle1_4"]],
            "w3c itt"      => ["w3c_imsc11_paragraphs.ttml", Format::Itt, ["subtitle1", "subtitle1_2", "subtitle1_3", "subtitle1_4"]],
        ];
    }


    #[DataProvider("duplicateIdFiles")]
    public function testDuplicateCueIdsGetASuffix(string $file, Format $format, array $paragraphIds): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Ttml);

        $ids = $this->ids($this->write($subtitle, $format));

        $this->assertSame($paragraphIds, array_slice($ids, -count($paragraphIds)));
    }


    public function testCueIdEqualToAnIttHeadIdGetsASuffix(): void
    {
        $vtt = "WEBVTT\n\ntop\n00:00:01.000 --> 00:00:02.000\nOne\n\nnormal\n00:00:03.000 --> 00:00:04.000\nTwo\n";

        $output = $this->write(Subtitle::fromString($vtt, Format::WebVtt), Format::Itt);

        $this->assertSame(["normal", "top", "bottom", "top_2", "normal_2"], $this->ids($output));
        $this->assertStringContainsString("<p xml:id=\"top_2\" begin=\"00:00:01:00\" end=\"00:00:02:00\" region=\"bottom\">One</p>", $output);
    }


    public function testCueIdEqualToANewRegionIdGetsASuffix(): void
    {
        $vtt = "WEBVTT\n\nbottomCenter\n00:00:01.000 --> 00:00:02.000\nOne\n\nagent1\n00:00:03.000 --> 00:00:04.000\n<v Fred>Two\n";

        $output = $this->write(Subtitle::fromString($vtt, Format::WebVtt), Format::Ttml);

        $this->assertSame(["agent1", "bottomCenter", "bottomCenter_2", "agent1_2"], $this->ids($output));
        $this->assertStringContainsString("<p xml:id=\"bottomCenter_2\" begin=\"00:00:01.000\" end=\"00:00:02.000\" region=\"bottomCenter\">One</p>", $output);
    }


    public function testStoredBodyAndDivIdsShareTheIdsWithTheHeadAndTheCues(): void
    {
        $ttml     = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xml:lang=\"en\">\n"
                    . "  <head><styling><style xml:id=\"s1\"/></styling></head>\n"
                    . "  <body xml:id=\"s1\"><div>\n"
                    . "    <p xml:id=\"d1\" begin=\"1s\" end=\"2s\">One</p>\n"
                    . "    <p xml:id=\"s1\" begin=\"3s\" end=\"4s\">Two</p>\n"
                    . "  </div></body>\n</tt>";
        $subtitle = Subtitle::fromString($ttml, Format::Ttml);
        foreach ($subtitle->getCues() as $idx => $cue) {
            $cue->setFormatData(TtmlParser::FORMAT_DATA_KEY, ["div" => ["xml:id" => "d1", "xml:space" => $idx === 0 ? "default" : "preserve"]]);
        }

        $output = $this->write($subtitle, Format::Ttml);

        $this->assertSame(["s1", "s1_2", "d1", "d1_2", "d1_3", "s1_3"], $this->ids($output));
    }
}
