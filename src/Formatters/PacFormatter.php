<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\AlignmentGrid;
use SubtitleToolbox\Encoding\CodePage;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Pac;
use SubtitleToolbox\Parsers\PacCodePage;
use SubtitleToolbox\Parsers\PacParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Writes PAC files by Screen Electronics. It keeps the code page that the parser read. New text is Latin, or PAC
 * Unicode when Latin cannot hold it.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Pac.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Pac.cs), MIT.
 */
final class PacFormatter extends SubtitleFormatter
{
    private const MAX_NUMBER = 0xFFFF;

    // The highest hours of a time code, as the 2 bytes hold hours * 100 + minutes.
    private const MAX_HOURS = 654;

    // Subtitle Edit writes these 3 bytes before the text of each PAC Unicode record.
    private const UNICODE_PREFIX = "\x80\x80\x80";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData  = $subtitle->findFormatData(PacParser::FORMAT_DATA_KEY) ?? [];
        $codePage  = isset($fileData["codePage"]) ? PacCodePage::from($fileData["codePage"]) : self::newCodePage($subtitle);
        $frameRate = new FrameRate($fileData["frameRate"] ?? Pac::FRAME_RATE);
        $number    = $fileData["firstNumber"] ?? 0;

        $records = "";
        foreach ($subtitle->getCues() as $cue) {
            if ($number > self::MAX_NUMBER) {
                throw new UnwritableContentException("PAC allows subtitle numbers up to " . self::MAX_NUMBER . ".");
            }

            [$verticalPosition, $text] = $this->text($cue, $codePage, $options->stripTags);
            if (strlen($text) >= self::MAX_NUMBER) {
                throw new UnwritableContentException("PAC allows cue text of up to " . (self::MAX_NUMBER - 1) . " bytes.");
            }

            $records .= Pac::RECORD_START . pack("v", $number++) . chr(Pac::RECORD_MARK) . self::timeCode($cue->getStart(), $frameRate) .
                        self::timeCode($cue->getEnd(), $frameRate) . pack("v", strlen($text) + 1) . chr($verticalPosition) . $text;
        }

        return (isset($fileData["header"]) ? hex2bin($fileData["header"]) : Pac::HEADER) . $records .
               (isset($fileData["footer"]) ? hex2bin($fileData["footer"]) : Pac::FOOTER);
    }


    /**
     * Picks Latin for text that it holds, else PAC Unicode.
     */
    private static function newCodePage(Subtitle $subtitle): PacCodePage
    {
        foreach ($subtitle->getCues() as $cue) {
            $text = str_replace(["\u{2026}", "\n"], ["...", " "], Markup::plainText($cue->getText()));
            if (str_contains(PacCodePage::Latin->encode(str_replace(CodePage::REPLACEMENT, "", $text)), CodePage::REPLACEMENT)) {
                return PacCodePage::Unicode;
            }
        }

        return PacCodePage::Latin;
    }


    /**
     * Returns the vertical position and the text bytes of a record: the stored bytes of an unchanged cue, else new bytes.
     *
     * @return array{int, string}
     */
    private function text(SubtitleCue $cue, PacCodePage $codePage, bool $stripTags): array
    {
        $cueData   = $cue->findFormatData(PacParser::FORMAT_DATA_KEY) ?? [];
        $alignment = $cue->getAlignment();
        if (isset($cueData["verticalPosition"], $cueData["alignment"], $cueData["text"], $cueData["bytes"]) && !$stripTags &&
            $cueData["text"] === $cue->getText() && Pac::alignment($cueData["verticalPosition"], $cueData["alignment"]) === $alignment) {
            return [$cueData["verticalPosition"], hex2bin($cueData["bytes"])];
        }

        $lines            = Markup::carryOpenTags(array_map(Markup::rubyAsText(...), $cue->getLines()));
        $verticalPosition = match (AlignmentGrid::row($alignment)) {
            AlignmentGrid::TOP_ROW    => Pac::TOP_ROW,
            AlignmentGrid::MIDDLE_ROW => Pac::MIDDLE_ROW,
            default                   => max(Pac::FIRST_BOTTOM_ROW, Pac::LAST_ROW + 1 - count($lines)),
        };
        // A cue keeps its vertical position while its row and its number of lines stay the same.
        if (isset($cueData["verticalPosition"], $cueData["text"]) && substr_count($cueData["text"], "\n") + 1 === count($lines) &&
            Pac::alignment($cueData["verticalPosition"], Pac::ALIGN_CENTER) === Pac::alignment($verticalPosition, Pac::ALIGN_CENTER)) {
            $verticalPosition = $cueData["verticalPosition"];
        }
        $alignmentByte = match (AlignmentGrid::column($alignment)) {
            AlignmentGrid::LEFT  => Pac::ALIGN_LEFT,
            AlignmentGrid::RIGHT => Pac::ALIGN_RIGHT,
            default              => Pac::ALIGN_CENTER,
        };

        $bytes = $codePage === PacCodePage::Unicode ? self::UNICODE_PREFIX : "";
        foreach ($lines as $line) {
            $bytes .= chr(Pac::LINE_START) . chr($alignmentByte) . chr(Pac::LINE_END) . $codePage->encodeLine(self::lineText($line, $codePage, $stripTags));
        }

        return [$verticalPosition, $bytes];
    }


    /**
     * Converts the core markup of a line to PAC text with the italics codes "<" and ">". A code takes the place of
     * the space between it and a letter or digit. The end of the line ends italics, so a ">" there goes.
     */
    private static function lineText(string $line, PacCodePage $codePage, bool $stripTags): string
    {
        $text   = "";
        $italic = 0;
        foreach (Markup::splitTags($line) as $index => $token) {
            if ($index % 2 === 0) {
                $text .= str_replace([Pac::ITALIC_ON, Pac::ITALIC_OFF], CodePage::REPLACEMENT, Markup::decodeEntities($token));
                continue;
            }

            $tag   = strtolower($token);
            $text .= match (true) {
                $stripTags                                   => "",
                $tag === "<i>"                               => $italic++ === 0 ? Pac::ITALIC_ON : "",
                $tag === "</i>" && $italic > 0               => --$italic === 0 ? Pac::ITALIC_OFF : "",
                default                                      => "",
            };
        }

        $text = preg_replace(['/(?<=[\p{L}\p{N}]) ' . Pac::ITALIC_ON . '/u', '/' . Pac::ITALIC_OFF . ' (?=[\p{L}\p{N}])/u', '/' . Pac::ITALIC_OFF . '\z/'],
                             [Pac::ITALIC_ON, Pac::ITALIC_OFF, ""], $text);
        if ($codePage !== PacCodePage::Unicode) {
            $text = str_replace("\u{2026}", "...", $text);
        }

        return $codePage->isRightToLeft() ? Pac::rightToLeft($text, $codePage === PacCodePage::Arabic ? Pac::ARABIC_LATIN : Pac::HEBREW_LATIN) : $text;
    }


    /**
     * Returns the 2 little-endian numbers hours * 100 + minutes and seconds * 100 + frames.
     */
    private static function timeCode(float $seconds, FrameRate $frameRate): string
    {
        [$hours, $minutes, $wholeSeconds, $frames] = Timecode::frames(max(0.0, $seconds), $frameRate);

        return pack("v2", min($hours, self::MAX_HOURS) * 100 + $minutes, $wholeSeconds * 100 + $frames);
    }
}
