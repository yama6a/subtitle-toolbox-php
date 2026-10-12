<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Lets another process create an output file between the check and the write, and checks that the run removes its own files.
 */
class OutputRaceTest extends CliTestCase
{
    private const FOREIGN = "written by another process";


    public function testBatchConvertRemovesTheOutputsOfEarlierInputs(): void
    {
        foreach (["a", "b", "c"] as $name) {
            copy("$this->dir/trip.srt", "$this->dir/$name.srt");
        }

        $this->assertSame([3, "a.srt -> out/a.vtt\n", "Error: out/b.vtt exists. Removed the file that this run wrote.\n"],
                          $this->runRacing(["convert", "a.srt", "b.srt", "c.srt", "--to", "vtt", "--output-dir", "out"], "out/b.vtt"));
        $this->assertSame(["b.vtt"], array_values(array_diff(scandir("$this->dir/out"), [".", ".."])));
        $this->assertSame(self::FOREIGN, $this->file("out/b.vtt"));
    }


    public function testConvertRemovesTheSubtitleOutputWhenTheMuteFileAppears(): void
    {
        copy(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");

        $this->assertSame([3, "radio.vtt -> new.srt\n", "Error: radio.edl exists. Removed the file that this run wrote.\n"],
                          $this->runRacing(["convert", "radio.vtt", "--to", "srt", "-o", "new.srt", "--mask-words", "words.txt", "--mute-edl", "radio.edl"],
                                           "radio.edl"));
        $this->assertFileDoesNotExist("$this->dir/new.srt");
        $this->assertSame(self::FOREIGN, $this->file("radio.edl"));
    }


    public function testHlsRemovesTheSegmentsWhenThePlaylistAppears(): void
    {
        $this->assertSame([3, "", "Error: hls/subs.m3u8 exists. Removed the 2 files that this run wrote.\n"],
                          $this->runRacing(["hls", "trip.srt", "--output-dir", "hls"], "hls/subs.m3u8"));
        $this->assertSame(["subs.m3u8"], array_values(array_diff(scandir("$this->dir/hls"), [".", ".."])));
        $this->assertSame(self::FOREIGN, $this->file("hls/subs.m3u8"));
    }


    /**
     * Runs the CLI in the temporary directory. Just before the run creates $racedPath, another process creates it.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private function runRacing(array $arguments, string $racedPath): array
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        $race    = function (string $path) use ($racedPath): void {
            if ($path === $racedPath) {
                file_put_contents($path, self::FOREIGN);
            }
        };

        $cwd = getcwd();
        chdir($this->dir);
        try {
            $code = (new Application(...$streams, beforeCreate: $race))->run(["stbox", ...$arguments]);
        } finally {
            chdir($cwd);
        }
        rewind($streams[1]);
        rewind($streams[2]);

        return [$code, stream_get_contents($streams[1]), stream_get_contents($streams[2])];
    }
}
