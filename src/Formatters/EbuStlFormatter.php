<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\AlignmentGrid;
use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Encoding\Iso6937;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\EbuStl;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Writes EBU STL files as defined in EBU Tech 3264: https://tech.ebu.ch/docs/tech/tech3264.pdf
 */
final class EbuStlFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = EbuStlWriteOptions::class;

    // The values of a new GSI (General Subtitle Information) block.
    // EBU Tech 3264 section 4.2.5 fills unused bytes with spaces.
    private const DEFAULT_GSI = [
        "CPN" => "850", "DSC" => "1", "CCT" => EbuStl::CCT_LATIN, "RN" => "00", "MNC" => "40", "MNR" => "23",
        "TCS" => "1", "TCP" => "00000000", "TND" => "1", "DSN" => "1",
    ];

    private const FIRST_SUBTITLE_NUMBER = 1;
    private const MAX_EXTENSION_BLOCKS  = 0xF0;
    private const MAX_SUBTITLE_NUMBER   = 0xFFFF;
    private const DEFAULT_FRAME_RATE    = 25;
    private const MAX_MNC               = 99;
    private const TCF_WITHOUT_CUES      = "00000000";
    private const UNKNOWN_LANGUAGE      = "00";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData = $subtitle->findFormatData(EbuStlParser::FORMAT_DATA_KEY);
        $gsi      = ($fileData["gsi"] ?? []) + self::DEFAULT_GSI;
        $fps  = $this->formatOptions($options)->frameRate ?? EbuStl::FRAME_RATES[$gsi["DFC"] ?? ""] ?? self::DEFAULT_FRAME_RATE;

        if (!array_key_exists($gsi["CCT"], EbuStl::CHARACTER_CODE_TABLES)) {
            throw new InvalidArgumentException("The character code table \"{$gsi["CCT"]}\" is not 00, 01, 02, 03 or 04.");
        }

        $gsi["DFC"] = array_search((int) $fps, EbuStl::FRAME_RATES, true);
        $frameRate  = new FrameRate($fps);
        $context    = new EbuStlContext(
            frameRate: $frameRate,
            offset: empty($fileData["startOfProgrammeSubtracted"]) ? 0.0 : EbuStl::timeCodeToSeconds($gsi["TCP"], $frameRate),
            characterCodeTable: $gsi["CCT"],
            maxRow: EbuStl::maxRow($gsi),
            teletext: in_array($gsi["DSC"], EbuStl::TELETEXT_DISPLAY_CODES, true),
            stripTags: $options->stripTags,
        );

        $sets = $this->subtitleSets($context, $subtitle, $fileData["comments"] ?? []);

        $number = $fileData["firstSubtitleNumber"] ?? self::FIRST_SUBTITLE_NUMBER;
        $blocks = "";
        foreach ($sets as $set) {
            if ($number > self::MAX_SUBTITLE_NUMBER) {
                throw new UnwritableContentException("EBU STL allows subtitle numbers up to " . self::MAX_SUBTITLE_NUMBER . ".");
            }

            foreach ($set["blocks"] as $block) {
                $blocks .= substr_replace($block, pack("v", $number), EbuStl::TTI_SN, EbuStl::SUBTITLE_NUMBER_SIZE);
            }
            $number++;
        }

        return $this->formatGsi($subtitle, $gsi, $fileData, $sets) . $blocks;
    }


    /**
     * @return list<array{blocks: list<string>, comment: bool}>
     */
    private function subtitleSets(EbuStlContext $context, Subtitle $subtitle, array $storedComments): array
    {
        $cues     = array_values($subtitle->getCues());
        $comments = $this->commentsByCue($subtitle);
        $sets     = [];
        foreach ([...array_keys($cues), count($cues)] as $index) {
            foreach ($comments[$index] ?? [] as $comment) {
                $timeCode = $this->timeCode($context, isset($cues[$index]) ? $cues[$index]->getStart() : ($cues === [] ? 0 : end($cues)->getEnd()));
                $sets[]   = ["blocks" => $this->commentBlocks($context, $comment->text, $storedComments, $timeCode), "comment" => true];
            }

            if (isset($cues[$index])) {
                $sets[] = ["blocks" => $this->cueBlocks($context, $cues[$index]), "comment" => false];
            }
        }

        return $sets;
    }


    /**
     * Writes the stored blocks of a comment with the same text, or new blocks with the comment flag set.
     *
     * @return list<string>
     */
    private function commentBlocks(EbuStlContext $context, string $text, array &$storedComments, string $timeCode): array
    {
        foreach ($storedComments as $index => $stored) {
            if ($stored["text"] === $text) {
                unset($storedComments[$index]);

                return array_map("hex2bin", $stored["blocks"]);
            }
        }

        $bytes    = implode(chr(EbuStl::NEW_LINE), array_map(fn (string $line): string => $this->encodeCharacters($context, $line), explode(LineEnding::Lf->value, $text)));
        $position = $this->position($context, Alignment::BottomCenter, count(explode(LineEnding::Lf->value, $text)));

        return $this->textBlocks($context, $bytes, $this->header(0, 0, $timeCode, $timeCode, $position, isComment: true));
    }


    /**
     * Writes the stored blocks of an unchanged cue with new times and position, or new blocks from the core markup.
     *
     * @return list<string>
     */
    private function cueBlocks(EbuStlContext $context, SubtitleCue $cue): array
    {
        $cueData     = $cue->findFormatData(EbuStlParser::FORMAT_DATA_KEY);
        $alignment   = $cue->getAlignment();
        $timeCodeIn  = $this->timeCode($context, $cue->getStart());
        $timeCodeOut = $this->timeCode($context, $cue->getEnd());

        $position = [$cueData["verticalPosition"] ?? -1, $cueData["justificationCode"] ?? -1];
        if (!isset($cueData["verticalPosition"], $cueData["justificationCode"]) ||
            EbuStl::alignment($position[0], $position[1], $context->maxRow) !== $alignment) {
            $position = $this->position($context, $alignment, max(1, count($cue->getLines())));
        }

        $header = $this->header($cueData["subtitleGroupNumber"] ?? 0, $cueData["cumulativeStatus"] ?? 0, $timeCodeIn, $timeCodeOut, $position);
        $blocks = array_map("hex2bin", $cueData["blocks"] ?? []);
        if ($blocks !== [] && !$context->stripTags && ($cueData["text"] ?? null) === $cue->getText()) {
            return $this->patchHeaders($blocks, $header);
        }

        $userData = array_filter($blocks, fn (string $block): bool => ord($block[EbuStl::TTI_EBN]) === EbuStl::USER_DATA_BLOCK);

        return [...$this->patchHeaders(array_values($userData), $header), ...$this->textBlocks($context, $this->encodeText($context, $cue), $header)];
    }


    /**
     * Writes bytes 0 and 4 to 15 of $header into each block where they differ from the first block.
     * So unchanged values stay as they are in every block.
     *
     * @param list<string> $blocks
     *
     * @return list<string>
     */
    private function patchHeaders(array $blocks, string $header): array
    {
        if ($blocks === []) {
            return [];
        }

        $first = $blocks[0];
        $fields = [
            [EbuStl::TTI_SGN, 1], [EbuStl::TTI_CS, 1], [EbuStl::TTI_TCI, EbuStl::TIME_CODE_SIZE],
            [EbuStl::TTI_TCO, EbuStl::TIME_CODE_SIZE], [EbuStl::TTI_VP, 1], [EbuStl::TTI_JC, 1],
        ];
        foreach ($fields as [$offset, $length]) {
            $old = substr($first, $offset, $length);
            $new = substr($header, $offset, $length);
            if ($old === $new) {
                continue;
            }

            foreach ($blocks as $index => $block) {
                if (substr($block, $offset, $length) === $old) {
                    $blocks[$index] = substr_replace($block, $new, $offset, $length);
                }
            }
        }

        return $blocks;
    }


    /**
     * Splits text bytes into text fields. Each field holds 112 bytes, and the last one ends with at least one 8Fh code,
     * EBU Tech 3264 section 4.3.2. A diacritical mark stays in the same field as its letter.
     *
     * @return list<string>
     */
    private function textBlocks(EbuStlContext $context, string $bytes, string $header): array
    {
        $fields = [];
        while (strlen($bytes) >= EbuStl::TEXT_FIELD_SIZE) {
            $length = EbuStl::TEXT_FIELD_SIZE;
            if ($context->characterCodeTable === EbuStl::CCT_LATIN && isset(Iso6937::DIACRITICS[ord($bytes[$length - 1])])) {
                $length--;
            }

            $fields[] = str_pad(substr($bytes, 0, $length), EbuStl::TEXT_FIELD_SIZE, chr(EbuStl::UNUSED_SPACE));
            $bytes    = substr($bytes, $length);
        }
        $fields[] = str_pad($bytes, EbuStl::TEXT_FIELD_SIZE, chr(EbuStl::UNUSED_SPACE));

        if (count($fields) > self::MAX_EXTENSION_BLOCKS + 1) {
            throw new UnwritableContentException("The cue text needs more than " . (self::MAX_EXTENSION_BLOCKS + 1) . " TTI blocks.");
        }

        $blocks = [];
        foreach ($fields as $index => $field) {
            $extensionBlockNumber = $index === count($fields) - 1 ? EbuStl::LAST_BLOCK : $index;
            $blocks[]             = substr_replace($header, chr($extensionBlockNumber), EbuStl::TTI_EBN, 1) . $field;
        }

        return $blocks;
    }


    /**
     * Returns the 16 header bytes of a TTI (Text and Timing Information) block, EBU Tech 3264 table 2. The formatter writes the subtitle number later.
     *
     * @param array{int, int} $position the vertical position and the justification code
     */
    private function header(int $group, int $cumulativeStatus, string $timeCodeIn, string $timeCodeOut, array $position, bool $isComment = false): string
    {
        [$verticalPosition, $justificationCode] = $position;

        return chr($group) . "\0\0" . chr(EbuStl::LAST_BLOCK) . chr($cumulativeStatus) . $timeCodeIn . $timeCodeOut .
               chr($verticalPosition) . chr($justificationCode) . chr($isComment ? EbuStl::COMMENT_FLAG : 0);
    }


    /**
     * Returns the vertical position and the justification code for an alignment.
     * The first row of the subtitle goes at the top, in the middle or at the bottom of the rows.
     *
     * @return array{int, int}
     */
    private function position(EbuStlContext $context, Alignment $alignment, int $lineCount): array
    {
        $firstRow = $context->teletext ? 1 : 0;
        $lastRow  = max($firstRow, $context->maxRow - $lineCount + 1);

        $verticalPosition = match (AlignmentGrid::row($alignment)) {
            AlignmentGrid::TOP_ROW    => $firstRow,
            AlignmentGrid::MIDDLE_ROW => intdiv($firstRow + $lastRow, 2),
            default                   => $lastRow,
        };
        $justificationCode = match (AlignmentGrid::column($alignment)) {
            AlignmentGrid::LEFT  => EbuStl::JUSTIFY_LEFT,
            AlignmentGrid::RIGHT => EbuStl::JUSTIFY_RIGHT,
            default              => EbuStl::JUSTIFY_CENTER,
        };

        return [$verticalPosition, $justificationCode];
    }


    /**
     * Returns the 4 time code bytes hours, minutes, seconds and frames, EBU Tech 3264 section 4.3.2.
     */
    private function timeCode(EbuStlContext $context, float $seconds): string
    {
        [$hours, $minutes, $wholeSeconds, $frames] = Timecode::frames(max(0.0, $seconds + $context->offset), $context->frameRate);

        return chr(min($hours, 0xFF)) . chr($minutes) . chr($wholeSeconds) . chr($frames);
    }


    /**
     * Converts the core markup of the cue lines to text field codes, EBU Tech 3264 appendix 2.
     * Italics and underline take 80h to 83h. The eight colors take the teletext alpha color codes.
     */
    private function encodeText(EbuStlContext $context, SubtitleCue $cue): string
    {
        $rows = [];
        foreach (Markup::carryOpenTags(array_map(Markup::rubyAsText(...), $cue->getLines())) as $line) {
            $bytes  = "";
            $italic = 0;
            $under  = 0;
            $colors = [];
            foreach (Markup::splitTags(MarkupColors::webVttColorsToFont($line)) as $index => $token) {
                if ($index % 2 === 0) {
                    $bytes .= $this->encodeCharacters($context, Markup::decodeEntities($token));
                    continue;
                }

                if ($context->stripTags) {
                    continue;
                }

                $tag = strtolower($token);
                if (preg_match('/^<font\b[^>]*>$/', $tag)) {
                    $color    = array_search(MarkupColors::fontColorHex($tag), EbuStl::COLORS, true);
                    $colors[] = $color === false ? end($colors) : $color;
                    $bytes    .= $color === false ? "" : chr($color);
                    continue;
                }

                $bytes .= match ($tag) {
                    "<i>"     => $italic++ === 0 ? chr(EbuStl::ITALICS_ON) : "",
                    "</i>"    => $italic > 0 && --$italic === 0 ? chr(EbuStl::ITALICS_OFF) : "",
                    "<u>"     => $under++ === 0 ? chr(EbuStl::UNDERLINE_ON) : "",
                    "</u>"    => $under > 0 && --$under === 0 ? chr(EbuStl::UNDERLINE_OFF) : "",
                    "</font>" => $this->closeColor($colors),
                    default   => "",
                };
            }

            // A teletext color code shows as a space, so it replaces one space next to it. At the row end it has no effect.
            $bytes  = rtrim(preg_replace('/ ([\x00-\x07])|([\x00-\x07]) /', '$1$2', $bytes), "\x00..\x07");
            $rows[] = $bytes . ($italic > 0 ? chr(EbuStl::ITALICS_OFF) : "") . ($under > 0 ? chr(EbuStl::UNDERLINE_OFF) : "");
        }

        return implode(chr(EbuStl::NEW_LINE), $rows);
    }


    /**
     * Returns the color code that applies after a </font> tag, or "" when the color stays the same.
     */
    private function closeColor(array &$colors): string
    {
        $closed  = array_pop($colors);
        $current = end($colors);
        $current = $current === false ? EbuStl::WHITE : $current;

        return $closed === null || $closed === false || $closed === $current ? "" : chr($current);
    }


    private function encodeCharacters(EbuStlContext $context, string $text): string
    {
        $table = EbuStl::CHARACTER_CODE_TABLES[$context->characterCodeTable];

        return $table === null ? Iso6937::encode($text) : CodePage::encode($text, $table);
    }


    /**
     * Writes the GSI block. The title and language metadata and the counts of the written blocks can change.
     * Such a value replaces the stored value when it differs from what the parser read.
     *
     * @param list<array{blocks: list<string>, comment: bool}> $sets
     */
    private function formatGsi(Subtitle $subtitle, array $gsi, array $fileData, array $sets): string
    {
        $subtitles = array_values(array_filter($sets, fn (array $set): bool => !$set["comment"]));
        $counts    = [
            "TNB" => array_sum(array_map(fn (array $set): int => count($set["blocks"]), $sets)),
            "TNS" => count($subtitles),
            "TNG" => count(array_unique(array_map(fn (array $set): int => ord($set["blocks"][0][EbuStl::TTI_SGN]), $subtitles))),
            "TCF" => $subtitles === [] ? self::TCF_WITHOUT_CUES : EbuStl::timeCodeDigits(substr($subtitles[0]["blocks"][0], EbuStl::TTI_TCI, EbuStl::TIME_CODE_SIZE)),
        ];
        $formats = ["TNB" => "%05d", "TNS" => "%05d", "TNG" => "%03d", "TCF" => "%s"];
        foreach ($counts as $field => $count) {
            if (!isset($gsi[$field]) || $count !== ($fileData["counts"][$field] ?? null)) {
                $gsi[$field] = sprintf($formats[$field], $count);
            }
        }

        $longestRow = $this->longestRow($subtitles, $gsi["CCT"]);
        if ($longestRow > (int) $gsi["MNC"]) {
            $gsi["MNC"] = (string) min($longestRow, self::MAX_MNC);
        }

        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE) ?? "";
        if ($title !== rtrim($gsi["OPT"] ?? "", " \0")) {
            $gsi["OPT"] = $title;
        }

        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        if (!isset($gsi["LC"]) || $language !== (EbuStl::LANGUAGES[strtoupper($gsi["LC"])] ?? null)) {
            $gsi["LC"] = $this->languageCode($language);
        }

        $gsi["CD"] ??= gmdate("ymd");
        $gsi["RD"] ??= $gsi["CD"];

        $codePage = EbuStl::GSI_CODE_PAGES[$gsi["CPN"]] ?? CodePage::CP_850;
        $block    = "";
        foreach (EbuStl::GSI_FIELDS as $field => [, $length]) {
            $block .= substr(str_pad(CodePage::encode($gsi[$field] ?? "", $codePage), $length), 0, $length);
        }

        return $block;
    }


    /**
     * Counts the displayable characters of the longest row, as the MNC field does. Control codes do not count.
     * A diacritic of table 00 combines with the next letter, so it does not count either.
     *
     * @param list<array{blocks: list<string>, comment: bool}> $subtitles
     */
    private function longestRow(array $subtitles, string $characterCodeTable): int
    {
        $character = $characterCodeTable === EbuStl::CCT_LATIN ? '/[\x20-\x7F\xA0-\xC0\xD0-\xFF]/' : '/[\x20-\x7F\xA0-\xFF]/';
        $longest   = 0;
        foreach ($subtitles as $set) {
            foreach (explode(chr(EbuStl::NEW_LINE), EbuStl::textBytes($set["blocks"])) as $row) {
                $longest = max($longest, preg_match_all($character, $row));
            }
        }

        return $longest;
    }


    private function languageCode(?string $language): string
    {
        if ($language === null) {
            return self::UNKNOWN_LANGUAGE;
        }

        $languages = array_map("strtolower", EbuStl::LANGUAGES);
        $code      = array_search(strtolower($language), $languages, true)
                     ?: array_search(strtolower(explode("-", $language)[0]), $languages, true);

        return $code === false ? self::UNKNOWN_LANGUAGE : (string) $code;
    }
}
