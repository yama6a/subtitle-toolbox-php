<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\QuickTimeText;
use SubtitleToolbox\Parsers\QuickTimeTextParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class QuickTimeTextFormatter extends SubtitleFormatter
{
    // The header of Subtitle Edit, which reads only 2 fraction digits.
    private const DEFAULT_HEADER = [
        "{QTtext} {font:Tahoma}",
        "{plain} {size:20}",
        "{timeScale:30}",
        "{width:160} {height:32}",
        "{timestamps:absolute} {language:0}",
    ];


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData  = $subtitle->findFormatData(QuickTimeTextParser::FORMAT_DATA_KEY);
        $header    = $fileData["header"] ?? self::DEFAULT_HEADER;
        $paired    = !isset($fileData["header"]) || ($fileData["pairedTimestamps"] ?? false);
        $timeScale = QuickTimeTextParser::timeScale($header);
        $relative  = strtolower(QuickTimeText::findDescriptor($header, "timeStamps") ?? "") === "relative";
        $style     = QuickTimeText::PLAIN;
        QuickTimeText::readLines($header, $style);

        $output   = implode("", array_map(fn (string $line): string => $line . LineEnding::Lf->value, $header));
        // QuickTime writes samples from the start of the track, so a gap before the first cue is an empty sample.
        $previous = $paired || ($subtitle->getCues()[0] ?? null)?->getStart() <= 0 ? null : 0.0;
        $output  .= $previous === null ? "" : $this->timestamp(0.0, $timeScale) . LineEnding::Lf->value;
        foreach ($subtitle->getCues() as $cue) {
            $start = max(0.0, $cue->getStart());
            $end   = max($start, $cue->getEnd());
            if ($paired || $previous === null || $this->units($start, $timeScale) !== $this->units($previous, $timeScale)) {
                $output  .= $this->timestamp($start - ($relative ? $previous ?? 0.0 : 0.0), $timeScale) . LineEnding::Lf->value;
                $previous = $start;
            }
            foreach ($this->textLines($cue, $style) as $line) {
                $output .= $line . LineEnding::Lf->value;
            }
            $output  .= $this->timestamp($end - ($relative ? $previous : 0.0), $timeScale) . LineEnding::Lf->value;
            $output  .= $paired ? LineEnding::Lf->value : "";
            $previous = $end;
        }

        return $this->applyOutputOptions($output, $options);
    }


    private function units(float $seconds, ?int $timeScale): int
    {
        return (int) round($seconds * ($timeScale ?? 1000));
    }


    /**
     * Writes [hh:mm:ss.ff], where ff counts units of the time scale. Without a time scale, it writes milliseconds.
     */
    private function timestamp(float $seconds, ?int $timeScale): string
    {
        $perSecond = $timeScale ?? 1000;
        $units     = $this->units(max(0.0, $seconds), $timeScale);
        $whole     = intdiv($units, $perSecond);

        return sprintf(
            "[%02d:%02d:%02d.%0" . max(1, strlen((string) ($perSecond - 1))) . "d]",
            intdiv($whole, 3600),
            intdiv($whole, 60) % 60,
            $whole % 60,
            $units % $perSecond
        );
    }


    /**
     * Returns the stored text of an unchanged cue. Otherwise it writes the core markup as {bold}, {italic},
     * {underline} and {plain}, from the style that the earlier samples left. It updates $style.
     *
     * @param array{b: bool, i: bool, u: bool} $style
     *
     * @return list<string>
     */
    private function textLines(SubtitleCue $cue, array &$style): array
    {
        $source = $cue->findFormatData(QuickTimeTextParser::FORMAT_DATA_KEY)["source"] ?? null;
        if (is_array($source)) {
            $sourceStyle = $style;
            if (QuickTimeText::readLines($source, $sourceStyle) === $cue->getLines()) {
                $style = $sourceStyle;

                return $source;
            }
        }

        $lines = [];
        foreach (Markup::carryOpenTags(array_map(Markup::rubyAsText(...), $cue->getLines())) as $line) {
            $open = QuickTimeText::PLAIN;
            $text = "";
            foreach (Markup::splitTags($line) as $index => $token) {
                if ($index % 2 === 1) {
                    if (preg_match('/^<(\/?)([biu])>$/i', $token, $tag)) {
                        $open[strtolower($tag[2])] = $tag[1] === "";
                    }
                    continue;
                }
                $token = Markup::decodeEntities($token);
                if (trim($token) !== "") {
                    $text .= $this->descriptors($style, $open);
                    $style = $open;
                }
                $text .= $token;
            }
            $text = trim($text);
            if ($text !== "") {
                $lines[] = $text;
            }
        }

        return $lines;
    }


    /**
     * Returns the descriptors that change $from to $to. Only {plain} turns a style off.
     *
     * @param array{b: bool, i: bool, u: bool} $from
     * @param array{b: bool, i: bool, u: bool} $to
     */
    private function descriptors(array $from, array $to): string
    {
        $turnsOff = array_filter(array_keys($from), fn (string $flag): bool => $from[$flag] && !$to[$flag]) !== [];
        $base     = $turnsOff ? QuickTimeText::PLAIN : $from;
        $output   = $turnsOff ? "{plain}" : "";
        foreach (QuickTimeText::FLAGS as $flag => $descriptor) {
            $output .= $to[$flag] && !$base[$flag] ? $descriptor : "";
        }

        return $output;
    }
}
