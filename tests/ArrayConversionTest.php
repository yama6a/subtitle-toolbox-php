<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class ArrayConversionTest extends TestCase
{
    private const DIR = __DIR__ . "/files/";

    // Format::detect() does not detect cloud speech-to-text JSON, so these fixtures load with the format of their directory.
    private const CLOUD_SPEECH = ["assemblyai", "aws-transcribe", "deepgram", "google-speech"];

    // Detection finds no format for headerless CSV. FormatDetectorTest uses this fixture.
    private const UNREADABLE = ["csv/real/headerless_frame_times.csv"];


    private function bakery(): Subtitle
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Bakery")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setFormatData("ass", ["styles" => [["Name" => "Default", "Fontsize" => "72"]]]);
        $subtitle->addCue((new SubtitleCue(1.5, 4, ["Hello", "<i>world</i>"]))->setIdentifier("intro")->setAlignment(Alignment::TopCenter));
        $subtitle->addCue((new SubtitleCue(4.5, 6.25, "Fresh bread &amp; rolls"))->setFormatData("vtt", ["settings" => "line:0"]));
        $subtitle->addComment("Translated by Jane Doe", 0);

        return $subtitle;
    }


    public function testToArrayWritesEveryField(): void
    {
        $this->assertSame([
            "version"    => 1,
            "metadata"   => ["title" => "Bakery", "language" => "en"],
            "comments"   => [["text" => "Translated by Jane Doe", "beforeCueIndex" => 0]],
            "formatData" => ["ass" => ["styles" => [["Name" => "Default", "Fontsize" => "72"]]]],
            "cues"       => [
                ["start" => 1.5, "end" => 4.0, "lines" => ["Hello", "<i>world</i>"], "identifier" => "intro", "alignment" => 8, "formatData" => []],
                ["start" => 4.5, "end" => 6.25, "lines" => ["Fresh bread &amp; rolls"], "identifier" => null, "alignment" => null,
                 "formatData" => ["vtt" => ["settings" => "line:0"]]],
            ],
        ], $this->bakery()->toArray());
    }


    public function testToArrayWithoutFormatDataLeavesOutTheFormatDataKeys(): void
    {
        $array = $this->bakery()->toArray(false);

        $this->assertArrayNotHasKey("formatData", $array);
        $this->assertArrayNotHasKey("formatData", $array["cues"][1]);
        $this->assertEquals(
            (clone $this->bakery())->setFormatData("ass", [])->getCues()[0],
            Subtitle::fromArray($array)->getCues()[0]
        );
        $this->assertSame([], Subtitle::fromArray($array)->getCues()[1]->findFormatData("vtt"));
    }


    public function testFromArrayOfToArrayGivesAnEqualSubtitle(): void
    {
        $subtitle = $this->bakery();

        $this->assertEquals($subtitle, Subtitle::fromArray($subtitle->toArray()));
    }


    public function testFromArrayKeepsBinaryFormatData(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 2], [5, 6]]);
        $subtitle->getCues()[0]->setFormatData("image", ["png" => "\x89PNG\r\n\x1a\n"]);

        $this->assertEquals($subtitle, Subtitle::fromArray($subtitle->toArray()));
    }


    public function testNumericMetadataKeysSurviveTheJsonRoundTrip(): void
    {
        $subtitle = (new Subtitle())->setMetadata("0", "zero");

        $this->assertEquals($subtitle->toArray(), Subtitle::fromString($subtitle->toString(Format::Json))->toArray());
    }


    public function testFromArrayFillsMissingOptionalFields(): void
    {
        $subtitle = Subtitle::fromArray(["version" => 1, "cues" => [["start" => 1, "end" => 2, "lines" => ["Rain"]]]]);

        $this->assertSame([], $subtitle->getAllMetadata());
        $this->assertSame([], $subtitle->getComments());
        $this->assertEquals([new SubtitleCue(1, 2, "Rain")], $subtitle->getCues());
    }


    public static function badArrays(): array
    {
        $cue = ["start" => 1, "end" => 2, "lines" => ["Rain"]];

        return [
            "no version"             => [["cues" => []], "The field version is missing."],
            "unknown version"        => [["version" => 2, "cues" => []], "The field version must be 1."],
            "version as string"      => [["version" => "1", "cues" => []], "The field version must be 1."],
            "no cues"                => [["version" => 1], "The field cues must be a list."],
            "cues as object"         => [["version" => 1, "cues" => ["a" => $cue]], "The field cues must be a list."],
            "cue as string"          => [["version" => 1, "cues" => [$cue, "Rain"]], "The field cues[1] must be an object."],
            "start as string"        => [["version" => 1, "cues" => [$cue, $cue, $cue, ["start" => "1"] + $cue]], "The field cues[3].start must be a number."],
            "no end"                 => [["version" => 1, "cues" => [["start" => 1, "lines" => []]]], "The field cues[0].end must be a number."],
            "lines as string"        => [["version" => 1, "cues" => [["lines" => "Rain"] + $cue]], "The field cues[0].lines must be a list."],
            "line as number"         => [["version" => 1, "cues" => [["lines" => ["Rain", 7]] + $cue]], "The field cues[0].lines[1] must be a string."],
            "identifier as number"   => [["version" => 1, "cues" => [["identifier" => 7] + $cue]], "The field cues[0].identifier must be a string or null."],
            "alignment out of range" => [["version" => 1, "cues" => [["alignment" => 10] + $cue]], "The field cues[0].alignment must be an integer from 1 to 9 or null."],
            "cue format data string" => [["version" => 1, "cues" => [["formatData" => "a"] + $cue]], "The field cues[0].formatData must be an object."],
            "cue format data value"  => [["version" => 1, "cues" => [["formatData" => ["ass" => "x"]] + $cue]], "The field cues[0].formatData.ass must be an object."],
            "metadata value number"  => [["version" => 1, "metadata" => ["title" => 7], "cues" => []], "The field metadata.title must be a string."],
            "metadata as string"     => [["version" => 1, "metadata" => "Bakery", "cues" => []], "The field metadata must be an object."],
            "format data string"     => [["version" => 1, "formatData" => ["ass" => "x"], "cues" => []], "The field formatData.ass must be an object."],
            "comments as object"     => [["version" => 1, "comments" => ["a" => []], "cues" => []], "The field comments must be a list."],
            "comment as string"      => [["version" => 1, "comments" => ["Note"], "cues" => []], "The field comments[0] must be an object."],
            "comment without text"   => [["version" => 1, "comments" => [["beforeCueIndex" => 0]], "cues" => []], "The field comments[0].text must be a string."],
            "negative comment index" => [["version" => 1, "comments" => [["text" => "Note", "beforeCueIndex" => -1]], "cues" => []],
                                         "The field comments[0].beforeCueIndex must be an integer of 0 or more."],
        ];
    }


    #[DataProvider("badArrays")]
    public function testFromArrayThrowsWithThePathOfTheBadField(array $data, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        Subtitle::fromArray($data);
    }


    public static function realFiles(): array
    {
        $files = [];
        foreach (glob(self::DIR . "*/real/*.*") as $path) {
            $name = substr($path, strlen(self::DIR));
            if (!str_ends_with($path, ".md") && !str_starts_with($name, "plaintext/") && !in_array($name, self::UNREADABLE, true)) {
                $files[$name] = [$name];
            }
        }

        return $files;
    }


    #[DataProvider("realFiles")]
    public function testEveryRealFileSurvivesTheArrayAndJsonRoundTrip(string $file): void
    {
        $content   = file_get_contents(self::DIR . $file);
        $directory = explode("/", $file)[0];
        $subtitle  = match (true) {
            str_starts_with($file, "microdvd/")            => (new MicroDvdParser())->parse($content, new ReadOptions(formats: [new MicroDvdReadOptions(25)])),
            $file === "csv/real/dubbing_script.csv"        => (new CsvParser())->parse($content, new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: "Start TC", speaker: "Character"), frameRate: 25)])),
            $file === "csv/real/excel_de_semicolon.csv"    => (new CsvParser())->parse($content, new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(end: "Ende", speaker: "Sprecher"))])),
            $file === "sami/real/own_korean_cp949.smi"     => Subtitle::fromString($content, Format::Sami, new ReadOptions(TextEncoding::Cp949)),
            str_starts_with($file, "csv/")                 => (new CsvParser())->parse($content, new ReadOptions()),
            in_array($directory, self::CLOUD_SPEECH, true) => Subtitle::fromString($content, Format::from($directory)),
            default                                        => Subtitle::fromString($content),
        };

        $this->assertEquals($subtitle->toArray(), Subtitle::fromArray($subtitle->toArray())->toArray());
        $this->assertEquals($subtitle->toArray(), Subtitle::fromString($subtitle->toString(Format::Json))->toArray());
    }
}
