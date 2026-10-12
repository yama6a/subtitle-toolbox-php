<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\ParseWarning;

/**
 * Prints the parse warnings of an input and rewords library messages for the command line.
 *
 * @internal
 */
final class CliMessages
{
    /**
     * @param list<ParseWarning> $warnings
     */
    public static function printWarnings(Console $console, string $label, array $warnings): void
    {
        foreach ($warnings as $warning) {
            $line = $warning->lineNumber === null ? "" : "line $warning->lineNumber: ";
            $console->err("$label: $line" . self::reword($warning->message, null, null) . " ({$warning->action->value})\n");
        }
    }


    /**
     * Rewords a library message that names a PHP method, class or option property, so that it names CLI options.
     * $track and $from name the options that pick the track and the format of the file, or are null.
     */
    public static function reword(string $message, ?string $track, ?string $from): string
    {
        $pickTrack  = $track === null ? "Write one of them to a subtitle file with convert --track N first:" : "Pass $track N with one of them:";
        $pickFormat = $from === null
            ? "Write it to a subtitle file with convert --from FORMAT first. Chapters and cloud speech-to-text JSON always need --from, for example --from deepgram."
            : "Pass $from FORMAT. Chapters and cloud speech-to-text JSON always need it, for example $from deepgram.";
        $message    = preg_replace(
            '/^(\w+ \(Error #\d+\): )?.+ is an (MKV or WebM|MP4) file\. Call loadTrack\(\) with a track number\.$/s',
            '$1The input is an $2 file. ' . ($track === null ? "Write one track to a subtitle file with convert --track N first." : "Pass $track N."),
            $message
        ) ?? $message;

        return strtr($message, [
            "Call loadTrack() with one of them:"                                => $pickTrack,
            "Call load() with a format. Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram."       => $pickFormat,
            "Call fromString() with a format. Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram." => $pickFormat,
            "Set MicroDvdWriteOptions::\$frameRate."                                   => "Pass --fps or --output-fps.",
            "Set IttWriteOptions::\$frameRate."                                        => "Pass --fps or --output-fps.",
            "Set MicroDvdReadOptions::\$frameRate, start the file with {1}{1}<fps>, or read the file in lenient mode for 23.976 fps." =>
                "Pass --fps or --input-fps, start the file with {1}{1}<fps>, or pass --lenient for 23.976 fps.",
            "Set CsvReadOptions::\$frameRate."                                         => "Pass --fps or --input-fps.",
            "Read a file in another encoding with ReadOptions::\$encoding set to its source encoding." => "Pass --encoding with the source encoding of the file.",
            "Set ReadOptions::\$encoding to the source encoding."                    => "Pass --encoding with the source encoding of the file.",
            "Set ReadOptions::\$encoding if that is wrong."                          => "Pass --encoding if that is wrong.",
            "Call wrapLines(32, 4) first."                                      => "Pass --structure-wrap --structure-max-cpl 32 --structure-max-lines 4.",
            "Call Resegmenter::apply() with ResegmentMode::SplitLong and new CueLimits(32, 4), then wrapLines(32, 4)." =>
                "Pass --structure-split-long --structure-wrap --structure-max-cpl 32 --structure-max-lines 4.",
        ]);
    }
}
