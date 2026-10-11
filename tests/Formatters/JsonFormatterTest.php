<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\JsonWriteOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class JsonFormatterTest extends TestCase
{
    public function testWritesCompactJsonByDefault(): void
    {
        $subtitle = (new Subtitle())->setMetadata(Subtitle::METADATA_TITLE, "Big Buck Bunny")->setMetadata(Subtitle::METADATA_LANGUAGE, "en");
        $subtitle->addCue((new SubtitleCue(1.5, 4, ["Hello", "<i>world</i>"]))->setIdentifier("intro")->setAlignment(8));
        $subtitle->addComment("Translated by Jane Doe", 0);

        $this->assertSame(
            '{"version":1,"metadata":{"title":"Big Buck Bunny","language":"en"},' .
            '"comments":[{"text":"Translated by Jane Doe","beforeCueIndex":0}],"formatData":{},' .
            '"cues":[{"start":1.5,"end":4.0,"lines":["Hello","<i>world</i>"],"identifier":"intro","alignment":8,"formatData":{}}]}',
            $subtitle->toString(Format::Json)
        );
    }


    public function testWritesEmptyMapsAsObjectsAndNumericKeysAsObjectKeys(): void
    {
        $subtitle = (new Subtitle())->setMetadata("0", "zero")->setFormatData("sub", ["a", "b"]);

        $this->assertSame(
            '{"version":1,"metadata":{"0":"zero"},"comments":[],"formatData":{"sub":{"0":"a","1":"b"}},"cues":[]}',
            $subtitle->toString(Format::Json)
        );
    }


    public function testPrettyPrintEndsWithANewLine(): void
    {
        $json = (new Subtitle())->toString(Format::Json, new WriteOptions(lineEnding: LineEnding::Crlf, formats: [new JsonWriteOptions(prettyPrint: true)]));

        $this->assertSame("{\r\n    \"version\": 1,\r\n    \"metadata\": {},\r\n    \"comments\": [],\r\n    \"formatData\": {},\r\n    \"cues\": []\r\n}\r\n", $json);
    }


    public function testWritesImageCuesWithBase64Png(): void
    {
        $png      = PngEncoder::encode(1, 1, [0xFF0000FF]);
        $subtitle = new Subtitle();
        $subtitle->addCue((new CueImage($png, 1, 2, 1, 1, 720, 576))->toCue(new SubtitleCue(1, 2)));

        $json = json_decode($subtitle->toString(Format::Json), true);

        $this->assertSame(["base64" => base64_encode($png)], $json["cues"][0]["formatData"]["image"]["png"]);
        $this->assertSame(720, $json["cues"][0]["formatData"]["image"]["screenWidth"]);
    }


    public function testKeepsValidUtf8FormatDataAsString(): void
    {
        $subtitle = (new Subtitle())->setFormatData("ass", ["title" => "Bäckerei", "png" => "png"]);

        $this->assertStringContainsString('"ass":{"title":"Bäckerei","png":"png"}', $subtitle->toString(Format::Json));
    }


    public function testWithoutFormatDataOption(): void
    {
        $subtitle = (new Subtitle())->setFormatData("ass", ["title" => "Bakery"]);
        $subtitle->addCue((new SubtitleCue(1, 2, "Rain"))->setFormatData("vtt", ["settings" => "line:0"]));

        $this->assertSame(
            '{"version":1,"metadata":{},"comments":[],"cues":[{"start":1.0,"end":2.0,"lines":["Rain"],"identifier":null,"alignment":null}]}',
            $subtitle->toString(Format::Json, new WriteOptions(formats: [new JsonWriteOptions(withFormatData: false)]))
        );
    }
}
