<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Runs each command once with bin/subtitle-toolbox, because the other CLI tests run the commands in the test process.
 */
class BinarySmokeTest extends CliTestCase
{
    /**
     * @return array<string, array{list<string>, int, 2?: string}>
     */
    public static function commandLines(): array
    {
        return [
            "--version"           => [["--version"], 0],
            "help"                => [["help", "convert"], 0],
            "formats"             => [["formats"], 0],
            "convert srt to vtt"  => [["convert", "trip.srt", "--to", "vtt", "-o", "trip.vtt"], 0],
            "convert pgs to pgs"  => [["convert", self::FILES . "pgs/text_1080p.sup", "--to", "pgs", "-o", "text.sup"], 0],
            "convert stdin"       => [["convert", "-", "--from", "srt", "--to", "ass", "-o", "-"], 0, file_get_contents(self::FIXTURES . "trip.srt")],
            "convert usage error" => [["convert", "trip.srt", "--to", "nope"], 2],
            "convert file error"  => [["convert", "missing.srt", "--to", "vtt", "-o", "-"], 3],
            "retime"              => [["retime", "trip.srt", "--shift", "1", "-o", "late.srt"], 0],
            "info"                => [["info", "trip.srt", "shop.vtt", "--json"], 0],
            "validate"            => [["validate", "trip.srt", "--preset", "bbc"], 1],
            "sync"                => [["sync", "trip.srt", "--reference", "shop.vtt", "-o", "-"], 0],
            "diff"                => [["diff", "trip.srt", "shop.vtt"], 1],
            "translate"           => [["translate", "trip.srt", "--engine", "deepl", "--target-language", "de"], 2],
            "dual"                => [["dual", "--primary", "trip.srt", "--secondary", "shop.vtt", "-o", "-"], 0],
            "hls"                 => [["hls", "trip.srt", "--output-dir", "hls"], 0],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("commandLines")]
    public function testBinaryGivesTheResultOfTheRunInTheTestProcess(array $arguments, int $code, string $stdin = ""): void
    {
        $binary      = $this->runBinary($arguments, $stdin);
        $binaryFiles = $this->snapshot();
        $this->tearDown();
        $this->setUp();

        $this->assertSame($code, $binary[0], $binary[2]);
        $this->assertSame([$binary, $binaryFiles], [$this->runCli($arguments, $stdin), $this->snapshot()]);
    }
}
