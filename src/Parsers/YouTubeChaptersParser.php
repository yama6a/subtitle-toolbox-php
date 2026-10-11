<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class YouTubeChaptersParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = ChapterReadOptions::class;

    // A space, "|", ":", "-", an en dash or an em dash. The dashes are UTF-8 bytes, so the patterns need no /u flag.
    private const SEPARATOR       = '(?:[\s|:-]|\xE2\x80[\x93\x94])';
    private const AFTER_SEPARATOR = '(?<=^|[\s|:-]|\xE2\x80\x93|\xE2\x80\x94)';
    private const TIME            = '[(\[]?(?:(\d+):)?(\d+):(\d{2})[)\]]?';


    /**
     * Reads the lines of $content that start or end with a time such as 2:48 or 1:02:48, for example a video description.
     */
    protected function read(string $content): Subtitle
    {
        $lines = $this->lines($content);

        $chapters = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^' . self::TIME . '(?=$|' . self::SEPARATOR . ')(.*)$/', $line, $matches) === 1) {
                [, $hours, $minutes, $seconds, $title] = $matches;
            } elseif (preg_match('/^(.*?)' . self::AFTER_SEPARATOR . self::TIME . '$/', $line, $matches) === 1) {
                [, $title, $hours, $minutes, $seconds] = $matches;
            } else {
                continue;
            }
            $time = Timecode::toSeconds((int) $hours, (int) $minutes, (int) $seconds);
            if ((int) $seconds > 59 || ($hours !== "" && (int) $minutes > 59) || $time >= Timecode::MAX_HOURS * 3600) {
                continue;
            }

            $title      = preg_replace('/^' . self::SEPARATOR . '+|' . self::SEPARATOR . '+$/', "", $title);
            $chapters[] = new SubtitleCue($time, 0, Markup::escapeText($title));
        }

        return (new Subtitle())->addCues($this->endChapters($chapters));
    }
}
