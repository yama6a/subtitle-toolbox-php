<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\TestCase;

class HelpLayoutTest extends TestCase
{
    public function testAWordWiderThanALineGetsALineOfItsOwn(): void
    {
        $path = "/media/" . str_repeat("x", 83);

        $this->assertSame("  --log  Read\n         $path\n         from here.\n", HelpLayout::table([["--log", "Read $path from here."]]));
    }


    public function testTheLastColumnWrapsWithAHangingIndent(): void
    {
        $words = fn (int $count): string => implode(" ", array_fill(0, $count, "word"));

        $this->assertSame(
            "  -o      Output.\n  --from  " . $words(14) . "\n          " . $words(6) . "\n",
            HelpLayout::table([["-o", "Output."], ["--from", $words(20)]])
        );
    }


    public function testAnEmptyLastCellLeavesNoTrailingSpaces(): void
    {
        $this->assertSame("  --lenient\n  --json     Print JSON.\n", HelpLayout::table([["--lenient", ""], ["--json", "Print JSON."]]));
    }


    public function testAnOptionGroupAtTheWrapPointMovesToTheNextLineWhole(): void
    {
        $this->assertSame(
            "Usage: stbox convert <input>... --to FORMAT --from FMT --errors-fix\n" .
            "                     [--output-dir DIR] [options]\n",
            HelpLayout::usage("Usage: stbox convert", "<input>... --to FORMAT --from FMT --errors-fix [--output-dir DIR] [options]")
        );
    }


    public function testAnOptionAndItsValueAtTheWrapPointMoveToTheNextLineTogether(): void
    {
        $this->assertSame(
            "Usage: stbox convert <input>... --to FORMAT [-o FILE.srt] --errors-fix\n" .
            "                     --encoding NAME [options]\n",
            HelpLayout::usage("Usage: stbox convert", "<input>... --to FORMAT [-o FILE.srt] --errors-fix --encoding NAME [options]")
        );
    }


    public function testWrapKeepsALineThatStartsWithASpace(): void
    {
        $command = "  stbox convert " . str_repeat("film.srt ", 10) . "--to vtt";

        $this->assertSame("$command\n", HelpLayout::wrap($command));
    }
}
