# Sync

[editing.md](editing.md#retiming) covers fixed shifts, scales, frame rate changes and the sync by known points. On the command line, `convert --sync` does the sync by known points, see [cli.md](cli.md#timing). This page covers the sync that finds the offset and scale for you.

## Sync to a reference subtitle
A German SRT for the 25 fps release is late and drifts against a 23.976 fps video. An English SRT for that video is in sync. `ReferenceSync` finds the scale and the offset from the cue times alone, so the languages can differ.

```php
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;

$result = ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $english));   // calls scale() and then shift() on $german
$result->scale;                                                                           // 1.04271 (25 / 23.976)
$result->offset;                                                                          // -2.3, added after the scale
$result->score;                                                                           // 0.89

ReferenceSync::apply($german, new ReferenceSyncOptions(
    reference: $english,
    minOffset: -120,       // seconds, default -60
    maxOffset: 120,        // seconds, default 60
    searchScale: false,    // true (default) tries the frame-rate factors, false keeps the scale at 1
));
```

- **In place**: `apply()` changes the subtitle that you pass. Pass `clone $german` to keep the original and only read the result.
- **Matching**: only the cue times count, not the text. The idea comes from [alass](https://github.com/kaegi/alass).
- **Scale factors**: 1, 24/23.976, 25/24 and 25/23.976 and their inverses. Other factors are not found. One scale applies to the whole file.
- **Offsets**: the search finds offsets between `minOffset` and `maxOffset`, to 0.01 s.
- **Limits**: `minOffset` and `maxOffset` are from -86,400 to 86,400 s and at most 7,200 s apart. `maxSplits` is from 0 to 10. A larger value throws `InvalidArgumentException`, because the split search needs memory for each 0.1 s of the offset range. 10 splits over 7,200 s take about 40 s.
- **Score**: from 0 to 1. A score below 0.5 means the files likely do not match. Missing and extra cues lower the score. The result stays correct while most cues match.
- **Speed**: 2,000 cues against 2,000 cues take about 0.5 s.

## Splits
A TV recording has a 2:30 ad break at 6:30. The German SRT of the streaming release has none. A **split** is a point where the offset jumps. With `maxSplits`, each part between two splits gets its own offset. All parts share one scale.

```php
$result = ReferenceSync::apply($german, new ReferenceSyncOptions(
    reference: $englishTv,
    minOffset: -180,
    maxOffset: 180,        // the part after the break needs 147.7 s
    maxSplits: 2,          // default 0, no split search
    splitPenalty: 0.1,     // score units, default 0.1
));
$result->segments;         // 2 SyncSegment objects
$result->segments[0];      // from 0.0, to 414.32, scale 1.04271, offset -2.31
$result->segments[1];      // from 414.32, to INF, scale 1.04271, offset 147.7
$result->offset;           // -2.31, the offset of the first part. apply() shifted each part with its own offset.
```

- **Segments**: each `SyncSegment` has `from`, `to`, `scale` and `offset`. `from` and `to` are cue start times of the subtitle before the sync. A cue goes to the part that holds its start. Without a split, `segments` holds one part from 0 to `INF`.
- **Penalty**: a split stays only when it raises the score by more than `splitPenalty`. A part with 5% of the cue time raises the score by about 0.1. In unrelated files, a chance split raises the score by up to 0.04 with 300 cues. With 60 cues, it raises the score by up to 0.12. So a file with 60 cues or fewer needs a penalty above 0.12.
- **Split points**: splits fall between cues.
- **Overlaps**: `apply()` can move a part onto the next part. Then each cue of the earlier part that overlaps the later part ends 1 ms before the later part starts.
- **Speed**: with `maxSplits: 2`, 2,000 cues against 2,000 cues take about 1.5 s.

## Sync to speech
Without a reference subtitle, the speech in the audio is the reference. FFmpeg finds the silences, and `SpeechReference` turns the speech between them into cues without text.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;

// ffmpeg -i movie.mkv -af silencedetect=noise=-30dB:d=0.4 -f null - 2> silence.log
$speech = SpeechReference::fromFfmpegSilencedetect(file_get_contents('silence.log'), mediaDuration: 840);
ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $speech));

$speech = SpeechReference::fromIntervals([[1.2, 3.4], [5.0, 7.75]]);   // seconds, from any voice activity detector

$transcript = Subtitle::fromString(file_get_contents('whisper.json'), Format::Whisper);   // a Whisper JSON transcript of the audio
ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $transcript));
```

- **Log**: the reader takes the `silence_start` and `silence_end` lines of the FFmpeg `silencedetect` filter. Speech fills the time between the silences from 0 to `mediaDuration`. A silence without an end runs to `mediaDuration`.
- **Mono**: `silencedetect=mono=1` writes one line per channel. The reader throws `ParsingException` for such a log, and for a `silence_end` without a `silence_start` before it.
- **Score**: speech starts later and ends earlier than its cue. So the score stays lower than with a reference subtitle. The German example scores 0.78 against the speech and 0.89 against the English subtitle.
- **Whisper**: a [Whisper JSON](transcripts.md#whisper-json) transcript has cue times from the audio. Its language does not matter, because only the times count.
- **Intervals**: `fromIntervals()` throws `InvalidArgumentException` for an entry that is not `[start, end]` with 0 <= start <= end.
