<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Tests\Support\CliTestCase;

class BinaryUnwrapTest extends CliTestCase
{
    public function testUnwrapKeepsDialogueTurnsApart(): void
    {
        $files = __DIR__ . "/../files/fixes/";
        copy($files . "own_dialogue_turns_wrapped.srt", "$this->dir/turns.srt");

        [$code, $stdout, $stderr] = $this->runCli(["convert", "turns.srt", "--to", "srt", "-o", "out.srt", "--no-bom", "--structure-unwrap"]);

        $this->assertSame([0, "turns.srt -> out.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame(file_get_contents($files . "own_dialogue_turns_unwrapped.srt"), $this->file("out.srt"));
    }
}
