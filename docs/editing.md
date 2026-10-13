# Editing cues

All methods on this page change the `Subtitle` in place and return it, unless the text says otherwise. To keep the original, edit a copy, see [Keep the original](subtitle.md#call-shapes):

```php
$copy = clone $subtitle;
$copy->shift(2);                                     // $subtitle keeps its times
```

## Retiming
```php
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\SyncPoint;

$subtitle->shift(-2.5);                              // all cues 2.5 s earlier
$subtitle->shift(3, 600);                            // only cues that start at 600 s or later
$subtitle->shift(2, from: 600, to: 900);     // only cues that start at 600 s or later and before 900 s
$subtitle->scale(1.001);                             // multiply all times by 1.001
$subtitle->convertFrameRate(25, 23.976);             // subtitle for a 25 fps video, video is 23.976 fps
$subtitle->syncByPoints([                            // 10 s becomes 12 s, 600 s becomes 610 s, 1200 s becomes 1205 s
    new SyncPoint(oldSeconds: 10, newSeconds: 12),
    new SyncPoint(oldSeconds: 600, newSeconds: 610),
    new SyncPoint(oldSeconds: 1200, newSeconds: 1205),
]);
(new FrameRate(23.976))->framesToSeconds(1000);      // about 41.708
```

- **Negative times**: a start or end time that becomes negative becomes 0. The cue stays in the subtitle.
- **Time limit**: a cue time must stay below 100000 hours, the limit of the parsers. A `shift()`, `scale()`, `convertFrameRate()`, `syncByPoints()` or `append()` that moves a cue end to 100000 hours or more throws `InvalidArgumentException`, for example `scale(1e300)`. The subtitle then stays as it was. `shift()` and `append()` also reject an offset of NAN or INF.
- **Shift range**: the start time of a cue decides if `shift()` moves it. So a cue that starts before `$to` and ends after it moves as a whole. A cue that starts before `$from` stays. `$to` must be after `$from`, else `shift()` throws `InvalidArgumentException`. A cue that moves before an earlier cue changes its place in the list. Its comments move with it.
- **Sync by points**: `syncByPoints()` moves each old time to its new time. Between 2 points it corrects the times linearly. Before the first point and after the last point it continues the correction of the nearest 2 points. 1 point shifts all cues.
- **Point order**: the old and new times must both increase from point to point. Else `syncByPoints()` throws `InvalidArgumentException`.
- **Cue across a point**: each start, end and word timestamp moves by the segment that holds it. So a cue across a point keeps its times in step with the speech.
- **Word timestamps**: these 4 methods also move the word timestamps in the cue text, such as `<00:00:02.000>`. `append()` with an offset and `withSlice()` with `$moveToZero` move them too. A word timestamp that becomes negative becomes 0.
- **Cue boundaries**: some methods move a start or end time without moving the speech. So the word timestamps keep their times. These are `fixOverlaps()`, `extendShortCues()`, `addLeadInOut()`, `limitLongCues()`, the [shot change timing](#shot-changes-and-gaps) and the snap of `DualSubtitle`.
- **Speech-to-text format data**: the format data of Whisper, Deepgram, AssemblyAI, AWS Transcribe and Google input is a copy of the source file. It keeps the times of the source file. Read the word times from the word timestamps in the cue text.
- **Other ways to sync**: [sync.md](sync.md) finds the offset and scale from a reference subtitle or the speech in the audio.

## Append, slice, split and join
```php
$part1->append($part2, 3130);                    // appends part 2, 3130 s later
$clip = $subtitle->withSlice(600, 1200, true);  // a new Subtitle with the cues from 600 s to 1200 s, moved to start at 0
$subtitle->splitCue(4, 63.5, 1);                // cue 4 becomes two cues at 63.5 s, line 1 in the first
$subtitle->joinCues(4, 5);                      // one cue with the lines of cue 4 and 5
$subtitle->removeDuplicateCues();               // joins same-text cues that overlap or touch
$subtitle->removeDuplicateCues(0.5);            // also joins same-text cues up to 0.5 s apart
$subtitle->mergeSameTimeCues();                 // joins cues with the same start and end into one cue
```

- **Merge**: the metadata and the format data of `$part1` win over those of `$part2`. The comments of both files stay before their cues. At the same place, the comments of `$part1` come first.
- **Slice**: a cue that crosses the start or end time gets cut there. The copy keeps the metadata, the format data and the comments before the kept cues. The original stays unchanged.
- **Split and join**: the first cue keeps its identifier. A comment before a joined cue moves before the result. An index without a cue throws `CueNotFoundException`, as in `removeCue()`.
- **Duplicates**: `removeDuplicateCues()` joins a run of adjacent cues with the same text. Two cues join when they are identical, overlap, touch, or are at most `maxGap` seconds apart. The default `maxGap` is 0. The joined cue runs from the earliest start to the latest end. Cues with other text between them stay apart. Same-text cues also stay apart when their alignment, forced flag or format data differ, such as an ASS `Glow` event on layer 0 under a `Default` event on layer 1. A negative, NAN or INF `maxGap` throws `InvalidArgumentException`.
- **Same times**: `mergeSameTimeCues()` joins a run of adjacent cues whose start and end both differ by at most `timeTolerance` seconds from the first cue of the run. The default `timeTolerance` is 0. Example: ASS gives `- Where are you going?` and `- Home.` at 1.0 to 3.0 s as 2 events, one per speaker. Many TVs show only one of them. After the join, one cue holds both lines.
  - The joined cue keeps the start, identifier and format data of the first cue. It runs to the latest end of the run.
  - The lines keep the input order. A cue text that is already in the joined cue is not added again.
  - Cues with another alignment or forced flag stay apart, so a sign at the top keeps its place. Other format data, such as the ASS style, does not stop a join.
  - Image cues, such as PGS or VobSub cues, never join. A joined cue can hold only one image.
  - The method adds no dialogue dash. `CommonErrorFixer` can add them.
  - A negative, NAN or INF `timeTolerance` throws `InvalidArgumentException`.

## Overlaps, short cues and line breaks
```php
$gap = (new FrameRate(24))->framesToSeconds(2);   // about 0.083 s

$subtitle->fixOverlaps($gap);                     // end each cue at least $gap before the next cue starts
$subtitle->extendShortCues(0.833, $gap);          // show each cue for at least 0.833 s where the next cue allows it
$subtitle->addLeadInOut(0.2, 0.3, $gap);         // start each cue 0.2 s earlier and end it 0.3 s later where the neighbours allow it
$subtitle->limitLongCues(7);                      // end each cue at most 7 s after its start
$subtitle->wrapLines(42);                         // at most 42 characters per line, at most 2 lines
$subtitle->unwrapLines();                         // join the lines of each dialogue turn with a space
```

- **Start times**: `fixOverlaps()`, `extendShortCues()` and `limitLongCues()` move only end times. `fixOverlaps()` ends a cue at its own start when the gap does not fit. Cues with the same start, such as a sign and a line of dialogue, end before the next cue with a later start. `extendShortCues()` never creates an overlap and never makes a cue shorter.
- **Lead-in and lead-out**: speech-to-text cues start and end exactly on the speech, so they flash on and off. `addLeadInOut()` moves each start back by the lead-in and each end on by the lead-out. A start never goes below 0. A cue stops at least `minGap` seconds before or after a cue it did not overlap.
  - **Priority**: the lead-out comes first. So in a short gap the end of the earlier cue takes the space, and the next cue gets the lead-in that is left. For example, with `A` 1.0 to 2.0 and `B` 2.2 to 3.0, `addLeadInOut(0.3, 0.3)` gives `A` 0.7 to 2.2 and `B` 2.2 to 3.3. The end is the part of a cue that a reader most often misses.
  - **Overlaps**: a start or end inside another cue stays where it is. So `A` 1.0 to 3.0 and `B` 2.0 to 4.0 become `A` 0.8 to 3.0 and `B` 2.0 to 4.2 with 0.2 s each.
  - **Limits**: a negative, NAN or INF value throws `InvalidArgumentException`. The method never makes a cue shorter.
- **Long cues**: `limitLongCues(7)` ends a `Hi.` from 1.0 to 26.0 at 8.0. It never makes a cue longer. After it, the `MaxDuration` [validation rule](validation.md) with the same limit finds no cue. A maximum of 0 or less, NAN or INF throws `InvalidArgumentException`.
- **Line breaks**: `wrapLines()` changes only cues with a longer line or with more lines than allowed. It uses the fewest lines that fit and makes them about equal in length. When the text does not fit, the lines get longer than the limit.
- **Dialogue**: each dialogue turn keeps lines of its own. A turn starts at a line with a dialogue dash, such as `- Yes.`. It also starts at a dash after a sentence end within a line, so `- Are you coming? - Yes.` becomes 2 lines. A sentence end is `.`, `?`, `!`, an ellipsis character, the full-width `？` and `！`, or the ideographic full stop `。` and its half-width form `｡`. So `- 你要去哪里？ - 我要去学校。` becomes 2 lines too. A dash before a digit, as in `-20 degrees`, starts no turn. A long turn wraps within itself, with up to the maximum lines per turn. So a cue with 2 or more turns can have more lines than the maximum.
- **Unwrapping**: `unwrapLines()` joins the lines of each turn with a space. It joins 2 lines without a space when a CJK character ends the first and starts the second. A turn starts at the first line and at each line with a dialogue dash. For example, the lines `- Are you coming?`, `- Yes, in a minute,` and `I promise you.` become 2 lines: `- Are you coming?` and `- Yes, in a minute, I promise you.` A cue without dash lines becomes 1 line. A dash after a sentence end within a line stays on that line.
- **Characters**: tags count 0 characters, and an entity such as `&amp;` counts 1. `wrapLines()` breaks at spaces outside tags, and between 2 CJK characters. It closes the open core markup tags at a break and opens them again on the next line.
- **Chinese and Japanese**: a CJK character is a Han character, Hiragana, Katakana, CJK punctuation or a full-width form. Each counts as 1 character, as in the Netflix guides: 16 per line for Simplified Chinese, 13 for Japanese. `wrapLines(16)` breaks `我们明天早上八点在火车站见面，然后一起坐车去北京看望奶奶。` into `我们明天早上八点在火车站见面，` and `然后一起坐车去北京看望奶奶。`.
  - **Break choice**: a break after a space, CJK punctuation or an ideographic space wins over a break between 2 other characters. Among breaks of one kind, the most equal lines win. The library has no dictionary, so without punctuation a break can fall inside a word, such as `スケジュー` and `ル`.
  - **Line start and end**: no line starts with closing punctuation such as `。`, `、`, `，`, `！`, `？`, `」` or `）`, with a small kana such as `っ` or `ャ`, or with the long vowel mark `ー`. No line ends with opening punctuation such as `「`, `『`, `（`, `【` or `《`. This is the basic rule of JIS X 4051.
  - **Mixed text**: a Latin letter, a digit or ASCII punctuation next to a CJK character breaks only at a space. So `用WhatsApp联` stays on one line.
  - **Korean**: Hangul text uses spaces, so it breaks only at spaces.

## Short cues
Speech-to-text output and quick exchanges of 1 or 2 words often have cues under 1 s. `mergeShortCues()` joins such a cue with its neighbor when the joined cue still fits the limits.

```php
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\MergeShortCuesOptions;

// 00:01:02,100 --> 00:01:02,600  Wait.
// 00:01:02,640 --> 00:01:03,300  Where are you
// 00:01:03,320 --> 00:01:04,100  going?
$subtitle->mergeShortCues(new MergeShortCuesOptions(
    limits: new CueLimits(maxCharactersPerLine: 42, maxLinesPerCue: 2, maxDuration: 7),
    maxGap: 0.25,
));
// 00:01:02,100 --> 00:01:04,100  Wait. Where are you going?

$subtitle->mergeShortCues();   // the default limits of new MergeShortCuesOptions()
```

`CueLimits` holds the limits that `MergeShortCuesOptions` and `ResegmentOptions` share.

| `CueLimits` field | Default | Meaning for `mergeShortCues()` |
|:--- |:--- |:--- |
| `maxCharactersPerLine` | 42 | the line length of the joined text |
| `maxLinesPerCue` | 2 | the line count of the joined text |
| `minDuration` | 1 | a cue shorter than this many seconds is short |
| `maxDuration` | 7 | seconds from the start to the end of the joined cue |
| `maxCharactersPerSecond` | null | the reading speed of the joined cue. Null turns the rule off |

The `CueLimits` constructor throws `InvalidArgumentException` when `minDuration` is greater than `maxDuration`.

| Option | Default | Meaning |
|:--- |:--- |:--- |
| `limits` | `new CueLimits()` | the limits above |
| `maxGap` | 0.25 | seconds from the end of one cue to the start of the next |
| `minCharacters` | null | a cue with fewer visible characters is short. Null turns the rule off |
| `keepSentenceEnds` | false | join only when the first cue does not end with `.`, `?` or `!` |
| `mergeSameSpeakerAnyDuration` | false | join each cue with the next cue of the same `<v>` speaker, short or not, with no `maxDuration` limit. A cue without a `<v>` tag never joins |

- **Order**: the method walks the cues in start time order. It joins a short cue with the next cue. When the next cue does not fit, it tries the previous cue. A joined cue that is still short joins again.
- **Never joined**: cues with different `<v>` speakers, different alignments or different forced flags, and image cues. A cue without a `<v>` tag and a cue with one have different speakers.
- **Joined cue**: it keeps the start, the identifier, the alignment and the format data of the first cue. It keeps the end of the last cue. A comment before a joined cue moves before the result.
- **Text**: the method joins the lines with a space and wraps them as `wrapLines()` does. A line that starts with a dialogue dash stays on its own line. Each such line must then fit on one line. When both cues start with a `<v>` tag of the same speaker, the joined text keeps only the first tag.

## Long cues
Speech-to-text tools such as Whisper write segments of 10 s and more. `wrapLines()` makes the lines shorter, but the cue stays too long to read. `Resegmenter` with `ResegmentMode::SplitLong` splits such a cue into cues that fit the limits.

```php
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;

// 00:00:00,000 --> 00:00:11,050  The tensor operators are optimized heavily for Apple silicon CPUs. Depending on
//                                the computation size, Arm Neon SIMD intrinsics or CBLAS Accelerate framework routines are used.
$report = Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 42, maxLinesPerCue: 2)));
// 00:00:00,000 --> 00:00:04,231  The tensor operators are optimized heavily for Apple silicon CPUs.
// 00:00:04,231 --> 00:00:06,441  Depending on the computation size,
// 00:00:06,441 --> 00:00:11,050  Arm Neon SIMD intrinsics or CBLAS Accelerate framework routines are used.
$report->cuesBefore;   // 1
$report->cuesAfter;    // 3

Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::ByWords, maxWordGap: 0.6));
```

`ResegmentMode::ByWords` drops the cue boundaries and builds new cues from the word timestamps, for example from Whisper JSON read with `TranscriptReadOptions::$wordTimestamps`. Each cue then holds one sentence, or as much of it as fits.

| Option | Default | Meaning |
|:--- |:--- |:--- |
| `mode` | required | `ResegmentMode::SplitLong` or `ResegmentMode::ByWords` |
| `limits` | `new CueLimits()` | the limits of each new cue. `SplitLong` never makes a cue shorter than `minDuration` |
| `maxWordGap` | 0.6 | `ByWords` ends a cue at a pause of this many seconds or more |

- **Mode values**: `ResegmentMode::from('byWords')` returns `ResegmentMode::ByWords`. The values are `splitLong` and `byWords`.
- **Limits**: a cue breaks the limits when its text does not fit `maxLinesPerCue` lines of `maxCharactersPerLine` characters, as `wrapLines()` wraps it. It also breaks them above `maxDuration` or `maxCharactersPerSecond`.
- **Break points**: best first, a sentence end, a clause end, then the space closest to the middle. Among break points of the same kind, the one closest to the middle wins. A full stop before a word in lower case, as in "e.g. this", does not end a sentence.
- **Splitting**: `SplitLong` splits a cue in two at the best break point. It splits each part again while the part breaks a limit. A cue stays unchanged when no break point keeps both parts at `minDuration` or longer.
- **`minDuration`**: `ByWords` does not use it.
- **Times**: a new cue starts at the word timestamp of its first word. Without one, the time splits in proportion to the visible characters.
- **Text without spaces**: text such as Japanese splits after CJK punctuation and at word timestamps.
- **Regrouping**: `ByWords` ends a cue after a sentence end and before a pause of `maxWordGap` seconds. It also ends a cue before a word that would break a limit. It never joins words of cues with different `<v>` speakers, alignments or forced flags. Cues without word timestamps stay unchanged.
- **Tags**: a core markup tag can be open at a break. It then closes at the end of the first cue and opens again in the next cue.
- **Unchanged**: image cues and cues of one word.
- **Cue data**: a new cue keeps the alignment, forced flag and format data of its source cue. Only the cue with the first word of a source cue keeps its identifier.

## Shot changes and gaps
A shot change is the frame where the picture cuts to a new shot. `ShotChangeTiming` times cues to the shot changes and [closes gaps](#closing-gaps) between cues, as the [Netflix Subtitle Timing Guidelines](https://partnerhelp.netflixstudios.com/hc/en-us/articles/360051554394) require. You pass the shot change times. The library does not read video.

```php
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;

// ffmpeg -i in.mp4 -vf "select='gt(scene,0.3)',showinfo" -f null - 2> scenes.log
$shotChanges = ShotChanges::fromFfmpegLog(file_get_contents('scenes.log'));   // [12.5, 62.5, 70.0]
$shotChanges = ShotChanges::fromText("12.5\n00:01:02.500\n70\n");               // the same times

$report = ShotChangeTiming::apply($subtitle, new ShotChangeOptions(
    frameRate: 24,
    shotChanges: $shotChanges,   // seconds. Without shot changes, apply() only closes gaps.
    snapWindowFrames: 12,        // frames, default frameRate / 2 rounded half down: 12 at 23.976, 24 and 25 fps, 15 at 29.97 fps
    minGapFrames: 2,             // frames between a cue and the next cue or shot change, default 2
    chain: true,                 // true (default) closes gaps, false keeps them
    minDurationFrames: 20,       // frames, default 20
));
$report->movedStarts;   // the cue starts that moved by one frame or more
$report->movedEnds;     // the cue ends that moved by one frame or more
```

An **in-time** is the start time of a cue. An **out-time** is the end time of a cue.

| Rule | Before, at 24 fps | After |
|:--- |:--- |:--- |
| An in-time up to `snapWindowFrames` frames after a shot change moves to the shot change. | shot change 62.500, cue starts 62.708 | starts 62.500 |
| An out-time up to `snapWindowFrames` frames before a shot change ends `minGapFrames` before it. | shot change 70.000, cue ends 69.750 | ends 69.917 |

- **Frames**: all cue times of the result fall on frames of `frameRate`, rounded to milliseconds.
- **Blocked moves**: a move does not happen when it makes a cue shorter than `minDurationFrames`. It also does not happen when it brings the cue closer than `minGapFrames` to the cue before or after it. A move that makes a short cue longer still happens.
- **Input**: `fromFfmpegLog()` reads the `pts_time:` values. `fromText()` reads one time per line, in seconds or as `hh:mm:ss.mmm`, and skips empty lines. Both return the times sorted, without duplicates.

### Closing gaps
Example at 24 fps with the default options: cue A ends at 10.000, cue B starts at 10.292. The gap is 7 frames. After `apply()`, A ends at 10.208, 2 frames before B.

- **Rule**: a gap closes when it is longer than `minGapFrames` and shorter than `snapWindowFrames` frames. No shot change may lie inside it.
- **Result**: the earlier cue ends `minGapFrames` frames before the next cue starts. The gap shrinks to `minGapFrames`, not to 0.
- **Without shot changes**: no gap holds a shot change, so only the length of the gap decides.
- **Off**: `chain: false` keeps all gaps.

## Dual subtitles
A dual subtitle shows two languages at the same time, for example for language learners. Most players show only one subtitle track, so both languages go into one file.

```php
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Subtitle;

$english = Subtitle::fromString(file_get_contents('movie.en.srt'));
$german  = Subtitle::fromString(file_get_contents('movie.de.srt'));

$dual = DualSubtitle::fromPair($english, $german);   // stack mode, the German line without a style
$dual = DualSubtitle::fromPair($english, $german, new DualSubtitleOptions(secondaryStyle: 'i'));
$dual = DualSubtitle::fromPair($english, $german, new DualSubtitleOptions(
    mode: DualSubtitleMode::TopBottom,              // English at the bottom, German at the top
    timeTolerance: 0.25,                            // seconds
    secondaryStyle: 'font color="#ffff00"',
    secondaryAlignment: Alignment::TopCenter,
));
```

| Mode | Result for `00:00:01.000 --> 00:00:04.000 Where are you going?` and `00:00:01.200 --> 00:00:03.900 Wohin gehst du?` | Formats |
|:--- |:--- |:--- |
| `stack`, the default | one cue from 1.000 s to 4.000 s with the lines `Where are you going?` and `<i>Wohin gehst du?</i>` with `secondaryStyle: 'i'` | all |
| `topBottom` | the English cue with `Alignment::BottomCenter`, and the German cue with `Alignment::TopCenter` from 1.000 s to 4.000 s | SubRip with `{\an8}`, WebVTT, ASS, TTML, iTT, EBU STL and SCC. The other formats do not write the alignment |

- **Stack**: each secondary cue joins the primary cue that it overlaps most. The joined cue spans from the earlier start to the later end. A cue without an overlap stays a cue of its own.
- **Top and bottom**: a secondary start or end time moves to the closest primary start or end time within `timeTolerance`. So the two languages appear and disappear together. A cue keeps its times when both would move to the same time.
- **Secondary style**: a core markup tag, such as `i` or `font color="#ffff00"`, around each secondary line. The WebVTT formatter writes a color as a class such as `<c.yellow>`, see [WebVTT](formats.md#webvtt).
- **Copied data**: the result is a new `Subtitle`. Metadata, comments and format data come from the primary subtitle. The secondary cues lose their identifiers and format data. The language becomes `en+de` when both subtitles have a language.
