<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Mpl2Parser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class Mpl2Formatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $output = "";
        foreach ($subtitle->getCues() as $cue) {
            $output .= "[" . Timecode::unitsBelowLimit($cue->getStart(), Mpl2Parser::DECISECONDS_PER_SECOND) . "]" .
                       "[" . Timecode::unitsBelowLimit($cue->getEnd(), Mpl2Parser::DECISECONDS_PER_SECOND) . "]" .
                       self::joinWithBars($this->linesWithItalics(array_map(Markup::rubyAsText(...), $cue->getLines()))) .
                       LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function linesWithItalics(array $lines): array
    {
        $italic = false;
        $result = [];
        foreach (explode(LineEnding::Lf->value, implode(LineEnding::Lf->value, $lines)) as $line) {
            $allItalic = true;
            foreach (preg_split('/(<\/?i>)/i', $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if (preg_match('/^<(\/?)i>$/i', $part, $tag)) {
                    $italic = $tag[1] === "";
                } elseif (trim(Markup::stripAllTags($part)) !== "" && !$italic) {
                    $allItalic = false;
                }
            }

            $text = trim(Markup::plainText($line));
            if ($text !== "") {
                $result[] = ($allItalic ? "/" : "") . $text;
            }
        }

        return $result;
    }
}
