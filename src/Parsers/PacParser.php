<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Reads PAC files by Screen Electronics and PAC Unicode (.fpc) files. The file does not store its code page, so the
 * parser detects it unless ReadOptions::$encoding names it.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Pac.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Pac.cs), MIT.
 */
final class PacParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Pac->value;

    protected const BINARY = true;

    // The language codes of the "Lang:" line of a story record, as Screen writes them.
    private const STORY_LANGUAGES = [
        "DAR" => "arabic", "OAR" => "arabic", "ARA" => "arabic", "ARB" => "arabic", "ISR" => "hebrew", "HEB" => "hebrew",
        "GRC" => "greek", "GRE" => "greek", "ELL" => "greek", "DRU" => "cyrillic", "RUS" => "cyrillic", "BGR" => "cyrillic",
        "MKD" => "cyrillic", "MNG" => "cyrillic", "UKR" => "cyrillic", "THA" => "thai", "TRD" => "chinese-traditional",
        "OCH" => "chinese-traditional", "CHT" => "chinese-traditional", "SIM" => "chinese-simplified", "CHS" => "chinese-simplified",
        "DJA" => "japanese", "JPN" => "japanese", "DKO" => "korean", "KOR" => "korean", "TUR" => "latin-turkish",
        "CSK" => "latin-czech", "CZE" => "latin-czech",
    ];

    // The highest valid record mark. Subtitle Edit reads 60h to 67h.
    private const LAST_RECORD_MARK = 0x67;

    private const MAX_FRAMES = 29;


    protected function read(string $content): Subtitle
    {
        if (!str_starts_with($content, Pac::HEADER)) {
            throw new ParsingException("A PAC file starts with the byte 01h and 19 zero bytes.");
        }

        [$records, $footer] = $this->records($content);
        $header = Pac::HEADER;
        $story  = $records !== [] && stripos(substr($records[0], Pac::RECORD_TEXT), "STORY:") !== false ? array_shift($records) : null;
        if ($story !== null) {
            $header .= $story;
        }

        $lines     = array_map(fn (string $record): array => Pac::lines(substr($record, Pac::RECORD_TEXT)), $records);
        $codePage  = $this->codePage($lines, $story);
        $frameRate = self::frameRate($records);

        $subtitle = new Subtitle();
        foreach ($records as $index => $record) {
            $cue = $this->cue($record, $lines[$index], $codePage, $frameRate, $index);
            if ($cue !== null) {
                $subtitle->addCue($cue);
            }
        }

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "codePage"    => $codePage->value,
            "frameRate"   => (int) $frameRate->getFramesPerSecond(),
            "firstNumber" => $records === [] ? 0 : unpack("v", $records[0], Pac::RECORD_NUMBER)[1],
            "header"      => bin2hex($header),
            "footer"      => bin2hex($footer),
        ]);

        return $subtitle;
    }


    /**
     * Splits the bytes after the header into records and the end block. A record starts with 00h.
     *
     * @return array{list<string>, string}
     */
    private function records(string $content): array
    {
        $records = [];
        $offset  = strlen(Pac::HEADER);
        $length  = strlen($content);
        while ($offset < $length && $content[$offset] === Pac::RECORD_START) {
            $mark = ord($content[$offset + Pac::RECORD_NUMBER + 2] ?? "\0");
            $end  = $offset + Pac::RECORD_TEXT - 1 + (unpack("v", str_pad(substr($content, $offset + Pac::RECORD_LENGTH, 2), 2, "\0"))[1]);
            if ($mark < Pac::RECORD_MARK || $mark > self::LAST_RECORD_MARK || $end > $length || $end < $offset + Pac::RECORD_TEXT) {
                $this->fail(new ParsingException("The PAC record at byte $offset is not complete."), null, count($records), [bin2hex(substr($content, $offset))]);

                return [$records, substr($content, $offset)];
            }

            $records[] = substr($content, $offset, $end - $offset);
            $offset    = $end;
        }

        $footer = substr($content, $offset);
        if ($footer !== "" && ord($footer[0]) !== Pac::END) {
            $this->fail(new ParsingException("The byte at $offset is neither a PAC record nor the end block."), null, count($records), [bin2hex($footer)]);
        }

        return [$records, $footer];
    }


    /**
     * Takes the code page of ReadOptions::$encoding, else of the "Lang:" line of the story, else detects it.
     *
     * @param list<list<array{alignment: int, runs: list<array{string, string}>}>> $lines
     */
    private function codePage(array $lines, ?string $story): PacCodePage
    {
        if ($this->options->encoding !== null) {
            return PacCodePage::fromEncodingName($this->options->encoding)
                ?? throw new InvalidArgumentException("PAC files have no code page for the encoding \"{$this->options->encoding}\".");
        }

        if ($story !== null && preg_match('/LANG:([A-Z]{3})/i', $story, $matches) === 1 && isset(self::STORY_LANGUAGES[strtoupper($matches[1])])) {
            return PacCodePage::from(self::STORY_LANGUAGES[strtoupper($matches[1])]);
        }

        [$codePage, $reason] = PacCodePageDetector::detect(array_merge(...array_map(
            fn (array $recordLines): array => array_column($recordLines, "runs"),
            $lines ?: [[]],
        )));
        if ($reason !== null) {
            $message = "The code page of the PAC file is not clear. $reason Set ReadOptions::\$encoding to the code page, for example ISO-8859-7 for Greek.";
            if (!$this->options->lenient) {
                throw new ParsingException($message);
            }
            $this->warn("$message The parser read it as {$codePage->value}.", null, null, [], ParseWarningAction::Repaired);
        }

        return $codePage;
    }


    /**
     * Returns 30 fps when a time code has a frame number from 25 to 29, else 25 fps. The file does not store the frame rate.
     *
     * @param list<string> $records
     */
    private static function frameRate(array $records): FrameRate
    {
        foreach ($records as $record) {
            foreach ([Pac::RECORD_TIME_IN, Pac::RECORD_TIME_OUT] as $offset) {
                $frames = unpack("v", $record, $offset + 2)[1] % 100;
                if ($frames >= Pac::FRAME_RATE && $frames <= self::MAX_FRAMES) {
                    return new FrameRate(Pac::HIGH_FRAME_RATE);
                }
            }
        }

        return new FrameRate(Pac::FRAME_RATE);
    }


    /**
     * @param list<array{alignment: int, runs: list<array{string, string}>}> $lines
     */
    private function cue(string $record, array $lines, PacCodePage $codePage, FrameRate $frameRate, int $index): ?SubtitleCue
    {
        $start = self::seconds(substr($record, Pac::RECORD_TIME_IN, 4), $frameRate);
        $end   = self::seconds(substr($record, Pac::RECORD_TIME_OUT, 4), $frameRate);
        if ($start === null || $end === null) {
            $number = unpack("v", $record, Pac::RECORD_NUMBER)[1];
            $this->fail(new ParsingException("Subtitle number $number has a time code that is not valid."), null, $index, [bin2hex($record)]);

            return null;
        }

        $text = [];
        foreach ($lines as $line) {
            [$decoded] = $codePage->decodeLine($line["runs"]);
            if ($codePage->isRightToLeft()) {
                $decoded = Pac::rightToLeft($decoded, $codePage === PacCodePage::Arabic ? Pac::ARABIC_LATIN : Pac::HEBREW_LATIN);
            }
            $text[] = self::markup($decoded);
        }

        $verticalPosition = ord($record[Pac::RECORD_VERTICAL]);
        $alignment        = $lines === [] ? Pac::ALIGN_CENTER : $lines[0]["alignment"];
        $cue              = new SubtitleCue($start, $end, $text);
        $cue->setAlignment(Pac::alignment($verticalPosition, $alignment));
        $cue->setFormatData(self::FORMAT_DATA_KEY, [
            "verticalPosition" => $verticalPosition,
            "alignment"        => $alignment,
            "text"             => $cue->getText(),
            "bytes"            => bin2hex(substr($record, Pac::RECORD_TEXT)),
        ]);

        return $cue;
    }


    /**
     * Reads a time code of 2 little-endian numbers: hours * 100 + minutes, and seconds * 100 + frames.
     */
    private static function seconds(string $bytes, FrameRate $frameRate): ?float
    {
        [, $hoursMinutes, $secondsFrames] = unpack("v2", $bytes);
        [$minutes, $seconds, $frames] = [$hoursMinutes % 100, intdiv($secondsFrames, 100), $secondsFrames % 100];
        if ($minutes > 59 || $seconds > 59 || $frames >= $frameRate->getFramesPerSecond()) {
            return null;
        }

        return Timecode::toSecondsFromFrames(intdiv($hoursMinutes, 100), $minutes, $seconds, $frames, $frameRate);
    }


    /**
     * Converts a text line to core markup. "<" starts italics and ">" or the end of the line ends them.
     * An italics code takes the place of the space between it and a letter or digit.
     */
    private static function markup(string $line): string
    {
        $line   = preg_replace(['/(?<=[\p{L}\p{N}])' . Pac::ITALIC_ON . '/u', '/' . Pac::ITALIC_OFF . '(?=[\p{L}\p{N}])/u'], [' $0', '$0 '], $line);
        $runs   = [];
        $italic = false;
        foreach (preg_split('/([' . Pac::ITALIC_ON . Pac::ITALIC_OFF . '])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            if ($index % 2 === 1) {
                $italic = $part === Pac::ITALIC_ON;
                continue;
            }
            $runs[] = [$part, ["i" => $italic]];
        }

        return StyleRuns::toMarkup($runs, true);
    }
}
