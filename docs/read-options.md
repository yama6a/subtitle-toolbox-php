# Read options

`ReadOptions` holds the format-neutral settings of one read. The `load` and `fromString` functions of `Subtitle` take it, and so does `SubtitleParser::parse()`. A parser ignores the fields it does not use.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd,
    new ReadOptions(encoding: 'Windows-1252', lenient: true, formats: [new MicroDvdReadOptions(frameRate: 23.976)]));
$warnings = $subtitle->getParseWarnings();

$table = Subtitle::fromString(file_get_contents('lines.csv'), Format::Csv,
    new ReadOptions(formats: [new CsvReadOptions(delimiter: ';')]));
```

| Field | Default | Used by |
|:--- |:--- |:--- |
| `encoding` | null, UTF-8 | text formats. The encoding of input that is not UTF-8. A `TextEncoding` case or an iconv name such as `'CP1125'`. A UTF-16 or UTF-32 BOM wins. See [encodings.md](encodings.md) |
| `lenient` | false | skips or repairs a broken block and records a warning, see [lenient-parsing.md](lenient-parsing.md). For a cue that ends before it starts, see [that rule](lenient-parsing.md#cue-that-ends-before-it-starts) |
| `lastCueDuration` | 5.0 | seconds that a last cue without an end lasts |
| `formats` | `[]` | per-format classes, at most one object per class, see below |

- **Checks**: the constructor throws `InvalidArgumentException` for an unknown encoding, a negative `lastCueDuration`, 2 objects of one class in `formats` and an object in `formats` that is not a per-format read class. The per-format classes throw it for a frame rate of 0 or less, a negative `track` and an empty `language` or `languageClass`.
- **Last cue**: every format ends a last cue without an end `lastCueDuration` after its start. These readers use it: TMPlayer, SubViewer 1, LRC and SAMI. CSV and TSV rows without an end time use it. So do HTML and Podcasting 2.0 transcripts, and SCC captions that no command erases. PGS display sets, MKV text blocks without a duration and VobSub subtitles without a stop command use it too. A VobSub subtitle without a stop command also ends where the next starts.
- **Warnings**: `Subtitle::getParseWarnings()` returns the warnings of a lenient read. A subtitle that no parser read has none.

## Per-format classes

`ReadOptions::$formats` takes the settings that only some formats have. The classes are in `SubtitleToolbox\Parsers\Options`. Each parser uses the object of its own class and ignores the others. So one `ReadOptions` object can hold the settings for every format that detection may find:

```php
$options = new ReadOptions(formats: [new MicroDvdReadOptions(frameRate: 25), new CsvReadOptions(delimiter: ';')]);
foreach (['a.sub', 'b.csv', 'c.srt'] as $path) {
    $subtitles[] = Subtitle::loadAutoDetectFormat($path, $options);   // a.sub at 25 fps, b.csv with ';', c.srt with neither
}
```

A class that the table lists for several formats applies to each of them. For example, one `ChapterReadOptions` sets the media duration for all 4 chapter formats.

| Class | Formats | Field | Meaning |
|:--- |:--- |:--- |:--- |
| `CsvReadOptions` | CSV, TSV | `columns` | a `CsvColumns` layout. Null reads the header names |
| | | `delimiter` | `,`, `;` or a tab. Null detects it |
| | | `frameRate` | the frames per second of times in `hh:mm:ss:ff` |
| `SccReadOptions` | SCC | `channel` | 1 reads CC1 and CC3, 2 reads CC2 and CC4 |
| | | `rollUp` | `SccRollUp::Screen`, the default, gives one cue per screen of roll-up captions. `SccRollUp::Lines` gives one cue per row, see [formats.md](formats.md#scc) |
| `EbuStlReadOptions` | EBU STL | `subtractStartOfProgramme` | subtracts the TCP time code from every cue time |
| `TranscriptReadOptions` | Whisper, cloud speech-to-text JSON, YouTube timed text, Podcasting 2.0 transcript | `wordTimestamps` | word times as core markup, see [transcripts.md](transcripts.md) |
| | | `speakerVoices` | speakers as voice tags, for Whisper and cloud speech-to-text JSON only, see [text.md](text.md#speakers) |
| | | `keepSegments` | one cue per segment, also for a segment with one word. Podcasting 2.0 only |
| `ChapterReadOptions` | YouTube, Podcasting 2.0, FFmpeg and OGM chapters | `mediaDuration` | seconds, 0 or more, where a last chapter without its own end ends. An FFmpeg `END` or a Podcasting 2.0 `endTime` wins. A last chapter that starts after it, or null, ends at its own start |
| `MicroDvdReadOptions` | MicroDVD | `frameRate` | frames per second. It wins over a `{1}{1}<fps>` first line. Without either, strict mode throws and lenient mode uses 23.976 with a `ParseWarning` |
| `SamiReadOptions` | SAMI | `languageClass` | the SAMI class to read, such as `FRCC`. Null reads the first class of the STYLE block that a `<P>` uses |
| `VobSubReadOptions` | VobSub | `idx` | the content of the `.idx` file. The parser reads the `.sub` content. `Subtitle::load()` fills it from the `.idx` file |
| | | `track` | the track with this `index:` |
| | | `language` | the track with this `id:`. Without `track` and `language`, the parser reads the first track |
