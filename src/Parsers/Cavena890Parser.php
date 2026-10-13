<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads Cavena 890 files. The language codes and the fonts of the header name the text table.
 *
 * Adapted from Subtitle Edit, src/libse/SubtitleFormats/Cavena890.cs
 * (https://github.com/SubtitleEdit/subtitleedit/blob/a0dfbfe65f0517ef85b1e69f1606bca93f68ac3c/src/libse/SubtitleFormats/Cavena890.cs), MIT.
 */
final class Cavena890Parser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Cavena890->value;

    protected const BINARY = true;


    protected function read(string $content): Subtitle
    {
        if (strlen($content) < Cavena890::HEADER_SIZE) {
            throw new ParsingException("A Cavena 890 file starts with a header of " . Cavena890::HEADER_SIZE . " bytes.");
        }

        $header   = substr($content, 0, Cavena890::HEADER_SIZE);
        $variant  = Cavena890Variant::fromHeader($header);
        $body     = substr($content, Cavena890::HEADER_SIZE);
        $shortEnd = strlen($body) % Cavena890::BLOCK_SIZE === Cavena890::BLOCK_SIZE - strlen(Cavena890::BLOCK_END);
        if ($shortEnd) {
            $body .= Cavena890::BLOCK_END;
        } elseif (strlen($body) % Cavena890::BLOCK_SIZE !== 0) {
            $complete = intdiv(strlen($body), Cavena890::BLOCK_SIZE);
            $this->fail(new ParsingException("The blocks of a Cavena 890 file have " . Cavena890::BLOCK_SIZE . " bytes each."),
                null, $complete, [bin2hex(substr($body, $complete * Cavena890::BLOCK_SIZE))]);
            $body = substr($body, 0, $complete * Cavena890::BLOCK_SIZE);
        }

        $subtitle = new Subtitle();
        foreach (self::cueBlocks($body) as $blocks) {
            $subtitle->addCue(self::cue($blocks, $variant));
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["variant" => $variant->value, "header" => bin2hex($header), "shortEnd" => $shortEnd]);

        return $subtitle;
    }


    /**
     * Groups the blocks of one cue: a block with the number of the block before it adds 2 lines to that cue.
     *
     * @return list<list<string>>
     */
    private static function cueBlocks(string $body): array
    {
        $cues = [];
        $last = null;
        foreach (str_split($body, Cavena890::BLOCK_SIZE) as $block) {
            if ($block === "") {
                continue;
            }

            $number = substr($block, Cavena890::NUMBER, 2);
            if ($number === $last) {
                $cues[count($cues) - 1][] = $block;
            } else {
                $cues[] = [$block];
            }
            $last = $number;
        }

        return $cues;
    }


    /**
     * @param list<string> $blocks
     */
    private static function cue(array $blocks, Cavena890Variant $variant): SubtitleCue
    {
        $lines = [];
        foreach ($blocks as $block) {
            foreach ([Cavena890::LINE_1, Cavena890::LINE_2] as $offset) {
                $line = $variant->decode(substr($block, $offset, Cavena890::TEXT_SIZE));
                if (trim($line) !== "") {
                    $lines[] = $line;
                }
            }
        }

        $cue = new SubtitleCue(self::seconds($blocks[0], Cavena890::START), self::seconds($blocks[0], Cavena890::END), $lines);
        $cue->setAlignment(Cavena890::alignment(ord($blocks[0][Cavena890::ALIGNMENT])));
        $cue->setFormatData(self::FORMAT_DATA_KEY, ["text" => $cue->getText(), "blocks" => array_map("bin2hex", $blocks)]);

        return $cue;
    }


    /**
     * Reads a frame number of 3 big-endian bytes at 25 fps.
     */
    private static function seconds(string $block, int $offset): float
    {
        return unpack("N", "\0" . substr($block, $offset, 3))[1] / Cavena890::FRAME_RATE;
    }
}
