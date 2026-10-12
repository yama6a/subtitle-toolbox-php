<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

/**
 * What SpeakerLabels knows while it splits the lines of one cue at each change of the speaker.
 *
 * @internal
 */
final class SpeakerSplitState
{
    public ?string $speaker = null;

    /** @var list<OpenStyleTag> */
    public array $open = [];

    /** @var list<array{speaker: ?string, text: string}> */
    public array $parts = [];
}
