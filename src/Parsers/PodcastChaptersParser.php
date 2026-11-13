<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * @see https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/examples/chapters/jsonChapters.md
 */
final class PodcastChaptersParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = ChapterReadOptions::class;
    public const FORMAT_DATA_KEY = Format::PodcastChapters->value;


    protected function read(string $content): Subtitle
    {
        $data = $this->decodeJsonObject($content);
        if (!is_array($data["chapters"] ?? null) || !array_is_list($data["chapters"]) || $this->isJsonObject("chapters")) {
            throw new ParsingException("The JSON has no \"chapters\" list.");
        }

        $chapters = [];
        $ends     = [];
        foreach ($data["chapters"] as $index => $chapter) {
            $start = is_array($chapter) ? $chapter["startTime"] ?? null : null;
            if (!self::isTime($start)) {
                throw new ParsingException("The field chapters[$index].startTime must be a number.");
            }
            $end   = $chapter["endTime"] ?? null;
            if ($end !== null && !self::isTime($end)) {
                throw new ParsingException("The field chapters[$index].endTime must be a number.");
            }
            $start = self::boundedField($start, "chapters[$index].startTime");
            $end   = $end === null ? null : self::boundedField($end, "chapters[$index].endTime");
            $title = $chapter["title"] ?? null;

            $cue = new SubtitleCue($start, $start, is_string($title) ? Markup::escapeText($title) : "");
            $cue->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($chapter, array_flip(["startTime", "endTime", "title"])));
            $chapters[] = $cue;
            $ends[]     = $end;
        }

        $subtitle = (new Subtitle())->addCues($this->endChapters($chapters, $ends));

        foreach (["title" => Subtitle::METADATA_TITLE, "author" => Subtitle::METADATA_AUTHOR] as $field => $key) {
            if (is_string($data[$field] ?? null)) {
                $subtitle->setMetadata($key, $data[$field]);
            }
        }

        return $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($data, array_flip(["chapters", "title", "author"])));
    }
}
