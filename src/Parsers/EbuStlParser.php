<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Encoding\Iso6937;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\EbuStlReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Reads EBU STL files as defined in EBU Tech 3264: https://tech.ebu.ch/docs/tech/tech3264.pdf
 * A file holds one GSI (General Subtitle Information) block, then TTI (Text and Timing Information) blocks.
 */
final class EbuStlParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = EbuStlReadOptions::class;
    public const FORMAT_DATA_KEY = Format::EbuStl->value;

    protected const BINARY = true;


    protected function read(string $content): Subtitle
    {
        $content   = $this->cutIncompleteBlock($content);
        $gsi       = self::checkedGsi($content);
        $frameRate = new FrameRate(EbuStl::FRAME_RATES[$gsi["DFC"]]);
        $offset    = $this->formatOptions()->subtractStartOfProgramme ? EbuStl::timeCodeToSeconds($gsi["TCP"], $frameRate) : 0.0;
        $sets      = self::readSubtitleSets(substr($content, EbuStl::GSI_BLOCK_SIZE));

        $subtitle = new Subtitle();
        $title    = rtrim($gsi["OPT"], " \0");
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, $title === "" ? null : $title);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, EbuStl::LANGUAGES[strtoupper($gsi["LC"])] ?? null);

        $read = $this->readSets($sets, $gsi, $frameRate, $offset);
        CommentAnchors::addParsed($subtitle, $read["cues"], $read["cueComments"]);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "gsi"                        => $gsi,
            "startOfProgrammeSubtracted" => $this->formatOptions()->subtractStartOfProgramme,
            "firstSubtitleNumber"        => $sets === [] ? null : unpack("v", $sets[0][0], EbuStl::TTI_SN)[1],
            "comments"                   => $read["comments"],
            "counts"                     => [
                "TNB" => intdiv(strlen($content) - EbuStl::GSI_BLOCK_SIZE, EbuStl::TTI_BLOCK_SIZE),
                "TNS" => count($subtitle->getCues()),
                "TNG" => count($read["groups"]),
                "TCF" => $read["firstTimeIn"] ?? "00000000",
            ],
        ]);

        return $subtitle;
    }


    /**
     * Fails on a file shorter than the GSI block. Cuts an incomplete last TTI block, or fails on it when not lenient.
     */
    private function cutIncompleteBlock(string $content): string
    {
        if (strlen($content) < EbuStl::GSI_BLOCK_SIZE) {
            throw new ParsingException("An EBU STL file starts with a GSI block of " . EbuStl::GSI_BLOCK_SIZE . " bytes.");
        }

        try {
            self::checkTtiBlockSize($content);
        } catch (ParsingException $exception) {
            $complete  = intdiv(strlen($content) - EbuStl::GSI_BLOCK_SIZE, EbuStl::TTI_BLOCK_SIZE);
            $cutLength = EbuStl::GSI_BLOCK_SIZE + $complete * EbuStl::TTI_BLOCK_SIZE;
            $this->fail($exception, null, $complete, [bin2hex(substr($content, $cutLength))]);
            $content   = substr($content, 0, $cutLength);
        }

        return $content;
    }


    /**
     * @return array<string, string>
     */
    private static function checkedGsi(string $content): array
    {
        $gsi = EbuStl::readGsi(substr($content, 0, EbuStl::GSI_BLOCK_SIZE));
        if (!isset(EbuStl::FRAME_RATES[$gsi["DFC"]])) {
            throw new ParsingException("The disk format code \"{$gsi["DFC"]}\" is not STL25.01 or STL30.01.");
        }

        if (!array_key_exists($gsi["CCT"], EbuStl::CHARACTER_CODE_TABLES)) {
            throw new ParsingException("The character code table \"{$gsi["CCT"]}\" is not 00, 01, 02, 03 or 04.");
        }

        return $gsi;
    }


    /**
     * Reads the cues and the comments of the subtitle sets.
     *
     * @param list<list<string>>    $sets
     * @param array<string, string> $gsi
     *
     * @return array{cues: list<SubtitleCue>, cueComments: list<array{string, int}>, comments: list<array{text: string, blocks: list<string>}>, groups: array<int, true>, firstTimeIn: ?string}
     */
    private function readSets(array $sets, array $gsi, FrameRate $frameRate, float $offset): array
    {
        $read       = ["cues" => [], "cueComments" => [], "comments" => [], "groups" => [], "firstTimeIn" => null];
        $maxRow     = EbuStl::maxRow($gsi);
        $blockIndex = 0;
        foreach ($sets as $blocks) {
            $header      = $blocks[0];
            $blockIndex += count($blocks);
            $lines       = self::decodeLines(self::textBytes($blocks), $gsi["CCT"]);
            $hexes       = array_map("bin2hex", $blocks);
            if (ord($header[EbuStl::TTI_CF]) === EbuStl::COMMENT_FLAG) {
                $lines = array_filter(array_map("trim", $lines), fn (string $line): bool => $line !== "");
                $text  = Markup::plainText(implode("\n", $lines));
                $read["cueComments"][] = [$text, count($read["cues"])];
                $read["comments"][] = ["text" => $text, "blocks" => $hexes];
                continue;
            }

            if ($this->options->lenient && !self::hasValidTimeCodes($header, $frameRate)) {
                $this->warnInvalidTimeCodes($header, $blockIndex - count($blocks), $hexes);
                continue;
            }

            $read["groups"][ord($header[EbuStl::TTI_SGN])] = true;
            $read["firstTimeIn"] ??= EbuStl::timeCodeDigits(substr($header, EbuStl::TTI_TCI, EbuStl::TIME_CODE_SIZE));
            $read["cues"][] = self::cue($header, $lines, $hexes, $frameRate, $offset, $maxRow);
        }

        return $read;
    }


    /**
     * @param list<string> $hexes
     */
    private function warnInvalidTimeCodes(string $header, int $blockIndex, array $hexes): void
    {
        $this->warn(
            "Subtitle number " . unpack("v", $header, EbuStl::TTI_SN)[1] . " has a time code that is not valid: " .
            EbuStl::timeCodeDigits(substr($header, EbuStl::TTI_TCI, EbuStl::TIME_CODE_SIZE)) . " to " .
            EbuStl::timeCodeDigits(substr($header, EbuStl::TTI_TCO, EbuStl::TIME_CODE_SIZE)) . ".",
            null,
            $blockIndex,
            $hexes,
            ParseWarningAction::Skipped
        );
    }


    /**
     * @param list<string> $lines
     * @param list<string> $hexes
     */
    private static function cue(string $header, array $lines, array $hexes, FrameRate $frameRate, float $offset, int $maxRow): SubtitleCue
    {
        $start = max(0.0, self::timeCodeBytesToSeconds(substr($header, EbuStl::TTI_TCI, EbuStl::TIME_CODE_SIZE), $frameRate) - $offset);
        $end   = max(0.0, self::timeCodeBytesToSeconds(substr($header, EbuStl::TTI_TCO, EbuStl::TIME_CODE_SIZE), $frameRate) - $offset);
        $cue   = new SubtitleCue($start, $end, $lines);
        $cue->setAlignment(EbuStl::alignment(ord($header[EbuStl::TTI_VP]), ord($header[EbuStl::TTI_JC]), $maxRow));
        $cue->setFormatData(self::FORMAT_DATA_KEY, [
            "subtitleGroupNumber" => ord($header[EbuStl::TTI_SGN]),
            "cumulativeStatus"    => ord($header[EbuStl::TTI_CS]),
            "verticalPosition"    => ord($header[EbuStl::TTI_VP]),
            "justificationCode"   => ord($header[EbuStl::TTI_JC]),
            "text"                => $cue->getText(),
            "blocks"              => $hexes,
        ]);

        return $cue;
    }


    private static function checkTtiBlockSize(string $content): void
    {
        if ((strlen($content) - EbuStl::GSI_BLOCK_SIZE) % EbuStl::TTI_BLOCK_SIZE !== 0) {
            throw new ParsingException("The TTI blocks of an EBU STL file must have " . EbuStl::TTI_BLOCK_SIZE . " bytes each.");
        }
    }


    /**
     * EBU Tech 3264 limits the TCI and TCO fields to hours 0 to 23 and minutes and seconds 0 to 59.
     * The frames stay below the frame rate.
     */
    private static function hasValidTimeCodes(string $header, FrameRate $frameRate): bool
    {
        foreach ([EbuStl::TTI_TCI, EbuStl::TTI_TCO] as $offset) {
            [$hours, $minutes, $seconds, $frames] = array_map("ord", str_split(substr($header, $offset, EbuStl::TIME_CODE_SIZE)));
            if ($hours > 23 || $minutes > 59 || $seconds > 59 || $frames >= $frameRate->getFramesPerSecond()) {
                return false;
            }
        }

        return true;
    }


    private static function timeCodeBytesToSeconds(string $bytes, FrameRate $frameRate): float
    {
        [$hours, $minutes, $seconds, $frames] = array_map("ord", str_split($bytes));

        return Timecode::toSecondsFromFrames($hours, $minutes, $seconds, $frames, $frameRate);
    }


    /**
     * Groups the TTI blocks into the sets of one subtitle, EBU Tech 3264 section 4.3.2.
     * A set holds consecutive blocks with the same subtitle number, up to the block with extension block number FFh.
     *
     * @return list<list<string>>
     */
    private static function readSubtitleSets(string $ttiBlocks): array
    {
        $sets    = [];
        $current = [];
        foreach (str_split($ttiBlocks, EbuStl::TTI_BLOCK_SIZE) as $block) {
            if ($block === "") {
                continue;
            }

            if ($current !== [] && substr($current[0], EbuStl::TTI_SN, EbuStl::SUBTITLE_NUMBER_SIZE) !== substr($block, EbuStl::TTI_SN, EbuStl::SUBTITLE_NUMBER_SIZE)) {
                $sets[]  = $current;
                $current = [];
            }

            $current[] = $block;
            if (ord($block[EbuStl::TTI_EBN]) === EbuStl::LAST_BLOCK) {
                $sets[]  = $current;
                $current = [];
            }
        }

        if ($current !== []) {
            $sets[] = $current;
        }

        return $sets;
    }


    /**
     * Joins the text fields of the blocks that carry subtitle text, EBN 00h to EFh and FFh, without the unused space code.
     */
    private static function textBytes(array $blocks): string
    {
        $text = "";
        foreach ($blocks as $block) {
            $extensionBlockNumber = ord($block[EbuStl::TTI_EBN]);
            if ($extensionBlockNumber <= 0xEF || $extensionBlockNumber === EbuStl::LAST_BLOCK) {
                $text .= substr($block, EbuStl::TTI_TF, EbuStl::TEXT_FIELD_SIZE);
            }
        }

        return str_replace(chr(EbuStl::UNUSED_SPACE), "", $text);
    }


    /**
     * Converts a text field to cue lines with core markup. EBU Tech 3264 section 5 lists the control codes.
     * A teletext control code takes the place of a space. Italics and underline last until their off code.
     * The color returns to white at each new row.
     *
     * @return list<string>
     */
    private static function decodeLines(string $bytes, string $characterCodeTable): array
    {
        $lines = [];
        $runs  = [];
        $style = ["color" => EbuStl::WHITE, "i" => false, "u" => false];
        $text  = "";
        foreach (str_split($bytes) as $byte) {
            $code = ord($byte);
            if (($code >= 0x20 && $code < 0x80) || $code >= 0xA0) {
                $text .= $byte;
                continue;
            }

            $runs[] = [self::decodeCharacters($text, $characterCodeTable), self::runStyle($style)];
            $text   = "";
            if ($code < 0x20) {
                $runs[] = [" ", []];
            }

            match (true) {
                $code < 0x08                    => $style["color"] = $code,
                $code === EbuStl::ITALICS_ON    => $style["i"] = true,
                $code === EbuStl::ITALICS_OFF   => $style["i"] = false,
                $code === EbuStl::UNDERLINE_ON  => $style["u"] = true,
                $code === EbuStl::UNDERLINE_OFF => $style["u"] = false,
                default                         => null,
            };

            if ($code === EbuStl::NEW_LINE) {
                $lines[]        = StyleRuns::toMarkup($runs, true);
                $runs           = [];
                $style["color"] = EbuStl::WHITE;
            }
        }

        $runs[]  = [self::decodeCharacters($text, $characterCodeTable), self::runStyle($style)];
        $lines[] = StyleRuns::toMarkup($runs, true);

        return $lines;
    }


    private static function decodeCharacters(string $bytes, string $characterCodeTable): string
    {
        $table = EbuStl::CHARACTER_CODE_TABLES[$characterCodeTable];

        return $table === null ? Iso6937::decode($bytes) : CodePage::decode($bytes, $table);
    }


    /**
     * @param array{color: int, i: bool, u: bool} $style
     * @return array{color: ?string, i: bool, u: bool}
     */
    private static function runStyle(array $style): array
    {
        return ["color" => $style["color"] === EbuStl::WHITE ? null : EbuStl::COLORS[$style["color"]]] + $style;
    }
}
