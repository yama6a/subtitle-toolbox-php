<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Cavena890;
use SubtitleToolbox\Parsers\Cavena890Parser;
use SubtitleToolbox\Parsers\Cavena890Variant;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

/**
 * Writes Cavena 890 files. It keeps the text table that the parser read, and writes Latin for a new file.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Cavena890.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Cavena890.cs), MIT.
 */
final class Cavena890Formatter extends SubtitleFormatter
{
    private const MAX_FRAME = 0xFFFFFF;
    private const MAX_NUMBER = 0xFFFF;


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData = $subtitle->findFormatData(Cavena890Parser::FORMAT_DATA_KEY) ?? [];
        $variant  = Cavena890Variant::from($fileData["variant"] ?? Cavena890Variant::Latin->value);
        $header   = isset($fileData["header"]) ? hex2bin($fileData["header"]) : Cavena890::newHeader();

        $blocks = [];
        $number = 0;
        foreach ($subtitle->getCues() as $cue) {
            $cueBlocks = $this->blocks($cue, $variant, $options->stripTags);
            $stored    = unpack("n", $cueBlocks[0], Cavena890::NUMBER)[1];
            $number    = min(self::MAX_NUMBER, $stored > $number ? $stored : $number + Cavena890::NUMBER_STEP);
            foreach ($cueBlocks as $block) {
                $blocks[] = substr_replace($block, pack("n", $number), Cavena890::NUMBER, 2);
            }
        }

        foreach (Cavena890::COUNTS as $offset) {
            $header = substr_replace($header, pack("v", min(count($blocks), self::MAX_NUMBER)), $offset, 2);
        }
        $output = $header . implode("", $blocks);

        return $blocks !== [] && ($fileData["shortEnd"] ?? true) ? substr($output, 0, -strlen(Cavena890::BLOCK_END)) : $output;
    }


    /**
     * Returns the stored blocks of an unchanged cue with new times and alignment, or new blocks of 2 lines each.
     * A new block has the number 0. A cue of 1 line takes the lower line.
     *
     * @return list<string>
     */
    private function blocks(SubtitleCue $cue, Cavena890Variant $variant, bool $stripTags): array
    {
        $cueData   = $cue->findFormatData(Cavena890Parser::FORMAT_DATA_KEY) ?? [];
        $times     = self::frames($cue->getStart()) . self::frames($cue->getEnd());
        $alignment = match ($cue->getAlignment()) {
            Alignment::BottomLeft, Alignment::MiddleLeft, Alignment::TopLeft    => Cavena890::ALIGN_LEFT,
            Alignment::BottomRight, Alignment::MiddleRight, Alignment::TopRight => Cavena890::ALIGN_RIGHT,
            default                                                             => Cavena890::ALIGN_CENTER,
        };

        if (isset($cueData["text"], $cueData["blocks"]) && $cueData["blocks"] !== [] && !$stripTags && $cueData["text"] === $cue->getText()) {
            $blocks = array_map("hex2bin", $cueData["blocks"]);
            if (Cavena890::alignment(ord($blocks[0][Cavena890::ALIGNMENT])) === Cavena890::alignment($alignment)) {
                $alignment = ord($blocks[0][Cavena890::ALIGNMENT]);
            }

            return array_map(fn (string $block): string => substr_replace($block, $times . chr($alignment), Cavena890::START, 7), $blocks);
        }

        $lines  = Markup::carryOpenTags(array_map(Markup::rubyAsText(...), $cue->getLines()));
        $blocks = [];
        foreach (array_chunk($lines === [] ? [""] : $lines, 2) as $pair) {
            [$upper, $lower] = count($pair) === 2 ? $pair : ["", $pair[0]];
            $blocks[] = "\0\0" . $times . chr($alignment) . str_repeat("\0", 7) . $variant->encode($upper, $stripTags) .
                        str_repeat("\0", 6) . $variant->encode($lower, $stripTags) . Cavena890::BLOCK_END;
        }

        return $blocks;
    }


    /**
     * Returns the frame number at 25 fps as 3 big-endian bytes.
     */
    private static function frames(float $seconds): string
    {
        return substr(pack("N", min(self::MAX_FRAME, (int) round(max(0.0, $seconds) * Cavena890::FRAME_RATE))), 1);
    }
}
