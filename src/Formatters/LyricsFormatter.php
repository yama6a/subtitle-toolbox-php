<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Comment;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class LyricsFormatter extends SubtitleFormatter
{
    protected const DEFAULT_BOM = true;

    /** The last centisecond the parser reads in the mm:ss.cc form: 999:59.99. */
    private const LAST_UNIT = 999 * 6000 + 5999;


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $output   = $this->formatIdTags($subtitle);
        $cues     = array_values($subtitle->getCues());
        $comments = $this->commentsByCue($subtitle);
        $spaced   = $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY)["spacedWordTimes"] ?? false;

        foreach ($cues as $cueIndex => $cue) {
            $output .= $this->formatComments($comments[$cueIndex] ?? []);
            $output .= $this->formatCue($cue, $spaced);
            $output .= LineEnding::Lf->value;

            if ($cue->findFormatData(LyricsParser::FORMAT_DATA_KEY)["endLine"] ?? false) {
                $output .= "[" . $this->stamp($cue->getEnd()) . "]" . LineEnding::Lf->value;
            }
        }
        $output .= $this->formatComments($comments[count($cues)] ?? []);

        return $this->applyOutputOptions($output, $options);
    }


    private function formatIdTags(Subtitle $subtitle): string
    {
        $tags = [];
        foreach (LyricsParser::METADATA_TAGS as $tag => $metadataKey) {
            if ($subtitle->findMetadata($metadataKey) !== null) {
                $tags[$tag] = $subtitle->findMetadata($metadataKey);
            }
        }
        $tags += $subtitle->findFormatData(LyricsParser::FORMAT_DATA_KEY)["idTags"] ?? [];

        $output = "";
        foreach ($tags as $tag => $value) {
            // The parser reads a tag only with this key. It takes a numeric offset as a shift of the times, which the written times hold already.
            if (preg_match("/^[A-Za-z][A-Za-z0-9_]*$/D", (string) $tag) !== 1
                || (strtolower((string) $tag) === "offset" && preg_match("/^[+-]?\\d+$/D", trim((string) $value)) === 1)) {
                continue;
            }
            $output .= "[" . $tag . ":" . Markup::toSingleLine($value) . "]" . LineEnding::Lf->value;
        }

        return $output;
    }


    /**
     * @param list<Comment> $comments
     */
    private function formatComments(array $comments): string
    {
        $output = "";
        foreach ($comments as $comment) {
            $output .= "[#:" . Markup::toSingleLine($comment->text) . "]" . LineEnding::Lf->value;
        }

        return $output;
    }


    /**
     * $spaced writes a space on both sides of each word time, as in "<00:03.20> Slow <00:03.75> river", when the parser read that form.
     */
    private function formatCue(SubtitleCue $cue, bool $spaced): string
    {
        $timestamp = "[" . $this->stamp($cue->getStart()) . "]";

        $parts = preg_split(Markup::WORD_TIMESTAMP_REGEX, Markup::rubyAsText(str_replace(LineEnding::Lf->value, " ", implode(" ", $cue->getLines()))), -1, PREG_SPLIT_DELIM_CAPTURE);
        $lines  = "";
        $latest = null;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $lines .= Markup::plainText($part);
                continue;
            }

            $seconds = Markup::wordTimestampSeconds($part) ?? $latest ?? 0.0;
            if (!Markup::keepsWordTimeOrder($seconds, $latest)) {
                continue;
            }

            $next   = Markup::plainText($parts[$index + 1]);
            $before = $spaced && $index === count($parts) - 2 && $next === "" && $lines !== "" && trim(substr($lines, -1)) !== "" ? " " : "";
            $after  = $spaced && $next !== "" && ltrim($next) === $next ? " " : "";
            $lines .= $before . "<" . $this->stamp($seconds) . ">" . $after;
        }

        return $timestamp . " " . $lines;
    }


    /**
     * Returns the time as mm:ss.cc, without the brackets of a line stamp or a word stamp.
     */
    private function stamp(float $seconds): string
    {
        $units = min(Timecode::unitsBelowLimit($seconds, 100), self::LAST_UNIT);

        return sprintf("%02d:%02d.%02d", intdiv($units, 6000), intdiv($units, 100) % 60, $units % 100);
    }
}
