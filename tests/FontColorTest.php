<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;

class FontColorTest extends TestCase
{
    public static function formatsAndTags(): array
    {
        $formats = [
            "ass"      => [Format::Ass, new WriteOptions(), new ReadOptions()],
            "ebu stl"  => [Format::EbuStl, new WriteOptions(), new ReadOptions()],
            "itt"      => [Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]), new ReadOptions()],
            "microdvd" => [
                Format::MicroDvd,
                new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]),
                new ReadOptions(formats: [new MicroDvdReadOptions(25)]),
            ],
            "scc"      => [Format::Scc, new WriteOptions(), new ReadOptions()],
            "ttml"     => [Format::Ttml, new WriteOptions(), new ReadOptions()],
        ];
        $tags    = [
            "single quotes"       => "<font color='#FF0000'>Red</font>",
            "no quotes"           => "<font color=#ff0000>Red</font>",
            "upper case"          => "<FONT COLOR=\"#FF0000\">Red</FONT>",
            "spaces around equal" => "<font color = \"#ff0000\" >Red</font>",
            "other attributes"    => "<font face=\"Arial\" color=\"#ff0000\">Red</font>",
        ];

        $cases = [];
        foreach ($formats as $formatName => $format) {
            foreach ($tags as $tagName => $tag) {
                $cases["$formatName, $tagName"] = [...$format, $tag];
            }
        }

        return $cases;
    }


    #[DataProvider("formatsAndTags")]
    public function testEveryFontColorSyntaxKeepsTheColor(Format $format, WriteOptions $write, ReadOptions $read, string $text): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, $text))->toString($format, $write);

        $this->assertSame("<font color=\"#ff0000\">Red</font>", Subtitle::fromString($output, $format, $read)->getCues()[0]->getText());
    }
}
