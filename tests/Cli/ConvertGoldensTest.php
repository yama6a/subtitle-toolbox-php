<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Runs convert on real files without --from, so the CLI detects the format, and compares the output with the
 * cross-format goldens of the library. So the CLI and the library write the same bytes.
 */
class ConvertGoldensTest extends CliTestCase
{
    /**
     * Each set: the input file, the convert options and the golden file, under tests/files.
     *
     * @return array<string, array{string, list<string>, string}>
     */
    public static function conversions(): array
    {
        return [
            "SubRip to WebVTT"        => ["srt/real/own_styled.srt", ["--to", "vtt"], "srt/real/expected/own_styled.srt.to.vtt"],
            "WebVTT to SubRip"        => ["vtt/real/own_ytdlp_rolling.vtt", ["--to", "srt"], "vtt/real/expected/own_ytdlp_rolling.vtt.to.srt"],
            "ASS to SubRip"           => ["ass/real/own_aegisub.ass", ["--to", "srt"], "ass/real/expected/own_aegisub.ass.to.srt"],
            "Whisper JSON to SubRip"  => ["whisper/real/whisperx_diarize.json", ["--to", "srt"], "whisper/real/expected/whisperx_diarize.json.to.srt"],
            "YouTube json3 to WebVTT" => ["youtube/real/auto.en.json3", ["--to", "vtt"], "youtube/real/expected/auto.en.json3.to.vtt"],
            "Deepgram to WebVTT"      => ["deepgram/real/pool_utterances_diarize.json", ["--from", "deepgram", "--to", "vtt"], "deepgram/real/expected/pool_utterances_diarize.json.to.vtt"],
            "MicroDVD to SubRip"      => ["microdvd/real/sub_microdvd_with_styles.sub", ["--input-fps", "23.976", "--to", "srt"], "microdvd/real/expected/sub_microdvd_with_styles.sub.to.srt"],
            "LRC to SubRip"           => ["lrc/real/handwritten-enhanced.lrc", ["--to", "srt"], "lrc/real/expected/handwritten-enhanced.lrc.to.srt"],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("conversions")]
    public function testConvertToStandardOutputWritesTheGolden(string $input, array $options, string $golden): void
    {
        $this->assertSame([0, file_get_contents(self::FILES . $golden), ""], $this->runCli(["convert", self::FILES . $input, ...$options, "-o", "-"]));
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("conversions")]
    public function testConvertToAFileWritesTheGolden(string $input, array $options, string $golden): void
    {
        $this->assertSame(0, $this->runCli(["convert", self::FILES . $input, ...$options, "-o", "out"])[0]);
        $this->assertFileEquals(self::FILES . $golden, "$this->dir/out");
    }
}
