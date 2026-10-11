<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;

class EncodingBatchTest extends CliTestCase
{
    public function testEncodingConvertsOnlyTheFilesThatAreNotUtf8(): void
    {
        mkdir("$this->dir/season1");
        copy(self::FILES . "encoding/arabic-utf-8.srt", "$this->dir/season1/utf8.srt");
        copy(self::FILES . "encoding/arabic-windows-1256.srt", "$this->dir/season1/legacy.srt");

        [$code, , $stderr] = $this->runCli(["convert", "season1", "--encoding", "Windows-1256", "--to", "srt", "--output-dir", "out"]);

        $expected = Subtitle::load(self::FILES . "encoding/arabic-utf-8.srt", Format::SubRip)->toString(Format::SubRip);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringContainsString("مرحبا", $expected);
        $this->assertSame($expected, $this->file("out/utf8.srt"));
        $this->assertSame($expected, $this->file("out/legacy.srt"));
    }
}
