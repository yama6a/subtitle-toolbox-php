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

    /** @var ?\WeakMap<Subtitle, \SplObjectStorage<SubtitleCue, float>> each changed cue and its start at the last read */
    private static ?\WeakMap $startChanges = null;

    /** @var ?\WeakMap<Subtitle, \SplObjectStorage<SubtitleCue, null>> the cues whose start or end changed since the cue lookup took them */
    private static ?\WeakMap $timeChanges = null;


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
     * Adds $cue to the start changes of each subtitle that holds it. $startBefore is the start before the edit.
     * A cue that changed before keeps its first start, which is the start that its place in the cues follows.
     */
    public static function startChanged(SubtitleCue $cue, float $startBefore): void
    {
        self::$startChanges ??= new \WeakMap();
        foreach (self::holders($cue) as $subtitle) {
            self::$startChanges[$subtitle] ??= new \SplObjectStorage();
            if (!isset(self::$startChanges[$subtitle][$cue])) {
                self::$startChanges[$subtitle][$cue] = $startBefore;
            }
        }
    }


    /**
     * Returns the cues of $subtitle whose start changed since the last call, each with its start at that call, and
     * forgets them. A cue that $subtitle no longer holds can be among them.
     *
     * @return ?\SplObjectStorage<SubtitleCue, float> null when no start changed
     */
    public static function takeStartChanges(Subtitle $subtitle): ?\SplObjectStorage
    {
        if (!isset(self::$startChanges[$subtitle])) {
            return null;
        }

        $changes = self::$startChanges[$subtitle];
        unset(self::$startChanges[$subtitle]);

        return $changes;
    }


    /**
     * Starts to collect the time changes of the cues of $subtitle, or forgets the ones collected so far.
     */
    public static function watchTimes(Subtitle $subtitle): void
    {
        self::$timeChanges ??= new \WeakMap();
        self::$timeChanges[$subtitle] = new \SplObjectStorage();
    }


    /**
     * Adds $cue to the time changes of each subtitle that holds it and watches its times.
     */
    public static function timeChanged(SubtitleCue $cue): void
    {
        foreach (self::holders($cue) as $subtitle) {
            if (isset(self::$timeChanges[$subtitle])) {
                self::$timeChanges[$subtitle][$cue] = null;
            }
        }
    }


    /**
     * Returns the cues of $subtitle whose start or end changed since the last call or watchTimes(), and forgets them.
     * A cue that $subtitle no longer holds can be among them.
     *
     * @return list<SubtitleCue>
     */
    public static function takeTimeChanges(Subtitle $subtitle): array
    {
        if (!isset(self::$timeChanges[$subtitle]) || count(self::$timeChanges[$subtitle]) === 0) {
            return [];
        }

        $changes                      = iterator_to_array(self::$timeChanges[$subtitle], false);
        self::$timeChanges[$subtitle] = new \SplObjectStorage();

        return $changes;
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
