# Upgrade from 3.x to 4.0

```sh
composer require yama6a/subtitle-toolbox-php:^4.0
```

4.0 needs the same PHP version and extensions as 3.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 4.x. 4.0 has no deprecation period. Each section shows the 3.x call and the 4.0 call side by side.

## Alignment
`setAlignment()` wins over a stored WebVTT position and a stored TTML region. A cue keeps its stored position while it has the alignment that the parser read.

| Call | 3.x | 4.0 | To keep the 3.x result |
|:--- |:--- |:--- |:--- |
| `setAlignment(Alignment::BottomCenter)` on the WebVTT cue `00:00:01.000 --> 00:00:02.000 line:0`, then `toString(Format::WebVtt)` | `00:00:01.000 --> 00:00:02.000 line:0` | `00:00:01.000 --> 00:00:02.000` | `$cue->setFormatData('vtt', ['alignment' => 2] + $cue->findFormatData('vtt'))` |
| `setAlignment(Alignment::BottomCenter)` on the TTML `<p region="top">`, then `toString(Format::Ttml)` | `<p region="top">` | `<p region="bottomCenter">`, with a new `bottomCenter` region in the `<head>` | `$cue->setFormatData('ttml', ['alignment' => 2] + $cue->findFormatData('ttml'))` |

- **WebVTT**: the formatter drops the stored `line`, `position` and `align` settings and writes the settings of the new alignment. It keeps `size`, `vertical` and `region`.
- **TTML**: the formatter also writes `tts:textAlign` on the `<p>` when a style of the `<p>`, `<div>` or `<body>` sets another column.
- **Format data**: the `vtt` and `ttml` cue format data hold the key `alignment`, the alignment that the parser read. The JSON output of a WebVTT or TTML file holds it too. A cue whose alignment equals this value keeps its stored position. JSON from 3.x has no such key. `fromArray()` and the JSON reader then take the cue alignment as the stored value, so the cues keep their positions.

The `Alignment` enum replaces the alignment numbers from 1 to 9 and `null`.

| 3.x | 4.0 |
|:--- |:--- |
| `$cue->setAlignment(8)` | `$cue->setAlignment(Alignment::TopCenter)` |
| `$cue->setAlignment(null)` | `$cue->setAlignment(Alignment::BottomCenter)` |
| `$cue->setAlignment($number)` | `$cue->setAlignment(Alignment::from($number))` |
| `$cue->getAlignment() === 8` | `$cue->getAlignment() === Alignment::TopCenter` |
| `$cue->getAlignment() ?? 2` | `$cue->getAlignment()->value` |
| `new DualSubtitleOptions(secondaryAlignment: 7)` | `new DualSubtitleOptions(secondaryAlignment: Alignment::TopLeft)` |
| `SubtitleCue::DEFAULT_ALIGNMENT`, `SubtitleCue::TOP_CENTER_ALIGNMENT` | `Alignment::BottomCenter`, `Alignment::TopCenter` |

| 3.x number | 4.0 case |
|:--- |:--- |
| 1 | `Alignment::BottomLeft` |
| 2 or `null` | `Alignment::BottomCenter` |
| 3 | `Alignment::BottomRight` |
| 4 | `Alignment::MiddleLeft` |
| 5 | `Alignment::MiddleCenter` |
| 6 | `Alignment::MiddleRight` |
| 7 | `Alignment::TopLeft` |
| 8 | `Alignment::TopCenter` |
| 9 | `Alignment::TopRight` |

- **One default**: `getAlignment()` returns `Alignment::BottomCenter` for a cue without a position. 3.x returned `null` for it, and 2 for a cue with an explicit bottom center position.
- **Same numbers**: the value of each case is the 3.x number. JSON, the format data and the CLI option `--secondary-alignment` keep the numbers. `fromArray()` and the JSON reader accept 1 to 9 and `null`.
- **Invalid numbers**: `setAlignment()` and `DualSubtitleOptions` take only the enum. `Alignment::from(10)` throws `ValueError`. 3.x threw `InvalidArgumentException`.

| Call | 3.x | 4.0 | To keep the 3.x result |
|:--- |:--- |:--- |:--- |
| `toString(Format::Ass)` on the WebVTT cue `00:00:04.000 --> 00:00:06.500 align:center` | `{\an2}` before the text | no tag | no option |
| `toString(Format::Json)` or `toArray()` on the same cue | `alignment` 2 | `alignment` `null` | no option |

## Read and write options

| 3.x | 4.0 |
|:--- |:--- |
| `new ReadOptions(format: new MicroDvdReadOptions(frameRate: 25))` | `new ReadOptions(formats: [new MicroDvdReadOptions(frameRate: 25)])` |
| `new WriteOptions(format: new CsvWriteOptions(delimiter: ';'))` | `new WriteOptions(formats: [new CsvWriteOptions(delimiter: ';')])` |
| `$options->format` | `$options->formats`, a list |
| `catch (InvalidArgumentException $e)` around `fromStringAutoDetectFormat($srt, new ReadOptions(format: new MicroDvdReadOptions(frameRate: 25)))` | no exception. The SubRip parser ignores `MicroDvdReadOptions` |

- **List of classes**: `ReadOptions::$formats` and `WriteOptions::$formats` replace `$format`. Each takes at most one object per class. `format:` is removed.
- **Other formats**: each parser and formatter uses the object of its own class and ignores the others. In 3.x, the class of another format threw `InvalidArgumentException`.
- **Mixed input**: one `ReadOptions` object can hold the settings of every format that detection may find, for example `MicroDvdReadOptions` and `CsvReadOptions` for a batch of `.sub` and `.csv` files.
- **New errors**: 2 objects of one class throw `InvalidArgumentException`. So does a write class in `ReadOptions` or a read class in `WriteOptions`.

## Exceptions

| 3.x | 4.0 |
|:--- |:--- |
| `catch (InvalidParserException $e)` | `catch (UnsupportedFormatException $e)` |
| `catch (InvalidFormatterException $e)` | `catch (UnsupportedFormatException $e)` |
| `$e->getCode() === 101` for `toString(Format::Whisper)` or `save('out.unknown')` | `$e->getCode() === 102` |
| `catch (\InvalidArgumentException $e)` around `toString(Format::Scc)` for a cue with 5 lines | `catch (UnwritableContentException $e)` or `catch (\RuntimeException $e)` |
| `catch (InvalidArgumentException $e)` around `splitCue(9, 1.5, 1)` or `joinCues(0, 9)` on 3 cues | `catch (CueNotFoundException $e)` |

- **One class for formats**: `UnsupportedFormatException` replaces `InvalidParserException` and `InvalidFormatterException`. It keeps code 102. No class has code 101.
- **Unknown format**: `UnknownFormatException` keeps code 106 and extends `UnsupportedFormatException`.
- **Unwritable content**: `UnwritableContentException` keeps code 108 and extends `\RuntimeException`. `catch (\InvalidArgumentException $e)` and `catch (InvalidArgumentException $e)` no longer catch it.
- **Missing cue**: `removeCue()`, `splitCue()` and `joinCues()` throw `CueNotFoundException` for an index without a cue. `joinCues()` checks both indexes before their order, so `joinCues(9, 2)` on 3 cues throws `CueNotFoundException`.
- **Final class**: `InvalidArgumentException` is `final`. `UnsupportedFormatException` is the only exception class that is not `final`.

## Sync

`Subtitle::syncByTwoPoints()` is removed. `syncByPoints()` with 2 `SyncPoint` objects gives the same result.

```php
// 3.x
$subtitle->syncByTwoPoints(10, 12, 6260, 6005);
// 4.0
$subtitle->syncByPoints([new SyncPoint(oldSeconds: 10, newSeconds: 12), new SyncPoint(oldSeconds: 6260, newSeconds: 6005)]);
```

`syncByTwoPoints()` took its 2 points in either order. `syncByPoints()` needs the old and new times to increase from point to point.

## CLI diff

`diff` applies `--from`, `--track` and `--encoding` to both files. `--new-from` and `--new-track` replace `--from` and `--track` for the new file. `--from2` and `--track2` are removed.

```sh
# 3.x
subtitle-toolbox diff a.json b.json --from deepgram --from2 deepgram
subtitle-toolbox diff old.mkv new.mkv --track 3 --track2 8
# 4.0
subtitle-toolbox diff a.json b.json --from deepgram
subtitle-toolbox diff old.mkv new.mkv --track 3 --new-track 8
```

| Call | 3.x | 4.0 | 4.0 call for the 3.x result |
|:--- |:--- |:--- |:--- |
| `diff a.srt b.vtt --from srt` | exit code 1, `--from` reads `a.srt` only | exit code 3, `--from` reads `b.vtt` as SubRip too | `diff a.srt b.vtt --from srt --new-from vtt` |
| `diff text_tracks.mkv seek_head.mkv --track 3`, where `seek_head.mkv` has only track 2 | exit code 1, `--track` reads `text_tracks.mkv` only | exit code 3, `seek_head.mkv` has no track 3 | `diff text_tracks.mkv seek_head.mkv --track 3 --new-track 2` |

`--track` applies to the new file only when the new file is an MKV, WebM or MP4 file. For `diff old.mkv new.srt --track 3`, `diff` reads track 3 of `old.mkv` and ignores `--track` for `new.srt`. 
