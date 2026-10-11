<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class RubyFallbackTest extends TestCase
{
    private const FIXTURES = __DIR__ . "/../files/ruby/";


    #[DataProvider("goldenFileProvider")]
    public function testRubyIsWrittenAsBaseAndAnnotation(Format $format, string $expectedFile): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FIXTURES . "own_ruby.vtt"), Format::WebVtt);

        $this->assertSame(
            file_get_contents(self::FIXTURES . $expectedFile),
            $subtitle->toString($format, new WriteOptions(bom: false))
        );
    }


    public static function goldenFileProvider(): array
    {
        return [
            "SubRip"     => [Format::SubRip, "own_ruby_expected.srt"],
            "ASS"        => [Format::Ass, "own_ruby_expected.ass"],
            "plain text" => [Format::PlainText, "own_ruby_expected.txt"],
            "SBV"        => [Format::Sbv, "own_ruby_expected.sbv"],
            "WebVTT"     => [Format::WebVtt, "own_ruby.vtt"],
        ];
    }


    #[DataProvider("textFormatProvider")]
    public function testOtherFormatsWithoutRubyWriteBaseAndAnnotation(Format $format, WriteOptions $options): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<ruby>AB<rt>ab</rt>C<rt>c</rt></ruby> end"));

        $this->assertStringContainsString("AB (ab)C (c) end", $subtitle->toString($format, $options));
    }


    public static function textFormatProvider(): array
    {
        return [
            "MicroDVD"            => [Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)])],
            "MPL2"                => [Format::Mpl2, new WriteOptions()],
            "TMPlayer"            => [Format::TmPlayer, new WriteOptions()],
            "SubViewer"           => [Format::SubViewer, new WriteOptions()],
            "CSV"                 => [Format::Csv, new WriteOptions()],
            "TSV"                 => [Format::Tsv, new WriteOptions()],
            "MPSub"               => [Format::MpSub, new WriteOptions()],
            "LRC"                 => [Format::Lyrics, new WriteOptions()],
            "iTT"                 => [Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)])],
            "HTML transcript"     => [Format::HtmlTranscript, new WriteOptions()],
            "Podcast transcript"  => [Format::PodcastTranscript, new WriteOptions()],
            "FFmetadata chapters" => [Format::FfMetadataChapters, new WriteOptions()],
            "OGM chapters"        => [Format::OgmChapters, new WriteOptions()],
            "Podcast chapters"    => [Format::PodcastChapters, new WriteOptions()],
            "YouTube chapters"    => [Format::YouTubeChapters, new WriteOptions()],
        ];
    }


    #[DataProvider("readBackFormatProvider")]
    public function testFormatsReadBackBaseAndAnnotation(Format $format): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<ruby>AB<rt>ab</rt>C<rt>c</rt></ruby> end"));

        $reread = Subtitle::fromString($subtitle->toString($format), $format);

        $this->assertSame(["AB (ab)C (c) end"], $reread->getCues()[0]->getLines());
    }


    public static function readBackFormatProvider(): array
    {
        return [
            "SCC"     => [Format::Scc],
            "EBU STL" => [Format::EbuStl],
            "SAMI"    => [Format::Sami],
            "TTML"    => [Format::Ttml],
        ];
    }


    public function testEveryWritableTextFormatIsCovered(): void
    {
        $covered = array_map(
            fn (array $row): Format => $row[0],
            [...self::goldenFileProvider(), ...self::textFormatProvider(), ...self::readBackFormatProvider()]
        );
        $writable = array_filter(
            Format::cases(),
            fn (Format $format): bool => $format->canWrite() && !in_array($format, [Format::Json, Format::Pgs], true)
        );

        $this->assertEqualsCanonicalizing(
            array_map(fn (Format $format): string => $format->value, array_values($writable)),
            array_map(fn (Format $format): string => $format->value, $covered)
        );
    }
}
