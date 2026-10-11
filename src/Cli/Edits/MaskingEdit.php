<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\FileCommand;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Cli\OutputFiles;
use SubtitleToolbox\Cli\SideFile;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityMask;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class MaskingEdit extends Edit
{
    private const MASKS = [
        "stars"        => ProfanityMask::Stars,
        "first-letter" => ProfanityMask::FirstLetter,
        "remove"       => ProfanityMask::Remove,
        "none"         => ProfanityMask::None,
    ];

    /** @var list<MuteRange> */
    private array $muteRanges = [];


    private ProfanityOptions $options;


    private function __construct(
        private readonly string $wordsPath,
        private readonly ?ProfanityMask $mask,
        private readonly ?float $padding,
        private readonly ?string $edlPath,
        private readonly ?string $filterPath,
    ) {
    }


    public static function group(): string
    {
        return "masking";
    }


    public static function summary(): string
    {
        return "Mask words and write the mute times for players.";
    }


    public static function options(): array
    {
        return [
            Option::value("mask-words", "FILE", "Mask the words of this file, one per line. A * at the end matches any ending."),
            Option::value("mask", "STYLE", "How --mask-words masks a word: stars, first-letter, remove, or none to keep the text. Default: stars."),
            Option::value("mute-edl", "FILE", "Write the times of the --mask-words matches to this EDL (edit decision list) file. Kodi and MPlayer read it to mute the audio."),
            Option::value("mute-filter", "FILE", "Write an FFmpeg volume filter that mutes the --mask-words matches to this file."),
            Option::value("mute-padding", "SECONDS", "Widen each mute range by this time on both sides. Default: 0."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "mask-words", ["mask", "mute-edl", "mute-filter", "mute-padding"]);
        $words = $arguments->value("mask-words");
        if ($words === null) {
            return null;
        }

        $mask = $arguments->choice("mask", array_keys(self::MASKS));
        foreach (["mute-edl", "mute-filter"] as $option) {
            $path = $arguments->value($option);
            if ($path === FileCommand::DASH) {
                Command::fail("The option --$option needs a file path.");
            }
        }

        return new self(
            $words,
            $mask === null ? null : self::MASKS[$mask],
            $arguments->nonNegativeSeconds("mute-padding"),
            $arguments->value("mute-edl"),
            $arguments->value("mute-filter"),
        );
    }


    public function takesManyInputs(): bool
    {
        return $this->edlPath === null && $this->filterPath === null;
    }


    public function loadSideFiles(): void
    {
        $this->options = new ProfanityOptions(self::readWordFile($this->wordsPath), ...Command::withoutNulls([
            "mask"    => $this->mask,
            "padding" => $this->padding,
        ]));
    }


    /**
     * Reads one word per line. A UTF-8 BOM, CR LF line endings and empty lines do not count.
     *
     * @return list<string>
     */
    private static function readWordFile(string $path): array
    {
        $lines = preg_split('/\R/', StringHelpers::removeUtf8Bom(SideFile::read($path)));

        return array_values(array_filter(array_map("trim", $lines), fn (string $line): bool => $line !== ""));
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $this->muteRanges = ProfanityFilter::apply($subtitle, $this->options)->muteRanges;

        return $subtitle;
    }


    /**
     * Returns the files of --mute-edl and --mute-filter, by option name.
     *
     * @return array<string, string>
     */
    public function outputPaths(): array
    {
        return array_filter(["mute-edl" => $this->edlPath, "mute-filter" => $this->filterPath], fn (?string $path): bool => $path !== null);
    }


    /**
     * Writes the mute ranges of the last apply() to the files of --mute-edl and --mute-filter.
     * Returns the paths of the files.
     *
     * @return list<string>
     */
    public function writeMuteFiles(OutputFiles $files): array
    {
        $written = [];
        foreach ([[$this->edlPath, MuteRange::toEdl(...)], [$this->filterPath, MuteRange::toFfmpegVolumeFilter(...)]] as [$path, $render]) {
            if ($path === null) {
                continue;
            }
            $content = $render($this->muteRanges);
            $files->create($path, $content === "" || str_ends_with($content, "\n") ? $content : "$content\n");
            $written[] = $path;
        }

        return $written;
    }
}
