# Format detection

File extensions do not identify a format. For example, a `.sub` file can be MicroDVD, MPSub or SubViewer. So `Format::detect()` and `Subtitle::fromStringAutoDetectFormat()` read the start of the content.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

Format::detect(file_get_contents('upload.sub'));                                    // Format::MicroDvd, or null for an unknown format
$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('upload.sub'));  // throws UnknownFormatException for an unknown format
$subtitle = Subtitle::loadAutoDetectFormat('upload.sub');                           // also uses the extension, see formats.md#load-and-save
Format::tryFromPath('upload.sub');                                                     // Format::MicroDvd, from the extension only
```

Detection ignores a UTF-8 BOM and leading blank lines. It tries only formats whose `isAutoDetected()` is true.

Content that starts with `{` and is a JSON object goes to the JSON checks. Detection reads the top-level keys in this order and takes the first match:

| Order | Format | Keys |
|:--- |:--- |:--- |
| 1 | `Json` | a numeric `"version"` and a `"cues"` list |
| 2 | `PodcastTranscript` | a `"segments"` list whose first segment has `"startTime"` and `"body"` |
| 3 | `Whisper` | a `"segments"` or `"transcription"` list |
| 4 | `YouTubeTimedText` | an `"events"` list whose first event has `"tStartMs"` |

A JSON object that matches none of these gives null. Invalid JSON gives null too, unless a text signature matches it, such as MicroDVD `{24}{72}`.

Other content goes to the signatures of the text and binary formats. Detection checks them in this order and takes the first match:

| Order | Format | Signature |
|:--- |:--- |:--- |
| 1 | `WebVtt` | `WEBVTT` |
| 2 | `Ttml` | `<tt` after an optional XML declaration, comments and DOCTYPE |
| 3 | `Sami` | `<SAMI>` |
| 4 | `Ass` | `[Script Info]`, for ASS and SSA |
| 5 | `MpSub` | a first line such as `TITLE=`, and a `FORMAT=` line |
| 6 | `MicroDvd` | `{24}{72}` |
| 7 | `SubRip` | `1`, then `00:00:01,000 -->`. The cue number can come after at most 5 lines of other text. A first line such as `00:00:01,000 -->` matches without a cue number. |
| 8 | `Sbv` | `0:00:01.500,0:00:04.000` |
| 9 | `SubViewer` | `******** START SCRIPT ********`, `[INFORMATION]` or `00:00:01.50,00:00:04.00` |
| 10 | `Lyrics` | `[ti:Title]` or `[00:12.00]`, and at least one timestamp line |
| 11 | `Pgs` | the bytes `PG`, then a known segment type |
| 12 | `EbuStl` | a 3-digit code page such as `850`, then `STL25.01` or `STL30.01` |
| 13 | `Scc` | `Scenarist_SCC V1.0` |
| 14 | `YouTubeTimedText` | a `<timedtext>` or `<transcript>` root |
| 15 | `Mpl2` | `[12][45]` |
| 16 | `TmPlayer` | `00:00:01:`, `0:00:01=` or `00:00:01,1=`, but not an `hh:mm:ss:ff` frame timecode such as `00:00:01:10,` |
| 17 | `HtmlTranscript` | a tag at the start, and a `<cite>` and a `<time>` element |

- **Order**: a format with a more specific signature comes first. A WebVTT file without its `WEBVTT` line but with cue numbers looks like SubRip, so it detects as SubRip.
- **SubRip without cue numbers**: read such a file in lenient mode. Strict mode throws `ParsingException` for a missing cue number and for text before the first cue. A timing line with a dot, such as `00:00:01.000 -->`, needs a cue number in the first line.
- **`.sub` files**: SBV has three digits after the dot, SubViewer 2 has two.
- **MicroDVD**: detection does not find the frame rate. `fromStringAutoDetectFormat()` throws `ParsingException` for a MicroDVD file without a `{1}{1}<fps>` first line. Then read it in lenient mode for 23.976 fps, or pass the frame rate: `Subtitle::fromString($content, Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(frameRate: 23.976)]))`.
- **iTT**: an iTT file detects as `Format::Ttml`. Pass `Format::Itt` to keep the iTT format data.
- **Frame timecodes**: Spruce STL and CSV files that start with an `hh:mm:ss:ff` timecode give null, not TMPlayer.
- **No signature**: CSV and TSV. Pass `Format::Csv` or `Format::Tsv`, see [formats.md](formats.md#csv-and-tsv). VobSub needs its `.idx` file, see [ocr.md](ocr.md#vobsub).
- **Not detected**: chapters and cloud speech-to-text JSON look like other formats. `Format::detect()` returns null for them, and `isAutoDetected()` is false. Pass the format, for example `Subtitle::fromString($json, Format::Deepgram)`. `Format::tryFromPath()` finds only FFmpeg metadata, by its `.ffmeta` extension. The other formats share `.json` or `.txt` with formats that come first.
