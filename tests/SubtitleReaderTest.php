<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnknownFormatException;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;

class SubtitleReaderTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";

    private string $dir;


    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . "/subtitle-reader-" . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }


    protected function tearDown(): void
    {
        array_map("unlink", glob("$this->dir/*"));
        rmdir($this->dir);
    }


    /**
     * @return array<string, array{string, string, string}>
     */
    public static function vobSubPairs(): array
    {
        return [
            "upper-case .IDX next to the .SUB" => ["movie.SUB", "movie.IDX", "movie.SUB"],
            "upper-case .SUB next to the .IDX" => ["movie.SUB", "movie.IDX", "movie.IDX"],
            "upper-case .IDX next to a .sub"   => ["movie.sub", "movie.IDX", "movie.sub"],
            "path without an extension"        => ["movie", "movie.idx", "movie"],
            "path that ends with a dot"        => ["movie.", "movie.idx", "movie."],
        ];
    }


    #[DataProvider("vobSubPairs")]
    public function testVobSubFindsTheOtherFileOfThePair(string $subName, string $idxName, string $loadName): void
    {
        $idx = file_get_contents(self::FILES . "vobsub/text-pal.idx");
        $sub = file_get_contents(self::FILES . "vobsub/text-pal.sub");
        file_put_contents("$this->dir/$subName", $sub);
        file_put_contents("$this->dir/$idxName", $idx);

        $this->assertSame(
            Subtitle::fromString($sub, Format::VobSub, new ReadOptions(formats: [new VobSubReadOptions($idx)]))->toArray(),
            SubtitleReader::load("$this->dir/$loadName", Format::VobSub, new ReadOptions())->toArray()
        );
    }


    public function testVobSubNamesTheMissingFileOfThePair(): void
    {
        file_put_contents("$this->dir/movie.IDX", file_get_contents(self::FILES . "vobsub/text-pal.idx"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("VobSub needs the .sub file next to $this->dir/movie.IDX, but $this->dir/movie.sub does not exist.");
        SubtitleReader::load("$this->dir/movie.IDX", Format::VobSub, new ReadOptions());
    }


    public function testAnEmptyFileTakesTheFormatOfItsExtension(): void
    {
        file_put_contents("$this->dir/empty.srt", " \n");

        $subtitle = SubtitleReader::loadAutoDetect("$this->dir/empty.srt", new ReadOptions());

        $this->assertSame(Format::SubRip, $subtitle->findFormat());
        $this->assertCount(0, $subtitle);
    }


    public function testAnEmptyFileWithAnExtensionOfAFormatWithoutDetectionThrows(): void
    {
        file_put_contents("$this->dir/empty.json", "");

        $this->expectException(UnknownFormatException::class);
        SubtitleReader::loadAutoDetect("$this->dir/empty.json", new ReadOptions());
    }
}
