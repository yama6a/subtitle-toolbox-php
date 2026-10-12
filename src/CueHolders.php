<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * CueHolders knows the subtitles that hold each cue. For each subtitle, it collects the held cues whose start changed.
 * The maps are static WeakMaps, so == on cues and subtitles stays as it is, and a dropped cue or subtitle leaves them.
 *
 * @internal
 */
final class CueHolders
{
    /**
     * Most cues have one holder. A WeakReference stores it, and all cues of a subtitle share that one object.
     * A cue with more holders gets a WeakMap.
     *
     * @var ?\WeakMap<SubtitleCue, \WeakReference<Subtitle>|\WeakMap<Subtitle, true>>
     */
    private static ?\WeakMap $holders = null;

    /** @var ?\WeakMap<Subtitle, \SplObjectStorage<SubtitleCue, null>> */
    private static ?\WeakMap $startChanges = null;


    /**
     * @param iterable<SubtitleCue> $cues
     */
    public static function hold(Subtitle $subtitle, iterable $cues): void
    {
        self::$holders ??= new \WeakMap();
        $reference = \WeakReference::create($subtitle);
        foreach ($cues as $cue) {
            $holders = self::$holders[$cue] ?? null;
            if ($holders instanceof \WeakMap) {
                $holders[$subtitle] = true;
            } elseif ($holders === null || $holders->get() === null || $holders === $reference) {
                self::$holders[$cue] = $reference;
            } else {
                $map                  = new \WeakMap();
                $map[$holders->get()] = true;
                $map[$subtitle]       = true;
                self::$holders[$cue]  = $map;
            }
        }
    }


    /**
     * @param iterable<SubtitleCue> $cues
     */
    public static function release(Subtitle $subtitle, iterable $cues): void
    {
        foreach ($cues as $cue) {
            $holders = self::$holders[$cue] ?? null;
            if ($holders instanceof \WeakMap) {
                unset($holders[$subtitle]);
            } elseif ($holders?->get() === $subtitle) {
                unset(self::$holders[$cue]);
            }
        }
    }


    public static function isHeld(SubtitleCue $cue): bool
    {
        return self::holders($cue) !== [];
    }


    /**
     * Adds $cue to the start changes of each subtitle that holds it.
     */
    public static function startChanged(SubtitleCue $cue): void
    {
        self::$startChanges ??= new \WeakMap();
        foreach (self::holders($cue) as $subtitle) {
            self::$startChanges[$subtitle] ??= new \SplObjectStorage();
            self::$startChanges[$subtitle][$cue] = null;
        }
    }


    /**
     * Returns the cues of $subtitle whose start changed since the last call, in no order, and forgets them.
     * A cue that $subtitle no longer holds can be among them.
     *
     * @return list<SubtitleCue>
     */
    public static function takeStartChanges(Subtitle $subtitle): array
    {
        if (!isset(self::$startChanges[$subtitle])) {
            return [];
        }

        $changes = self::$startChanges[$subtitle];
        unset(self::$startChanges[$subtitle]);

        return iterator_to_array($changes, false);
    }


    /**
     * @return list<Subtitle>
     */
    private static function holders(SubtitleCue $cue): array
    {
        $holders = self::$holders[$cue] ?? null;
        if ($holders instanceof \WeakMap) {
            $subtitles = [];
            foreach ($holders as $subtitle => $held) {
                $subtitles[] = $subtitle;
            }

            return $subtitles;
        }
        $subtitle = $holders?->get();

        return $subtitle === null ? [] : [$subtitle];
    }
}
