<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\QuickTimeText;
use SubtitleToolbox\Parsers\QuickTimeTextParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
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

    private const LIMIT_MILLISECONDS = Timecode::MAX_HOURS * 3600 * 1000;


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData  = $subtitle->findFormatData(QuickTimeTextParser::FORMAT_DATA_KEY);
        $header    = isset($fileData["header"]) ? self::readableHeader($fileData["header"]) : self::DEFAULT_HEADER;
        $paired    = !isset($fileData["header"]) || ($fileData["pairedTimestamps"] ?? false);
        $perSecond = QuickTimeTextParser::timeScale($header) ?? 1000;
        $relative  = strtolower(QuickTimeText::findDescriptor($header, "timeStamps") ?? "") === "relative";
        $style     = QuickTimeText::PLAIN;
        QuickTimeText::readLines($header, $style);

        $output   = implode("", array_map(fn (string $line): string => $line . LineEnding::Lf->value, $header));
        $position = 0;
        $started  = false;
        // QuickTime writes samples from the start of the track, so a gap before the first cue is an empty sample.
        if (!$paired && ($subtitle->getCues()[0] ?? null)?->getStart() > 0) {
            [$timestamp, $position] = $this->timestamp(0.0, $perSecond, $relative, $position);
            $output .= $timestamp;
            $started = true;
        }
        foreach ($subtitle->getCues() as $cue) {
            $start = max(0.0, $cue->getStart());
            $end   = max($start, $cue->getEnd());
            [$timestamp, $startPosition] = $this->timestamp($start, $perSecond, $relative, $position);
            // Without its own timestamp, the sample starts at the end timestamp of the cue before it.
            if ($paired || !$started || $startPosition !== $position) {
                $output  .= $timestamp;
                $position = $startPosition;
            }
            $started = true;
            foreach ($this->textLines($cue, $style) as $line) {
                $output .= $line . LineEnding::Lf->value;
            }
            [$timestamp, $position] = $this->timestamp($end, $perSecond, $relative, $position);
            $output .= $timestamp . ($paired ? LineEnding::Lf->value : "");
        }

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Returns the stored header in a form that the parser reads back: a CR or LF becomes a space, a line that reads as
     * a timestamp drops out, and {QTtext} comes first.
     *
     * @param list<string> $header
     *
     * @return list<string>
     */
    private static function readableHeader(array $header): array
    {
        $lines = [];
        foreach ($header as $line) {
            $line = str_replace(["\r", "\n"], " ", $line);
            if (!preg_match(QuickTimeText::TIMESTAMP_REGEX, trim($line))) {
                $lines[] = $line;
            }
        }

        return preg_match(QuickTimeTextParser::HEADER_REGEX, $lines[0] ?? "") ? $lines : ["{QTtext}", ...$lines];
    }


    /**
     * Returns the timestamp line of $seconds, [hh:mm:ss.ff] where ff counts units of the time scale, and the
     * milliseconds that the parser reaches with it. $position is the time that the parser reaches after the timestamps
     * before it. A relative timestamp counts from $position, so a rounding error or an overlap of cues does not move
     * the later cues.
     *
     * @return array{string, int}
     */
    private function timestamp(float $seconds, int $perSecond, bool $relative, int $position): array
    {
        $from  = $relative ? $position : 0;
        $units = $this->units(Timecode::totalMilliseconds($seconds) - $from, $perSecond);
        // The parser rejects a relative timestamp that takes the sum to 100000 hours. Half a unit of rounding can do that.
        if ($units > 0 && $from + $this->readMilliseconds($units, $perSecond) >= self::LIMIT_MILLISECONDS) {
            $units--;
        }
        $whole     = intdiv($units, $perSecond);
        $timestamp = sprintf(
            "[%02d:%02d:%02d.%0" . max(1, strlen((string) ($perSecond - 1))) . "d]",
            intdiv($whole, 3600),
            intdiv($whole, 60) % 60,
            $whole % 60,
            $units % $perSecond
        );

        return [$timestamp . LineEnding::Lf->value, $from + $this->readMilliseconds($units, $perSecond)];
    }


    /**
     * Returns $milliseconds in units of the time scale, at least 0. unitsBelowLimit() multiplies the milliseconds by
     * twice the time scale. So above about 12.8 million units per second, the time stops where that product still
     * fits in an int. A time scale of PHP_INT_MAX writes every time as 0.
     */
    private function units(int $milliseconds, int $perSecond): int
    {
        $last = intdiv(intdiv(PHP_INT_MAX - 1000, 2), $perSecond);

        return Timecode::unitsBelowLimit(min(max(0, $milliseconds), $last) / 1000, $perSecond);
    }


    /**
     * Returns the milliseconds that the parser reads for a timestamp of $units.
     */
    private function readMilliseconds(int $units, int $perSecond): int
    {
        return Timecode::totalMilliseconds(QuickTimeTextParser::scaledSeconds(intdiv($units, $perSecond), $units % $perSecond, $perSecond));
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
