# Comparing two subtitles

A translator delivers `episode1_v2.srt`. `SubtitleDiff` lists what changed against `episode1_v1.srt`. It pairs cues by time and text, not by cue number, so one added cue does not shift the rest.

```php
use SubtitleToolbox\Diff\CueDifferenceKind;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;

$differences = SubtitleDiff::compare($v1, $v2);
$differences[0]->kind;       // CueDifferenceKind::TextChanged, with the value "text changed"
$differences[0]->oldIndex;   // 11, the key in $v1->getCues(), null for an added cue
$differences[0]->newIndex;   // 11, the key in $v2->getCues(), null for a removed cue
$differences[0]->oldCue;     // the SubtitleCue in $v1
echo SubtitleDiff::toText($differences);

SubtitleDiff::compare($v1, $v2, new SubtitleDiffOptions(
    timeTolerance: 0.04,      // seconds, default 0.001. A larger difference is a timing change
    ignoreFormatting: true,   // compares the text without tags, with entities decoded
    ignoreWhitespace: true,   // compares the text without spaces, tabs and line breaks
    textOnly: true,           // reports no timing changes
));
SubtitleDiff::isEqual($v1, $v2);  // true when compare() finds no difference
```

`toText()` writes one block per difference, with cue numbers that start at 1:

```
text changed: old cue 12, new cue 12
- 00:00:39.000 --> 00:00:40.500
  I'll be their.
+ 00:00:39.000 --> 00:00:40.500
  I'll be there.
```

- **Kinds**: `added`, `removed`, `text changed`, `timing changed` and `text and timing changed`. Pairs without a change are not in the list.
- **Pairing**: two cues pair when their text is the same or nearly the same. They also pair when they overlap for at least half of the shorter cue. Nearly the same means that at most 30% of the longer text differs. The pairs stay in order. The idea comes from the Compare tool of [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit/blob/main/docs/features/compare.md).
- **Split cues**: one half of a split cue pairs with the old cue as a text change. The other half is an added cue.
- **Moved cues**: a cue that moves past other cues is removed in one place and added in the other.
- **Forced flag**: a cue whose [forced flag](subtitle.md#forced-cues) changed is a text change. `toText()` writes `forced` after the times of a forced cue.
- **Speed**: 2,000 cues against 2,000 cues take well below 1 s. 10,000 cues with one cue over the whole file take about 0.2 s.
- **Limits**: a translation and its source have few cues with the same text. A run without the same text can have more than 40,000 cue pairs, for example 250 cues against 250 cues. In such a run, only cues that overlap in time pair. A cue with nearly the same text that ends before the old cue starts, or starts after it ends, is removed and added. A text that repeats more than 50 times, such as `[MUSIC]`, pairs by text only with the 50 cues of that text nearest in cue number.
