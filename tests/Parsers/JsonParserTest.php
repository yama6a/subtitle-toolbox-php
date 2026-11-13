<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class JsonParserTest extends TestCase
{
    public function testReadsMetadataCommentsAndCueFields(): void
    {
        $subtitle = Subtitle::fromString(<<<'JSON'
            {
              "version": 1,
              "metadata": {"title": "Big Buck Bunny", "language": "en"},
              "comments": [{"text": "Translated by Jane Doe", "beforeCueIndex": 0}],
              "formatData": {},
              "cues": [
                {"start": 1.5, "end": 4.0, "lines": ["Hello", "<i>world</i>"], "identifier": "intro", "alignment": 8, "formatData": {}}
              ]
            }
            JSON, Format::Json);

        $this->assertSame(["title" => "Big Buck Bunny", "language" => "en"], $subtitle->getAllMetadata());
        $this->assertEquals([new Comment("Translated by Jane Doe", 0)], $subtitle->getComments());
        $this->assertEquals(
            [(new SubtitleCue(1.5, 4, ["Hello", "<i>world</i>"]))->setIdentifier("intro")->setAlignment(Alignment::TopCenter)],
            $subtitle->getCues()
        );
    }


    public function testReadsTheCuesInStartOrderWithTheirComments(): void
    {
        $path  = __DIR__ . "/../files/json/real/own_aegisub.json";
        $data  = json_decode(file_get_contents($path), true);
        $count = count($data["cues"]);
        $data["cues"] = array_reverse($data["cues"]);
        foreach ($data["comments"] as $index => $comment) {
            if ($comment["beforeCueIndex"] < $count) {
                $data["comments"][$index]["beforeCueIndex"] = $count - 1 - $comment["beforeCueIndex"];
            }
        }

        $this->assertEquals(Subtitle::load($path, Format::Json), Subtitle::fromString(json_encode($data), Format::Json));
    }


    public function testDecodesBase64ObjectsInFormatData(): void
    {
        $subtitle = (new JsonParser())->parse("\xEF\xBB\xBF" . '{"version": 1, "formatData": {"x": {"list": [{"base64": "AAE="}]}}, ' .
                                              '"cues": [{"start": 1, "end": 2, "lines": [], "formatData": {"image": {"png": {"base64": "iVBORw=="}}}}]}', new ReadOptions());

        $this->assertSame(["list" => ["\x00\x01"]], $subtitle->findFormatData("x"));
        $this->assertSame(["png" => "\x89PNG"], $subtitle->getCues()[0]->findFormatData("image"));
    }


    public function testKeepsObjectsWithMoreKeysThanBase64(): void
    {
        $subtitle = (new JsonParser())->parse('{"version": 1, "formatData": {"x": {"base64": "AAE=", "note": "y"}}, "cues": []}', new ReadOptions());

        $this->assertSame(["base64" => "AAE=", "note" => "y"], $subtitle->findFormatData("x"));
    }


    public static function badJson(): array
    {
        return [
            "not JSON"         => ["{\"version\": 1,", "The content is not valid JSON: Syntax error."],
            "list root"        => ["[1, 2]", "The JSON root must be an object."],
            "string root"      => ["\"cues\"", "The JSON root must be an object."],
            "bad start"        => ['{"version": 1, "cues": [{"start": 0, "end": 1, "lines": []}, {"start": null, "end": 1, "lines": []}]}',
                                   "The field cues[1].start must be a number."],
            "bad base64"       => ['{"version": 1, "cues": [{"start": 0, "end": 1, "lines": [], "formatData": {"image": {"png": {"base64": "?"}}}}]}',
                                   "The field cues[0].formatData.image.png.base64 must be valid base64."],
            "bad base64 in list" => ['{"version": 1, "formatData": {"x": [{"base64": "!"}]}, "cues": []}',
                                     "The field formatData.x[0].base64 must be valid base64."],
        ];
    }


    #[DataProvider("badJson")]
    public function testThrowsParsingExceptionWithThePathOfTheBadField(string $json, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new JsonParser())->parse($json, new ReadOptions());
    }


    public function testStrictModeRejectsANegativeEndBeforeTheStart(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The cue ends at -1 s, before it starts at 5 s.");

        (new JsonParser())->parse('{"version":1,"cues":[{"start":5,"end":-1,"lines":["x"]}]}', new ReadOptions());
    }


    public function testLenientModeSwapsANegativeEndAndTheStart(): void
    {
        $parser   = new JsonParser();
        $subtitle = $parser->parse('{"version":1,"cues":[{"start":5,"end":-1,"lines":["x"]}]}', new ReadOptions(lenient: true));

        $this->assertSame([-1.0, 5.0], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
        $this->assertCount(1, $parser->getWarnings());
    }


    public function testATimeThatRoundsUpToTheLimitFails(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field cues[0].end is not below 100000 hours.");

        (new JsonParser())->parse('{"version":1,"cues":[{"start":0,"end":359999999.9996,"lines":["x"]}]}', new ReadOptions());
    }


    public static function objectsWhereAListBelongs(): array
    {
        return [
            "cues"     => ['{"version":1,"cues":{"0":{"start":0,"end":1,"lines":["a"]}}}', "The field cues must be a list."],
            "lines"    => ['{"version":1,"cues":[{"start":0,"end":1,"lines":{"0":"a"}}]}', "The field cues[0].lines must be a list."],
            "comments" => ['{"version":1,"cues":[],"comments":{"0":{"text":"a","beforeCueIndex":0}}}', "The field comments must be a list."],
        ];
    }


    #[DataProvider("objectsWhereAListBelongs")]
    public function testAJsonObjectWhereAListBelongsFails(string $json, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new JsonParser())->parse($json, new ReadOptions());
    }


    public function testFromArrayStillAcceptsAPhpList(): void
    {
        $subtitle = Subtitle::fromArray(["version" => 1, "cues" => [["start" => 0, "end" => 1, "lines" => ["a"]]]]);

        $this->assertCount(1, $subtitle->getCues());
    }
}
