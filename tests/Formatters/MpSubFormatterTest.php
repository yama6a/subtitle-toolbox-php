<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class MpSubFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectlyAndXmlTagsAreStripped()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/mpsub/valid.mpsub"),
            $subtitle->toString(Format::MpSub)
        );
    }


    /**
     * @return array<string, array{string}>
     */
    public static function filesWithEmptyLines(): array
    {
        return [
            "empty cues"        => ["srt/real/own_empty_cues.srt"],
            "empty middle line" => ["srt/real/own_alignment_and_coordinates.srt"],
            "only empty tags"   => ["validation/own_netflix_checks.srt"],
        ];
    }


    #[DataProvider("filesWithEmptyLines")]
    public function testEmptyCuesAndLinesAreSkippedSoTheOutputReadsBack(string $file): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/$file", Format::SubRip);
        $expected = array_values(array_filter(array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), Markup::plainLines($cue->getLines())],
            $subtitle->getCues()
        ), fn (array $cue): bool => $cue[2] !== []));

        $read = Subtitle::fromString($subtitle->toString(Format::MpSub), Format::MpSub);

        $this->assertEqualsWithDelta($expected, array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), Markup::plainLines($cue->getLines())], $read->getCues()), 0.0011);
    }


    public function testFrameRateOptionWritesFrames(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/mpsub/valid_25fps.mpsub"),
            $subtitle->toString(Format::MpSub, new WriteOptions(formats: [new MpSubWriteOptions(frameRate: 25)]))
        );
    }


    public function testFramesAreRoundedFromAbsoluteTimesSoErrorsDoNotAddUp(): void
    {
        $subtitle = new Subtitle();
        for ($i = 0; $i < 4; $i++) {
            $subtitle->addCue(new SubtitleCue($i * 0.06, ($i + 1) * 0.06, "Cue $i"));
        }

        $output = $subtitle->toString(Format::MpSub, new WriteOptions(formats: [new MpSubWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString("\n0 2\nCue 0\n\n0 1\nCue 1\n\n0 2\nCue 2\n\n0 1\nCue 3\n", $output);
    }


    public function testEscapedCoreMarkupTextIsWrittenAsPlainText(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["<i>I &lt;3 bread &amp; jam</i>"]));

        $this->assertStringContainsString("\n1 1\nI <3 bread & jam\n", $subtitle->toString(Format::MpSub));
    }


    public function testFrameRateZeroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The MPSub frame rate must be a positive integer, got 0.");
        new MpSubWriteOptions(frameRate: 0);
    }


    public function testFractionalFrameRateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The MPSub frame rate must be a positive integer, got 29.97.");
        new MpSubWriteOptions(frameRate: 29.97);
    }


    public function testHeaderWithoutMetadataAndFormatDataIsUnchanged(): void
    {
        $subtitle = (new Subtitle())->setMetadata(Subtitle::METADATA_LANGUAGE, "en");

        $this->assertSame("\xEF\xBB\xBFTITLE=\nAUTHOR=\nTYPE=VIDEO\nFORMAT=TIME\nNOTE=Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox-php)\n", $subtitle->toString(Format::MpSub));
    }


    public function testHeaderHoldsMetadataAndFormatData(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Yesterday")
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Jane Doe")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setFormatData("mpsub", ["NOTE" => "Draft", "FILE" => "123,abc", "FORMAT" => "30", "TYPE" => "AUDIO"]);

        $this->assertSame(
            "\xEF\xBB\xBFTITLE=Yesterday\nAUTHOR=Jane Doe\nTYPE=AUDIO\nFILE=123,abc\nFORMAT=TIME\nNOTE=Draft\n",
            $subtitle->toString(Format::MpSub)
        );
    }
}
