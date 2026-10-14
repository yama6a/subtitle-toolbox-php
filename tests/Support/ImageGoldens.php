<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

/**
 * Lists the pixel goldens of the PGS, VobSub and DVB subtitle fixtures and renders a parse result as a golden file.
 * A pixel golden is a JSON list with the times, the position, the size and the SHA-256 of the RGBA bytes of each cue.
 * The hash does not depend on the PNG encoder. A PNG of the first cue lies next to it, for a reviewer to look at.
 */
final class ImageGoldens
{
    private const DVB_SUBTITLE_TRACKS = ["mpegts/dvb_subtitles.ts" => [257, 258], "mkv/dvbsub.mkv" => [2, 3]];

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;


    /**
     * Returns one case per golden: the fixture under tests/files, the VobSub or container track or null for PGS, and the
     * golden path without its extension.
     *
     * @return array<string, array{string, ?int, string}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (glob(TestFiles::DIR . "pgs/*.sup") ?: [] as $path) {
            $cases["pgs/" . basename($path)] = ["pgs/" . basename($path), null, "pgs/expected/" . basename($path)];
        }
        foreach (glob(TestFiles::DIR . "vobsub/*.idx") ?: [] as $path) {
            preg_match_all('/^id:.*index:\s*(\d+)/mi', file_get_contents($path), $matches);
            $fixture = "vobsub/" . basename($path);
            foreach ($matches[1] as $track) {
                $cases["$fixture $track"] = [$fixture, (int) $track, "vobsub/expected/" . basename($path) . ".$track"];
            }
        }

        foreach (self::DVB_SUBTITLE_TRACKS as $fixture => $tracks) {
            foreach ($tracks as $track) {
                $cases["$fixture $track"] = [$fixture, $track, dirname($fixture) . "/expected/" . basename($fixture) . ".$track"];
            }
        }

        return $cases;
    }


    /**
     * Returns the VobSub tracks whose PGS output has a write golden, as [fixture, track, golden].
     *
     * @return array<string, array{string, int, string}>
     */
    public static function writeCases(): array
    {
        $cases = [];
        foreach (self::cases() as $key => [$fixture, $track, $golden]) {
            if (str_ends_with($fixture, ".idx")) {
                $cases[$key] = [$fixture, $track, "$golden.to.sup"];
            }
        }

        return $cases;
    }


    public static function parse(string $fixture, ?int $track): Subtitle
    {
        if ($track === null) {
            return TestFiles::parse($fixture, Format::Pgs);
        }
        if (isset(self::DVB_SUBTITLE_TRACKS[$fixture])) {
            return Subtitle::loadTrack(TestFiles::DIR . $fixture, $track);
        }

        return TestFiles::parse(substr($fixture, 0, -4) . ".sub", Format::VobSub,
                                new ReadOptions(formats: [new VobSubReadOptions(file_get_contents(TestFiles::DIR . $fixture), track: $track)]));
    }


    public static function render(Subtitle $subtitle): string
    {
        $cues = [];
        foreach ($subtitle->getCues() as $cue) {
            $image  = CueImage::fromCue($cue);
            $cues[] = [
                "start"        => $cue->getStart(),
                "end"          => $cue->getEnd(),
                "x"            => $image->x,
                "y"            => $image->y,
                "width"        => $image->width,
                "height"       => $image->height,
                "screenWidth"  => $image->screenWidth,
                "screenHeight" => $image->screenHeight,
                "forced"       => $image->forced,
                "alignment"    => $cue->getAlignment()?->name,
                "rgbaSha256"   => self::rgbaHash($image->png),
            ];
        }

        return json_encode($cues, self::JSON_FLAGS) . "\n";
    }


    public static function rgbaHash(string $png): string
    {
        return hash("sha256", pack("N*", ...PngDecoder::decode($png)["pixels"]));
    }
}
