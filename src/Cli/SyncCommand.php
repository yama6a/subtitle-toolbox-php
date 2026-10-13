<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;
use SubtitleToolbox\OptionsCopy;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;

/**
 * @internal
 */
final class SyncCommand extends WriteCommand
{
    private const LOW_SCORE = 0.5;

    private const SCALE_DECIMALS   = 5;
    private const SECONDS_DECIMALS = 3;
    private const SCORE_DECIMALS   = 2;

    private ?ReferenceSyncOptions $syncOptions = null;

    private Subtitle $reference;

    private ?float $mediaDuration = null;

    private ?int $referenceTrack = null;


    public function name(): string
    {
        return "sync";
    }


    public function summary(): string
    {
        return "Find the offset and frame-rate scale against a reference subtitle or the speech, and retime the input.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --reference FILE [options]", "<input>... --silence-log FILE --media-duration SECONDS [options]"];
    }


    protected function details(): string
    {
        return "Only the cue times count, so the reference can be in another language. " .
               "The scale is 1 or a factor between 23.976, 24 and 25 fps. sync prints the scale, the offset and a score from 0 to 1. " .
               "A score below 0.5 means that the files likely do not match. " .
               self::OUTPUT_DETAILS . "\n" .
               "For a sync to the speech, run this command and pass --silence-log silence.log:\n" .
               "  ffmpeg -i movie.mkv -af silencedetect=n=-30dB:d=0.4 -f null - 2> silence.log\n" .
               "A Whisper JSON transcript of the audio also works as --reference.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("reference", "FILE", "Subtitle in sync with the video, in any format that the tool reads."),
            Option::value("reference-track", "NUMBER", "Subtitle track of an MKV, WebM, MP4 or MPEG-TS reference file. Needed when the file has 2 or more subtitle tracks."),
            Option::value("silence-log", "FILE", "Log of the FFmpeg silencedetect filter. The speech between the silences is the reference."),
            Option::value("media-duration", "SECONDS", "Duration of the video, for --silence-log."),
            Option::value("min-offset", "SECONDS", "Smallest offset to try. Default: -60."),
            Option::value("max-offset", "SECONDS", "Largest offset to try. Default: 60."),
            Option::flag("no-scale", "Keep the scale at 1 and find only the offset."),
            Option::value("max-splits", "SPLITS", "Find up to this many points where the offset jumps, for example at ad breaks. Default: 0."),
            Option::value("split-penalty", "SCORE", "Score that a split must add to stay. Default: 0.1."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->checkReference($arguments);

        // The reference loads after the checks of the inputs. Until then, an empty subtitle stands in for it.
        $this->syncOptions = new ReferenceSyncOptions(...self::withoutNulls([
            "reference"    => new Subtitle(),
            "minOffset"    => $arguments->seconds("min-offset"),
            "maxOffset"    => $arguments->seconds("max-offset"),
            "searchScale"  => !$arguments->has("no-scale"),
            "maxSplits"    => $arguments->int("max-splits", 0, ReferenceSyncOptions::MAX_SPLITS),
            "splitPenalty" => $arguments->nonNegativeFloat("split-penalty"),
        ]));
    }


    private function checkReference(Arguments $arguments): void
    {
        if ($arguments->has("reference") === $arguments->has("silence-log")) {
            self::fail("Pass one of --reference FILE and --silence-log FILE.");
        }
        if ($arguments->has("silence-log") !== $arguments->has("media-duration")) {
            self::fail("Pass --media-duration with --silence-log.");
        }
        if ($arguments->has("reference-track") && !$arguments->has("reference")) {
            self::fail("Pass --reference with --reference-track.");
        }
        $this->mediaDuration  = $arguments->positiveSeconds("media-duration");
        $this->referenceTrack = $arguments->positiveInt("reference-track");
    }


    protected function loadSideFiles(Arguments $arguments, Console $console): void
    {
        $log = $arguments->value("silence-log");
        if ($log === null) {
            $this->reference = $this->loadSideSubtitle($arguments->value("reference"), $console, $this->referenceTrack, "--reference-track");

            return;
        }

        $this->reference = SideFile::parse($log, fn (string $content): Subtitle =>
            SpeechReference::fromFfmpegSilencedetect($content, $this->mediaDuration));
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $result = ReferenceSync::apply($subtitle, OptionsCopy::with($this->syncOptions, ["reference" => $this->reference]));

        $label = self::label($input);
        $text  = "$label: scale " . self::number($result->scale, self::SCALE_DECIMALS) . ", offset " .
                 self::number($result->offset, self::SECONDS_DECIMALS) . " s, score " . self::number($result->score, self::SCORE_DECIMALS) . "\n";
        $segments = $result->segments;
        if (count($segments) > 1) {
            foreach ($segments as $segment) {
                $text .= "$label: from " . self::number($segment->from, self::SECONDS_DECIMALS) . " s: offset " .
                         self::number($segment->offset, self::SECONDS_DECIMALS) . " s\n";
            }
        }
        $console->err($text);
        if ($result->score < self::LOW_SCORE) {
            $console->err("$label: the score is below " . self::LOW_SCORE . ", so the files likely do not match.\n");
        }

        parent::process($input, $subtitle, $format, $arguments, $console);
    }
}
