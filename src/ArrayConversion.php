<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

/**
 * @internal
 */
trait ArrayConversion
{
    public const ARRAY_VERSION = 1;


    /**
     * Returns the subtitle as an array of scalars in the shape that docs/json.md documents.
     */
    public function toArray(bool $withFormatData = true): array
    {
        $this->sortStartChanges();
        $cues = [];
        foreach ($this->cues as $cue) {
            $cueArray = [
                "start"      => $cue->getStart(),
                "end"        => $cue->getEnd(),
                "lines"      => array_values($cue->getLines()),
                "identifier" => $cue->findIdentifier(),
                "alignment"  => $cue->getAlignment() === Alignment::BottomCenter ? null : $cue->getAlignment()->value,
            ];
            if ($cue->isForced()) {
                $cueArray["forced"] = true;
            }
            if ($withFormatData) {
                $cueArray["formatData"] = $cue->getAllFormatData();
            }
            $cues[] = $cueArray;
        }

        $array = [
            "version"  => self::ARRAY_VERSION,
            "metadata" => $this->metadata,
            "comments" => array_map(fn (Comment $comment): array => ["text" => $comment->text, "beforeCueIndex" => $comment->beforeCueIndex],
                                    $this->comments),
        ];
        if ($withFormatData) {
            $array["formatData"] = $this->formatData;
        }
        $array["cues"] = $cues;

        return $array;
    }


    /**
     * Builds a subtitle from the array that toArray() returns, and keeps the cue order.
     * A bad field throws ParsingException with the path of the field.
     */
    public static function fromArray(array $data): self
    {
        return ArrayReader::read($data, [], static fn (ParsingException $exception): never => throw $exception);
    }
}
