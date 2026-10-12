<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Containers;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\SubtitleStatistics;

/**
 * @internal
 */
final class InfoCommand extends ReportCommand
{
    public function name(): string
    {
        return "info";
    }


    public function summary(): string
    {
        return "Print the format, the cue count and statistics of subtitle files.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--json] [options]"];
    }


    protected function details(): string
    {
        return "Times are in seconds. Character counts leave out tags. " .
               "The gap is the start of a cue minus the latest end of the earlier cues. An overlap gives a negative gap. " .
               "For an MKV, WebM or MP4 file, info lists the subtitle tracks. Pass --track for the statistics of one track.";
    }


    protected function commandOptions(): array
    {
        return [];
    }


    protected function listTracks(string $path, string $input, Console $console): bool
    {
        $container = Containers::detectFile($path);
        if (Format::tryFromPath($input) !== null || $container === null) {
            return false;
        }
        try {
            $tracks = Subtitle::tracks($path);
        } catch (ParsingException) {
            return false;
        }

        $text      = self::label($input) . "\n  Container: $container->value\n";
        foreach ($tracks as $track) {
            $text .= "  Track $track->number: " . $track->describe() . "\n";
        }
        $this->emit($console, ($this->succeeded > 0 ? "\n" : "") . $text, [
            "file"      => $input,
            "container" => $container->value,
            "tracks"    => array_map(fn (SubtitleTrack $track): array => [
                "number"   => $track->number,
                "codecId"  => $track->codecId,
                "language" => $track->language,
                "name"     => $track->name,
                "default"  => $track->default,
                "forced"   => $track->forced,
            ], $tracks),
        ]);

        return true;
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $statistics = SubtitleStatistics::of($subtitle);
        $imageCues  = array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue));
        $images     = [
            "count"    => count($imageCues),
            "withText" => count(array_filter($imageCues, fn (SubtitleCue $cue): bool => $cue->getLines() !== [])),
        ];

        $rows = $this->rows($subtitle, $format, $statistics, $images);
        $text = self::label($input) . "\n" . HelpLayout::table(array_map(fn (string $name, string $value): array => ["$name:", $value],
                                                                   array_keys($rows), $rows), 2, 1);

        $this->emit($console, ($this->succeeded > 0 ? "\n" : "") . $text, [
            "file"       => $input,
            "format"     => $format->value,
            "encoding"   => $subtitle->findSourceEncoding(),
            "metadata"   => (object)$subtitle->getAllMetadata(),
            "statistics" => $statistics->toArray(),
            "imageCues"  => $images,
            "warnings"   => self::warningsJson($this->parseWarnings),
        ]);
    }


    /**
     * Returns the rows of the text output by name.
     *
     * @param array{count: int, withText: int} $images
     * @return array<string, string>
     */
    private function rows(Subtitle $subtitle, Format $format, SubtitleStatistics $statistics, array $images): array
    {
        $words = [];
        foreach (array_slice($statistics->mostUsedWords, 0, SubtitleStatistics::MOST_USED_WORDS) as ["word" => $word, "count" => $count]) {
            $words[] = "$word ($count)";
        }

        $rows = ["Format" => $format->value];
        if ($subtitle->findSourceEncoding() !== null) {
            $rows["Encoding"] = $subtitle->findSourceEncoding();
        }
        $rows["Cues"] = (string)$statistics->cueCount;
        if ($this->parseWarnings !== []) {
            $rows["Warnings"] = (string)count($this->parseWarnings);
        }
        if ($images["count"] > 0) {
            $rows["Image cues"] = "$images[count], $images[withText] with text";
        }
        $rows += [
            "Words"                 => (string)$statistics->wordCount,
            "Characters"            => (string)$statistics->characterCount,
            "Display time"          => self::number($statistics->totalDisplayTime) . " s",
            "Span"                  => $statistics->span === null ? "-" : self::number($statistics->span) . " s",
            "Characters per second" => self::range($statistics->charactersPerSecond),
            "Words per minute"      => self::range($statistics->wordsPerMinute),
            "Characters per line"   => self::range($statistics->charactersPerLine),
            "Gaps"                  => self::range($statistics->gaps, " s"),
            "Most used words"       => implode(", ", $words),
        ];
        foreach ($subtitle->getAllMetadata() as $key => $value) {
            $rows["Metadata $key"] = $value;
        }

        return $rows;
    }


    /**
     * @param ?array{min: float, average: float, max: float} $values
     */
    private static function range(?array $values, string $unit = ""): string
    {
        return $values === null ? "-" : "min " . self::number($values["min"]) . ", average " . self::number($values["average"]) .
                                        ", max " . self::number($values["max"]) . $unit;
    }
}
