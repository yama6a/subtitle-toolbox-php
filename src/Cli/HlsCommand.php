<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class HlsCommand extends FileCommand
{
    public const DEFAULT_PLAYLIST = "subs.m3u8";

    private ?HlsSegmentOptions $segmentOptions = null;

    private string $directory = "";

    private string $playlist = "";


    public function name(): string
    {
        return "hls";
    }


    public function summary(): string
    {
        return "Cut a subtitle into WebVTT segments and write an HLS playlist for them.";
    }


    protected function usageLines(): array
    {
        return ["<input> --output-dir DIR [--segment SECONDS] [options]"];
    }


    protected function details(): string
    {
        return "Each segment starts with an X-TIMESTAMP-MAP header and holds every cue that overlaps it, with its full times. " .
               "The playlist is a VOD media playlist.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("output-dir", "DIR", "Directory for the segments and the playlist. Creates it when it is missing. No playlist or segment file may exist."),
            Option::value("segment", "SECONDS", "Duration of a segment. Default: 6."),
            Option::value("playlist", "NAME", "File name of the playlist. Default: subs.m3u8."),
            Option::value("pattern", "PATTERN", "File name of a segment, with %d for its number from 0. Default: sub%d.vtt."),
            Option::value("mpegts", "TICKS", "90 kHz MPEG-2 timestamp at which subtitle time 0 plays. Default: 900000."),
            Option::value("local", "SECONDS", "WebVTT cue time that maps to --mpegts. Default: 0."),
            Option::value("media-duration", "SECONDS", "Duration of the video, so the playlist covers it all. Default: the end of the last cue."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->directory = $arguments->value("output-dir") ?? self::fail("Pass --output-dir DIR.");
        $this->playlist  = $arguments->value("playlist") ?? self::DEFAULT_PLAYLIST;
        self::checkOutputDirectory($this->directory);

        $this->segmentOptions = new HlsSegmentOptions(...self::withoutNulls([
            "segmentDuration" => $arguments->positiveSeconds("segment"),
            "mpegts"          => $arguments->int("mpegts", 0),
            "local"           => $arguments->nonNegativeSeconds("local"),
            "fileNamePattern" => $arguments->value("pattern"),
            "mediaDuration"   => $arguments->positiveSeconds("media-duration"),
        ]));
    }


    protected function takesManyInputs(): bool
    {
        return false;
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1) {
            self::fail("The hls command takes one input file, got " . count($inputs) . ".");
        }

        $playlist = self::realTarget(rtrim($this->directory, "/\\") . "/$this->playlist");
        if ($this->isSegment($playlist)) {
            self::fail("The playlist $this->playlist has the name of a segment. Pass another --playlist or --pattern.");
        }
        $input = $inputs[0] === self::DASH ? false : realpath($inputs[0]);
        if ($input !== false && ($input === $playlist || $this->isSegment($input))) {
            self::fail("The output would overwrite the input $inputs[0]. Pass another --output-dir, --playlist or --pattern.");
        }

        // The segment count is known only after the read, so any file that matches the pattern counts as a segment.
        $directory = rtrim($this->directory, "/\\");
        $existing  = OutputFiles::exists($playlist) ? [$this->playlist] : [];
        foreach (is_dir($directory) ? scandir($directory) ?: [] : [] as $name) {
            if ($this->isSegment(self::realTarget("$directory/$name"))) {
                $existing[] = $name;
            }
        }
        if ($existing !== []) {
            self::fail("$directory/$existing[0] exists. The tool never overwrites a file. Remove the playlist and the segments, or pass another --output-dir.");
        }
    }


    private function isSegment(string $realPath): bool
    {
        $directory = self::realTarget(rtrim($this->directory, "/\\")) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $directory)) {
            return false;
        }
        $name    = substr($realPath, strlen($directory));
        $pattern = $this->segmentOptions->fileNamePattern;
        $parsed  = sscanf($name, $pattern);
        $number  = is_array($parsed) ? $parsed[0] : null;

        return is_int($number) && $number >= 0 && sprintf($pattern, $number) === $name;
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $result    = HlsWebVttSegmenter::segment($subtitle, $this->segmentOptions);
        $directory = rtrim($this->directory, "/\\");

        foreach ($result->getSegments() as $name => $content) {
            $this->outputFiles->create("$directory/$name", $content);
        }
        $this->outputFiles->create("$directory/$this->playlist", $result->getPlaylist());

        $console->out(self::label($input) . " -> $directory/$this->playlist, " . $result->getSegmentCount() . " segments\n");
    }
}
