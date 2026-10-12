<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

/**
 * One line of a cue without its <v> tags.
 *
 * @internal
 */
final class SpeakerLine
{
    /**
     * @param ?string $speaker       the name of the speaker, or null for a line without a speaker
     * @param string  $text          the line with its style tags
     * @param bool    $startsSpeaker the line is the first line with text of its speaker in the cue
     */
    public function __construct(
        public readonly ?string $speaker,
        public readonly string $text,
        public readonly bool $startsSpeaker,
    ) {
    }
}
