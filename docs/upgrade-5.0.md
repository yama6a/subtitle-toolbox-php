# Upgrade from 4.x to 5.0

```sh
composer require yama6a/subtitle-toolbox-php:^5.0
```

5.0 needs the same PHP version and extensions as 4.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 5.x. 5.0 has no deprecation period. Each section shows the 4.x call and the 5.0 call side by side.

## Command name

TODO #960

## Cue order

| 4.x | 5.0 |
|:--- |:--- |
| `Subtitle::fromArray($array)` keeps the order of `$array["cues"]` | `Subtitle::fromArray($array)` sorts the cues by start time |
| `Subtitle::load("cues.json", Format::Json)` keeps the order of the file | `Subtitle::load("cues.json", Format::Json)` sorts the cues by start time |
| `$subtitle->reIndexCues();` | delete the call |
| `new ValidationRules(noUnsortedCues: true)` | delete the argument |
| `ValidationRule::NoUnsortedCues` | delete each use |

- A `Subtitle` always holds its cues in start order. Cues with the same start keep their order from the array or the file. Each comment moves with its cue.
- Library JSON with cues out of start order reads sorted in 5.0. 4.x wrote such files after an unsorted `fromArray()`.
- Example: a file holds a cue at 5 s, then a cue at 1 s. `subtitle-toolbox convert cues.json --to srt` now writes the 1 s cue first.
- A cue index from the array can point to another cue after the sort. Read the indexes from `getCues()`.
- `ValidationRules::structure()` checks `requireCues`, `noNegativeDuration` and `noOverlap`.

## Removed commands and aliases

| 4.x | 5.0 |
|:--- |:--- |
| `subtitle-toolbox retime movie.srt --shift 1.5 -o out.srt` | `subtitle-toolbox convert movie.srt --shift 1.5 -o out.srt` |

- `retime` is removed. Use `convert` with the same options and without `--to`.
- The output files are byte-equal.
- `retime` exits with code 2 as an unknown command.
- `retime` with no edit option exited with code 2. `convert` without an edit option copies the file.

Chapter format names:

| 4.x | 5.0 |
|:--- |:--- |
| `--to ytchapter` | `--to youtube-chapters` |
| `--to podcast` | `--to podcast-chapters` |
| `--to ogm` | `--to ogm-chapters` |

- `--from` and `--to` no longer take `ytchapter`, `podcast`, `ogm` and `ffmeta`. The old names exit with code 2 and the message `Unknown format`. The message points to `subtitle-toolbox formats`, which lists the valid names.
- `ffmeta` still works, because it is the file extension of `ffmeta-chapters`. Use `--to ffmeta-chapters` in scripts.

## Output file extension

| 4.x | 5.0 |
|:--- |:--- |
| `subtitle-toolbox sync a.srt --reference r.srt -o out.vtt` (writes SubRip into `out.vtt`) | `subtitle-toolbox sync a.srt --reference r.srt --to vtt -o out.vtt` |

- `sync`, `dual` and `translate` need `--to` when the `-o` extension names another writable format than the input format.
- Without `--to`, they exit with code 2 and the message names `--to`. `convert` already did this.
- The extension never picks the output format. `.bak` and the extension of the input format still work.
- For standard input, the file fails with exit code 3.

## Exceptions

Image cues without text now throw `UnwritableContentException`, like all other content that a format cannot hold. `ImageCueWithoutTextException` no longer exists.

| Old | New |
|:--- |:--- |
| `catch (ImageCueWithoutTextException $e)` | `catch (UnwritableContentException $e)` |
| error code 103 | error code 108 |

- Code 103 is retired and never used again.
- A catch of `UnwritableContentException` now also catches this error. Check the message if you handle the two cases differently.
- The message still names the cue, and says that the cue needs OCR or a bitmap format.

## Line wrapping of Chinese and Japanese

`wrapLines()` breaks Chinese and Japanese lines between 2 characters. 4.x broke only at spaces, so it kept such a line long. The call stays the same.

| Call | 4.x | 5.0 |
|:--- |:--- |:--- |
| `wrapLines(16)` on `我们明天早上八点在火车站见面，然后一起坐车去北京看望奶奶。` | 1 line of 29 characters | `我们明天早上八点在火车站见面，` and `然后一起坐车去北京看望奶奶。` |
| `unwrapLines()` on `我们明天早上八点在火车站见面，` and `然后一起坐车去北京看望奶奶。` | joins them with a space | joins them without a space |

- `--structure-wrap`, `mergeShortCues()` and `Resegmenter` with `ResegmentMode::SplitLong` measure lines as `wrapLines()` wraps them. So `mergeShortCues()` can join more Chinese and Japanese cues, and `SplitLong` splits fewer.
- Korean text and text with spaces keep their line breaks.
- [editing.md](editing.md#overlaps-short-cues-and-line-breaks) lists the break rules.

## Public API cleanup

These methods were public and marked `@internal`. 5.0 removes the ones in this table. Code that follows the docs does not call them.

| 4.x | 5.0 |
|:--- |:--- |
| `Subtitle::emptyCopy()` | removed, no replacement |
| `Subtitle::setLinesAndRemoveEmptied()` | removed, use `mapLines()`, which also removes cues that lose all text |
| `Subtitle::toUpperOrLower()` | removed, use `changeCase()` |
| `Subtitle::fromArrayLeavingOut()` | removed, use `fromArray()`, or read the JSON with `ReadOptions(lenient: true)` |
| `SubtitleCue::mapTimes()` | removed, use `setStart()`, `setEnd()` and `mapWordTimestamps()` |

- `Comment::withBeforeCueIndex()`, `SubtitleTrack::describe()`, `TesseractOcrEngine::isInstalled()`, `TimestampMap::isHeader()`, `TimestampMap::toHeader()`, `HlsSegmentOptions::fileName()` and `CueImage::isImageCue()` stay public and are marked `@internal`. `Subtitle::setFormat()`, `setSourceEncoding()`, `setParseWarnings()` and `replaceCues()` stay public and `@internal` too. All of them can change in any release.

## Renamed methods

TODO #969 #971

## Load and parse

TODO #970

## Options and results

| 4.x | 5.0 |
|:--- |:--- |
| `new CommonErrorOptions(unicodeEllipsis: true)` | `new CommonErrorOptions(ellipsis: EllipsisStyle::Unicode)` |
| `new CommonErrorOptions(ellipsis: false)` | `new CommonErrorOptions(ellipsis: null)` |
| `new SpeakerLabelOptions(to: SpeakerStyle::Prefix)` | `new SpeakerLabelOptions(style: SpeakerStyle::Prefix)` |
| `new SpeakerLabelOptions(readPrefixes: true, readUpperCaseOnly: false)` | `new SpeakerLabelOptions(speakerLabels: true, speakerLabelsUpperCaseOnly: false)` |
| `$report->getSegments()[1]['offset']` | `$report->segments[1]->offset` |
| `$cue = $image->toCue(new SubtitleCue(1, 2));` | `$cue = new SubtitleCue(1, 2);` and then `$image->applyTo($cue);` |
| `$subtitle->recognizeText(OcrEngineChooser::create(null, 'deu'));` | `$subtitle->recognizeText(OcrEngineChooser::create(), 'deu');` |
| `OcrEngineChooser::create(null, 'eng', '/opt/bin/tesseract')` | `OcrEngineChooser::create(null, '/opt/bin/tesseract')` |

- `EllipsisStyle` is in `SubtitleToolbox\Fixing`. Its cases are `ThreeDots` for `...`, the default, and `Unicode` for U+2026.
- `HearingImpairedOptions` keeps `speakerLabels` and `speakerLabelsUpperCaseOnly`. `SpeakerLabelOptions` now uses the same 2 names.
- `ReferenceSyncReport::$segments` is a list of `SyncSegment` objects with the fields `from`, `to`, `scale` and `offset`.
- `CueImage::applyTo()` returns nothing. It still changes the cue that you pass.
- `OcrEngineChooser::create()` makes Tesseract read English. Pass another language to `recognizeText()`, or build a `TesseractOcrEngine` with `TesseractOcrOptions::$language`.
- The CLI options are the same. The output of all these calls is the same.
