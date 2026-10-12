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


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $output   = $this->formatIdTags($subtitle);
        $cues     = array_values($subtitle->getCues());
        $comments = $this->commentsByCue($subtitle);

        foreach ($cues as $cueIndex => $cue) {
            $output .= $this->formatComments($comments[$cueIndex] ?? []);
            $output .= $this->formatCue($cue);
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


    private function formatCue(SubtitleCue $cue): string
    {
        $timestamp = "[" . $this->stamp($cue->getStart()) . "]";

        $parts = preg_split(Markup::WORD_TIMESTAMP_REGEX, Markup::rubyAsText(implode(" ", $cue->getLines())), -1, PREG_SPLIT_DELIM_CAPTURE);
        $lines = "";
        foreach ($parts as $index => $part) {
            $lines .= $index % 2 === 1 ? "<" . $this->stamp(Markup::wordTimestampSeconds($part)) . ">" : Markup::plainText($part);
        }

        return $timestamp . " " . $lines;
    }


    /**
     * Returns the time as mm:ss.cc, without the brackets of a line stamp or a word stamp.
     */
    private function stamp(float $seconds): string
    {
        [$hours, $minutes, $wholeSeconds, $centiseconds] = Timecode::centiseconds($seconds);

        return sprintf("%02d:%02d.%02d", 60 * $hours + $minutes, $wholeSeconds, $centiseconds);
    }
}
