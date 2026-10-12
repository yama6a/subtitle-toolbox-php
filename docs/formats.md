# Subtitle formats

The [main README](../README.md#supported-formats) lists every format with its extensions. This page holds the details of each text subtitle format. Other formats have their own pages:

- Bitmap formats PGS and VobSub: [ocr.md](ocr.md)
- Speech-to-text JSON, YouTube timed text, podcast transcripts and plain text: [transcripts.md](transcripts.md)
- Chapter lists: [chapters.md](chapters.md)
- The JSON of this library: [json.md](json.md)
- Subtitle tracks in MKV, WebM and MP4 files: [mkv.md](mkv.md)

## The Format enum
The enum `Format` names each format. Its value is the format name of the command line tool.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($content, Format::MicroDvd);
$subtitle = Subtitle::fromStringAutoDetectFormat($content);   // see detection.md
$vtt      = $subtitle->toString(Format::WebVtt);

Format::from('srt');                          // Format::SubRip, for a name from user input
Format::fromPath('movie.sub');                // Format::MicroDvd
Format::Ass->extensions();                    // ['ass', 'ssa'], the first one for new files
Format::VobSub->canWrite();                   // false
Format::PlainText->canRead();                 // false
Format::FfMetadataChapters->isAutoDetected(); // false
```

- **Shared extensions**: when two formats share an extension, the earlier case owns it. So `fromPath()` returns `Format::MicroDvd` for `.sub`, `Format::Json` for `.json` and `Format::PlainText` for `.txt`.
- **Parser and formatter classes**: the classes in `Parsers` and `Formatters` are public. `ReadOptions` holds the format-neutral read settings, for example `new ReadOptions(lenient: true)`. A class in `Parsers\Options` holds the settings of one format, for example `new MicroDvdReadOptions(frameRate: 23.976)`. See [read-options.md](read-options.md).
- **Time limit**: the parsers reject a time of 100,000 hours or more in every form. Examples are `99999999999999999999:00:04.000`, a JSON time of `1e20` seconds, a MicroDVD frame number of 13 digits and a word timestamp. The limit also applies to a sum, such as a time plus the SubViewer `[DELAY]` or a TTML `begin` plus the `begin` of its `div`. Strict mode throws `ParsingException`, and lenient mode skips the cue with a warning. The AssemblyAI, Amazon Transcribe, Deepgram and Google parsers skip only the word when a word time is too large. The YouTube chapter parser skips such a line, and the other chapter parsers throw.

## Load and save
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

Subtitle::load('movie.srt', Format::SubRip)->save('movie.vtt');
Subtitle::loadAutoDetectFormat('movie.srt')->save('movie.vtt');
Subtitle::load('movie.sub', Format::MicroDvd, new ReadOptions(encoding: 'Windows-1252', formats: [new MicroDvdReadOptions(frameRate: 23.976)]))->save('movie.srt');
Subtitle::load('movie.idx', Format::VobSub, new ReadOptions(formats: [new VobSubReadOptions(language: 'de')]));
Subtitle::load('call.json', Format::Deepgram, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]));
Subtitle::loadTrack('/media/movie.mkv', 3);           // see mkv.md

$subtitle = Subtitle::load('movie.srt', Format::SubRip);
$subtitle->getFormat();                               // Format::SubRip, the format that the load call read
$subtitle->save('movie.txt', Format::WebVtt);         // the format argument wins over the extension
```

- **`load()`**: reads the file in the given format and never guesses. For VobSub, pass the `.idx` or the `.sub` file. The other file must lie next to it. A path without extension, such as `d/movie`, is the `.sub` file, and `d/movie.idx` must exist. Otherwise `load()` throws `InvalidArgumentException`.
- **`loadAutoDetectFormat()`**: tries only formats whose `isAutoDetected()` is true. It reads the format that [detection](detection.md) finds in the content. An iTT file with the `.itt` extension reads as iTT, not TTML.
- **Extension fallback**: when detection finds nothing, `loadAutoDetectFormat()` takes the format of the extension, for example `.tsv`. It skips an extension that a format without detection also uses, such as `.json` and `.txt`. Then it throws `UnknownFormatException`.
- **Control characters**: the text parsers drop Ctrl-Z characters at the end of a file, as DOS editors write them. They remove the other C0 control characters except tab and the line endings, and DEL. Lenient mode warns once per file for a removed character. A NUL throws `ParsingException` with its line in strict mode, because it marks UTF-16 or UTF-32 read with the wrong encoding. Lenient mode removes it with the same warning. The WebVTT parsers replace NUL with U+FFFD in both modes, as the WebVTT spec says. Format characters such as U+200E and U+200D stay.
- **Empty files**: `loadAutoDetectFormat()` reads a file that is empty, or holds only a BOM and whitespace, as an empty subtitle in the format of its extension. The extension fallback rules apply. `fromStringAutoDetectFormat()` has no extension, so it throws `UnknownFormatException` for empty content.
- **Empty file in every format**: a text file with only white space or a BOM gives an empty subtitle in every format and mode, also WebVTT without `WEBVTT`.
- **No cues**: a header without cues is valid, such as `WEBVTT` alone or LRC ID tags alone. Text that gives no cue throws `ParsingException` with its line, such as LRC lines without time tags. Lenient mode returns an empty subtitle with 1 warning.
- **Chapters and cloud speech-to-text JSON**: they load only with `load()` and their format.
- **MKV, WebM and MP4**: `load()` throws for them. `loadAutoDetectFormat()` reads a file with exactly 1 subtitle track and throws with the track list for other files.
- **`getFormat()`**: null for a subtitle from `new Subtitle()` or `fromArray()`. For an MKV or MP4 track, it is the format of the codec, for example `Format::SubRip`.
- **`findSourceEncoding()`**: the encoding that the load call read the text from, for example `Windows-1252`. See [encodings.md](encodings.md#order-of-the-checks).
- **`save()`**: writes the format argument. Without it, `save()` writes the format of the extension. It throws `UnsupportedFormatException` for an unknown extension.
- **Frame rate**: MicroDVD output takes the frame rate from `MicroDvdWriteOptions::$frameRate`. Without it, the frame rate comes from a MicroDVD input. iTT output takes it from `IttWriteOptions::$frameRate`. Without it, the frame rate comes from an iTT input. Without either, `toString()` and `save()` throw `InvalidArgumentException`.
- **CSV and TSV**: TSV output has tabs. CSV output from a TSV input has commas. A `CsvWriteOptions::$delimiter` wins.

## Write options
`WriteOptions` holds the write settings for all formats. Its `format` field takes the options class of one format, such as `MicroDvdWriteOptions`.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\WriteOptions;

$subtitle->toString(Format::SubRip, new WriteOptions(
    lineEnding: LineEnding::Crlf,    // LineEnding::Lf (default) or LineEnding::Crlf
    bom: false,                      // true adds a UTF-8 BOM, false removes it, null (default) keeps the rule of the format
    stripTags: true,                 // no tags in the output
    skipImageCues: true,             // drops image cues without text
));
$subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 23.976)]));
```

- **`lineEnding` and `bom`**: EBU STL and PGS ignore them.
- **`stripTags`**: only ASS, EBU STL, iTT, MicroDVD, SAMI, SubRip, TTML and WebVTT output read it.
- **`skipImageCues`**: `toString()` and `save()` read it, not a formatter. PGS and JSON output keep image cues and ignore it.

| Options class | Format | Fields |
|:--- |:--- |:--- |
| `AssWriteOptions` | ASS | `karaokeTag`, `style` |
| `CsvWriteOptions` | CSV, TSV | `delimiter`, `timeFormat`, `frameRate`, `secondText`, `secondTextHeader`, `escapeFormulas` |
| `EbuStlWriteOptions` | EBU STL | `frameRate` |
| `HtmlTranscriptWriteOptions` | HTML transcript | `paragraphGap` |
| `IttWriteOptions` | iTT | `frameRate` |
| `JsonWriteOptions` | JSON | `prettyPrint`, `withFormatData` |
| `MicroDvdWriteOptions` | MicroDVD | `frameRate`, `writeFrameRateLine` |
| `MpSubWriteOptions` | MPSub | `frameRate` |
| `PlainTextWriteOptions` | plain text | `joinLines`, `joinCues`, `paragraphGap`, `withTimes` |
| `PodcastTranscriptWriteOptions` | Podcasting 2.0 transcript | `wordSegments`, `prettyPrint` |
| `SccWriteOptions` | SCC | `dropFrame`, `fit` |
| `SubViewerWriteOptions` | SubViewer | `version` |

- **Line endings**: every formatter writes LF by default.
- **BOM**: ASS, CSV, TSV, LRC, MPSub, SubRip and WebVTT write a UTF-8 BOM by default. The other formatters do not.
- **Strip all tags**: ASS, EBU STL, iTT, MicroDVD, SAMI, SubRip, TTML and WebVTT read `stripTags`.
- **Ruby**: WebVTT keeps ruby. JSON keeps the text as it is. Every other text formatter writes `<ruby>漢<rt>kan</rt></ruby>` as `漢 (kan)`, with or without `stripTags`.
- **Image cues**: see [ocr.md](ocr.md#image-cues).
- **Negative times**: `new SubtitleCue(-0.5, 1)` and `setStart(-0.5)` keep a negative time. Every formatter except JSON writes it as 0.
- **Precedence**: a field that you set wins over the format data of the subtitle. For example, `IttWriteOptions(frameRate: 25)` wins over the frame rate that `IttParser` stored. A field left at `null` takes the stored value, for example `MicroDvdWriteOptions::$frameRate` with a MicroDVD input.
- **Frame rate**: every `frameRate` field is a `float`. Each format checks the values it can write, for example 25 or 30 for EBU STL.
- **Several formats**: `WriteOptions::$formats` takes at most one object per class. Each formatter uses the object of its own class and ignores the others. So `new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25), new CsvWriteOptions(delimiter: ';')])` works for every output format.
- **Errors**: 2 objects of one class in `formats` throw `InvalidArgumentException`. Each options class checks its values when you create it, so `new CsvWriteOptions(delimiter: '|')` throws at once.

## ASS and SSA
`AssParser` reads ASS v4.00+ and SSA v4.00. `AssFormatter` writes the version that the parser read, or ASS for cues from other formats.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssKaraokeTag;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('episode.ass'), Format::Ass);
$subtitle->findFormatData('ass')['scriptInfo']['PlayResX'];     // '1920'
$subtitle->getCues()[0]->findFormatData('ass')['fields'];       // ['Layer' => '0', 'Style' => 'Default', ...]
$subtitle->toString(Format::Ass);
$subtitle->toString(Format::Ass, new WriteOptions(formats: [new AssWriteOptions(karaokeTag: AssKaraokeTag::Fill)]));   // \kf
$subtitle->toString(Format::Ass, new WriteOptions(formats: [new AssWriteOptions(style: 'Fontname=Roboto,Fontsize=48')]));
```

| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| `{\b1}`, `{\i1}`, `{\u1}`, `{\s1}`, their `0` forms and `\r` | `<b>`, `<i>`, `<u>`, `<s>` and their closing tags | the same override tags |
| `{\c&H0000FF&}` or `{\1c&H0000FF&}`, color as BGR | `<font color="#ff0000">` | `{\c&H0000FF&}`, `{\c}` at `</font>` |
| `{\an8}`, SSA `{\a6}` | `Alignment::TopCenter`. The first tag wins. | `{\an8}` in ASS, `{\a6}` in SSA. Nothing when the style has the same alignment. |
| Style `Thoughts` with `Italic` `-1` and `Alignment` 8 | `<i>` around the text, `Alignment::TopCenter` | no tag for what the style already sets. `{\i0}` or `{\an2}` where the cue differs from its style. |
| Name field `Fred` | `<v Fred>` at the start of the first line | the Name field, only the first speaker of a cue |
| `{\k50}`, `{\kf50}`, `{\K50}`, `{\ko50}` in centiseconds | a word timestamp at the start time of each syllable | `{\k}`, or the tag of `AssWriteOptions::$karaokeTag`. The last syllable lasts until the cue end. |
| `\N`, `\h` | a new line, U+00A0 | `\N`, `\h` |
| `\n` | a space, or a new line with `WrapStyle: 2` | `\N` |
| `Comment:` event, `Title:` | `getComments()`, the `title` metadata | the stored `Comment:` event with the same text, `Title:` |

- **Format data**: ASS and SSA both use the key `ass`. The subtitle keeps `[Script Info]`, the styles, the `Format:` lines, the section order, `Comment:` events and other sections such as `[Fonts]` and `[Graphics]`. Each cue keeps its event fields and its original `Text` field.
- **Unchanged cues**: a cue can keep the lines and the alignment that the parser gave it. The formatter then writes the original `Text` field. So tags such as `\pos`, `\fad` and `\t` survive an ASS round trip and a retiming.
- **Styles**: the `Bold`, `Italic`, `Underline` and `StrikeOut` fields of the event's style become `<b>`, `<i>`, `<u>` and `<s>` around the text, for the values `-1` and `1`. A style `Alignment` other than 2 becomes the cue alignment when the text has no `\an` or `\a` tag. Inline tags such as `{\i0}` override the style for their span. `\r` goes back to the event's style, and `\rName` switches to the style `Name`. An unknown style name falls back to `Default`, as in libass. Colors and fonts of a style do not change the core markup.
- **Changed cues**: the formatter writes the text from the [core markup](markup.md). Other override tags are lost.
- **Karaoke tags**: `AssKaraokeTag::Instant` writes `\k`, the default. `AssKaraokeTag::Fill` writes `\kf`, `AssKaraokeTag::Outline` writes `\ko`. `\kf` fills each syllable from left to right in Aegisub and libass. `\ko` hides the outline of a syllable until its time starts. The option applies only to cues that the formatter writes from the core markup.
- **Default style**: `AssWriteOptions::$style` changes fields of the `Default` style for burn-in. It takes comma-separated `Field=Value` pairs with the field names of the `[V4+ Styles]` `Format:` line, in any case, as FFmpeg `force_style` does. For example, `'Fontname=Roboto,Fontsize=48,Outline=2'` gives `Style: Default,Roboto,48,` and keeps the other fields. Other styles stay the same. A file without a `Default` style gets one. The new values set the look of every cue without its own tag. For example, with `Italic=-1` a SubRip cue without `<i>` comes out italic, with no `{\i0}`. The formatter writes the cue tags against the `Default` style as it was before the option. An unknown field or a pair without `=` throws `InvalidArgumentException`. A field that the `Format:` line of the file lacks throws `UnwritableContentException`, for example `Underline` in SSA.
- **Limits**: other override tags, `{...}` notes and `\p1` drawings are not cue text. An event that holds only a drawing becomes a cue without lines. Events come out in time order.
- **Output**: times in centiseconds. A cue from another format gets style `Default`. A subtitle from another format gets the minimal header that FFmpeg writes.

## CSV and TSV
Translators, reviewers and dubbing studios work in Excel or Google Sheets. `CsvParser` and `CsvFormatter` read and write subtitles as CSV and TSV tables, with RFC 4180 quoting.

```csv
start,end,speaker,text
00:00:01.000,00:00:04.000,Anna,Where are you going?
00:00:04.500,00:00:06.000,Ben,"Home.
Now."
```

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.csv'), Format::Csv);   // headers start, end, text, ...
$columns  = new CsvColumns(start: 'Start TC', text: 'Text', speaker: 'Character');
$subtitle = Subtitle::fromString(file_get_contents('dubbing-script.csv'), Format::Csv, new ReadOptions(formats: [new CsvReadOptions($columns, frameRate: 25)]));
$subtitle = Subtitle::fromString($tsv, Format::Tsv, new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: 0, end: 1, text: 2, header: false), "\t")]));

$csv = $english->toString(Format::Csv, new WriteOptions(formats: [new CsvWriteOptions(
    delimiter: ';',                  // Excel in German and French locales
    secondText: $german,             // a second text column, aligned by time
    secondTextHeader: 'text (de)',   // default 'text2'
)]));
```

| Column role | Parser | Formatter |
|:--- |:--- |:--- |
| `start`, `end` | the cue times | the cue times |
| `duration` | the end is start plus duration | the end minus the start |
| `text` | the cue lines, one per line break in the cell | the text without tags and entities, lines joined by a line break |
| `speaker` | `<v Name>` at the start of the first line | the name of the leading `<v>` tag |
| `identifier` | the cue identifier | the cue identifier |
| any other column | `findFormatData('csv')['columns']` of the cue, by header name | the same cell |

- **Column mapping**: `CsvColumns` maps each role to a header name or to a 0-based column index. Header names match without case. A role without a mapping uses the header with its own name, such as `start`, when the table has one.
- **Header synonyms**: without `CsvColumns`, a table that lacks a `start` or a `text` header uses header synonyms. Then a role whose name no header has takes the first header synonym that the table has. The match ignores case, spaces, underscores and hyphens. A `ParseWarning` with action `repaired` names the chosen columns, also in strict mode. A table with both `start` and `text` headers uses no synonyms for any role. So in `start,end,text,name`, the `name` column stays an extra column and gives no speaker. With `CsvColumns`, the parser uses no synonyms.

  | Role | Synonyms, in order |
  |:--- |:--- |
  | `start` | `begin`, `in`, `start time`, `start tc`, `timecode`, `tc in` |
  | `end` | `out`, `stop`, `end time`, `end tc`, `tc out` |
  | `duration` | `length` |
  | `speaker` | `name`, `character` |
  | `text` | `subtitle`, `caption`, `dialogue`, `transcript` |
- **Required columns**: a table without `start` or `text` throws `ParsingException`. So does a mapped header that the table lacks. `header: false` needs a column index for each mapped role, and at least for `start` and `text`.
- **Column limit**: a table has at most 1,000 columns. For a wider table, `CsvParser` throws `ParsingException` with the line of the first wide row, also in lenient mode.
- **Delimiter**: `,`, `;` or a tab. Without `CsvReadOptions::$delimiter`, the parser takes the one that occurs most often in the first line that has one. A comma wins a tie.
- **Times**: seconds such as `62.5`, `00:01:02.500`, `00:01:02,500`, `01:02.500` (minutes and seconds), `0:06` and `00:00:1` (one digit of seconds), and `00:01:02:12` with frames. Frames need `frameRate`. The parser does not guess it.
- **Times in milliseconds**: openai-whisper and whisper.cpp write times as whole milliseconds, such as `2340` for 2.34 s. The parser reads every time as milliseconds when each time is a whole number and the median cue duration is 100 or more as seconds. A `ParseWarning` with action `repaired` says so, also in strict mode. A table of whole seconds with cues of a few seconds, such as `1,4,Hello`, keeps reading as seconds.
- **Output times**: the formatter writes the format of the first parsed start time. Without a parsed time, it writes `hh:mm:ss.mmm`. `CsvWriteOptions::$timeFormat` takes a case of the enum `CsvTimeFormat`, for example `CsvTimeFormat::Comma`. `CsvTimeFormat::Frames` writes `hh:mm:ss:ff` and needs `CsvWriteOptions::$frameRate` or a parsed frame rate. `CsvTimeFormat::Milliseconds` writes whole milliseconds, such as `2340`.
- **Stored values**: the `csv` format data keeps `delimiter` and `timeFormat`, and `quotedColumns`, the 0-based indexes of the columns quoted in every row, when the table has such a column. `delimiter` is `,`, `;` or a tab. `timeFormat` is a value of `CsvTimeFormat`: `seconds`, `hh:mm:ss.mmm`, `hh:mm:ss,mmm`, `hh:mm:ss:ff` or `milliseconds`. `setFormatData()` throws `InvalidArgumentException` for another value, such as `|` or `hh:mm:ss;fff`. `fromArray()` and the JSON reader throw `ParsingException`. Each message names the field, for example `formatData.csv.delimiter`.
- **No end column**: a cue without an end time ends at the next later start. The last such cue lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **Layout**: a subtitle from `CsvParser` keeps its columns, header names, delimiter and time format. So an unchanged table comes out byte for byte. A column that the table quotes in every row after the header, such as the text of whisper.cpp, stays quoted. The parser trims the cue lines, so the space at the start of each whisper.cpp text does not come back. A subtitle from another format gets the columns `start`, `end` and `text`. `identifier` comes first when a cue has one. `speaker` comes before `text` when a cue has a `<v>` tag.
- **Bilingual table**: `secondText` adds a column after `text`. Each row gets the cue of the second subtitle that overlaps the row most. When one second cue is the best match of several rows, only the first of them gets it.
- **Output**: a UTF-8 BOM by default, because Excel needs it to read UTF-8. `WriteOptions(bom: false)` leaves it out. `lineEnding` ends the rows. A line break inside a cell stays LF, as Excel writes it.
- **Formula injection**: `escapeFormulas: true` puts `'` before a cell that starts with `=`, `+`, `-` or `@`. Then a spreadsheet does not run the cell as a formula. It is off by default, because dialogue lines start with `-` and the option changes them.
- **Detection**: a CSV file has no signature, so pass `Format::Csv` or `Format::Tsv`. The command line tool reads `.csv` and `.tsv` files by their extension.

## EBU STL
EBU STL is the binary exchange format of European broadcasters, from [EBU Tech 3264](https://tech.ebu.ch/docs/tech/tech3264.pdf). Pass the bytes of the file unchanged. A file starts with one GSI (General Subtitle Information) block of 1,024 bytes. Then each subtitle has one or more TTI (Text and Timing Information) blocks of 128 bytes.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Parsers\Options\EbuStlReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('news.stl'));  // detects EBU STL
$subtitle = Subtitle::fromString(file_get_contents('news.stl'), Format::EbuStl,
    new ReadOptions(formats: [new EbuStlReadOptions(subtractStartOfProgramme: true)])); // cue times minus the start of programme
$subtitle->findFormatData('stl')['gsi']['TCP'];                                   // '10000000'
$subtitle->toString(Format::EbuStl, new WriteOptions(formats: [new EbuStlWriteOptions(frameRate: 30)]));  // 25 or 30
```

- **Times**: the disk format code `STL25.01` or `STL30.01` sets the frame rate. By default, the parser keeps the time codes of the file.
- **Start of programme**: `EbuStlReadOptions(subtractStartOfProgramme: true)` subtracts the TCP time code, for example `10:00:00:00`. A time before it becomes 0. The formatter adds TCP again.
- **Characters**: the parser reads the character code tables 00 (ISO 6937) and 01 to 04 (ISO 8859-5, -6, -7 and -8). The formatter writes `?` for a character outside the table.
- **Styles**: italics, underline and the 8 teletext colors become `<i>`, `<u>` and `<font color>`, and back. White gives no tag. The formatter drops other colors. A teletext row resets the styles, so the formatter repeats a style that spans rows at the start of each row. For example, `<i>` around 2 lines makes both rows italic.
- **Blocks**: the parser joins the TTI blocks of one subtitle and skips user data blocks. A subtitle with the comment flag becomes a comment. The formatter splits text over the 112 bytes of one TTI text field into extension blocks.
- **Alignment**: the justification code gives the column. The vertical position gives the row: the top, middle or bottom third of the rows.
- **Metadata**: the title is the OPT field. The language is the LC field, for example `09` is `en`.
- **Format data**: the subtitle keeps the GSI fields by name in `gsi`, for example `DSC` and `TCP`. Each cue keeps its subtitle group, cumulative status, vertical position, justification code and original blocks.
- **Row length**: the formatter writes each line as one row and does not wrap it. It sets the GSI field MNC, the maximum characters per row, to the longest row when that row is longer than the stored MNC. A teletext decoder shows at most 40 characters per row and cuts the rest. Wrap long lines before the write, with `wrapLines(40)` or the CLI options `--structure-wrap --structure-max-cpl 40`.
- **Round trip**: an unchanged file comes out byte for byte. A cue with unchanged text keeps its text field bytes, also after retiming.
- **New files**: a subtitle from another format gets these GSI values. The creation date is today.
  - Code page 850, 25 fps and level-1 teletext.
  - Character table 00, 40 characters per row and 23 rows.
  - Subtitle numbers from 1.

## iTunes Timed Text
Apple TV and the iTunes Store take subtitles as iTunes Timed Text (iTT). iTT is a TTML profile with SMPTE frame times.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.itt'), Format::Itt);
$subtitle->findFormatData('itt');  // ['timeBase' => 'smpte', 'frameRate' => '24', 'frameRateMultiplier' => '999 1000', 'dropMode' => 'nonDrop']
$subtitle->toString(Format::Itt);
Subtitle::fromStringAutoDetectFormat($srt)->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 23.976)]));
```

- **Parser**: `IttParser` is `TtmlParser` plus the `itt` format data. Format detection returns `Format::Ttml` for an iTT file. Both read the same cues.
- **Frame rate**: the formatter takes it from `IttWriteOptions::$frameRate`. Without it, the frame rate comes from the `itt` format data. `IttWriteOptions` accepts 23.976, 24, 25, 29.97 and 30. Without a frame rate, the formatter throws `InvalidArgumentException`.
- **Times**: a time such as `00:00:01:12` is an SMPTE time code at the effective frame rate. So at 29.97 fps `01:00:00:00` is 3603.6 s. The formatter rounds each time to the nearest frame and gives each cue at least one frame. It always writes `ttp:dropMode="nonDrop"`.
- **Apple limits**: one `div`, `sansSerif` as the only font family, and a fixed `<head>` with the `top` and `bottom` regions. `TopLeft`, `TopCenter` and `TopRight` go to `top`, all others to `bottom`. The formatter does not keep the `<head>` or the attributes of the input file.
- **Markup**: the formatter writes `<b>`, `<i>`, `<u>` and `<font color>` as `tts:` attributes on `<span>`. It writes colors with the rule of the TTML formatter, see [TTML](#ttml), and drops a value that is no color. It strips `<s>`, `<v>` and word timestamps.
- **Forced cues**: see [subtitle.md](subtitle.md#forced-cues).

## LRC
LRC holds song lyrics with a time per line.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('song.lrc'), Format::Lyrics, new ReadOptions(lastCueDuration: 4));
$subtitle->findMetadata(Subtitle::METADATA_TITLE);                         // from [ti:]
$subtitle->findFormatData('lrc');                                          // ['idTags' => ['by' => 'Jane Doe']]
$subtitle->toString(Format::Lyrics);                                       // ID tags first, then the lyrics
```

- **ID tags**: `[ti:]`, `[ar:]`, `[al:]` and `[au:]` become the metadata keys `title`, `artist`, `album` and `author`. The parser keeps all other ID tags in the `lrc` format data. `[#:]` lines become comments. The formatter writes ID tags at the top and each comment before its cue.
- **Offset**: the parser subtracts `[offset:]` milliseconds from every time, so `[offset:+500]` turns `[00:12.00]` into 11.5 s. The formatter writes the shifted times and no `[offset:]` tag.
- **Timestamps**: the parser reads `[mm:ss]`, `[mm:ss.x]`, `[mm:ss.xx]` and `[mm:ss.xxx]` with 1 to 3 minute digits. It also reads `[hh:mm:ss]` with an optional fraction, and spaces inside the brackets, such as `[ 00:12.00 ]`.
- **Lines**: a line can hold several timestamps. Enhanced LRC word times such as `<00:12.50>` become word timestamps, and back. The parser drops the space after a word time and the space before a word time at the line end. So `<00:03.20> Slow <00:03.75> river` becomes `Slow river` in SubRip, and the formatter writes `<00:03.20>Slow <00:03.75>river`.
- **Text on the next line**: a timestamp line without text takes the next line as its text, if that line has no timestamp and is no ID tag. So `[00:02:35]` and then `Hello` on the next line give one cue at 2 min 35 s.
- **End times**: a cue ends at the next timestamp in time order. A timestamp without text, such as `[00:17.20]` before another timestamp line, only ends the cue before it. The formatter writes such a line back. The last cue lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **Output**: times in centiseconds. The formatter strips all tags. Text with `<`, `>` and `&` round-trips.

## MicroDVD
MicroDVD counts time in video frames, so the parser and the formatter need the frame rate of the video.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(frameRate: 23.976)]));
$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::MicroDvd);   // reads {1}{1}23.976

$subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(
    frameRate: 23.976,               // null (default) takes the frame rate that the parser stored
    writeFrameRateLine: true,        // writes {1}{1}23.976 first
)]));
$subtitle->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(writeFrameRateLine: true)]));
```

- **Frame rate**: `MicroDvdReadOptions::$frameRate` wins over a `{1}{1}<fps>` first line. The parser never reads that line as a cue. Without either, the parser throws `ParsingException` in strict mode. Lenient mode uses 23.976 fps, as pysubs2 and mantas-done/subtitles do, and adds a `ParseWarning`. The format data holds this `frameRate`.
- `$subtitle->findFormatData('microdvd')['frameRate']` returns the frame rate that the parser used.
- **Frame rate line**: the format data holds `frameRateLine: true` when the file starts with `{1}{1}<fps>`. The formatter then writes that line with the frame rate of the output, so the file reads back at the same frame rate. `MicroDvdWriteOptions(writeFrameRateLine: true)` writes the line for any input. To drop the line of a MicroDVD input, remove `frameRateLine` from the format data.
- **Control codes**: `{y:b}`, `{y:i}`, `{y:u}`, `{y:s}` and `{c:$BBGGRR}` become core markup. The parser reads the codes at the start of each `|`-separated line. A code later in the line stays text. A lower-case code styles one line. An upper-case code styles the whole cue. The `microdvd` format data keeps other control codes.
- **Output**: the formatter writes control codes only for tags that wrap a whole line. It strips other tags. A tag that spans lines wraps each of its lines, so `<i>` around 2 lines gives `{y:i}` on both lines. A `<v>` tag does not hide the styles inside it. An unchanged cue keeps its original control codes. With `stripTags`, the formatter writes no control codes, also not the stored font and size codes such as `{f:Arial}` and `{s:30}`.

## MPL2 and TMPlayer
Both formats are common in Polish subtitle downloads and use the `.txt` extension.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('film.txt'), new ReadOptions(encoding: 'Windows-1250'));   // detects MPL2 or TMPlayer
$subtitle = Subtitle::fromString(file_get_contents('film.txt'), Format::TmPlayer, new ReadOptions(lastCueDuration: 3));
$subtitle->toString(Format::Mpl2);                                         // [12][45]Where are you?|/Home.
$subtitle->toString(Format::TmPlayer);                                     // 00:00:01:Where are you?|Home.
```

| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| MPL2 `[12][45]Where are you?\|/Home.` | 1.2 s to 4.5 s, `Where are you?` and `<i>Home.</i>` | the same line |
| TMPlayer `00:00:01:Rain`, `00:00:04:` and `00:00:09:Sun` | 1 s to 4 s, and 9 s to 14 s | the same three lines |
| TMPlayer+ `0:00:01=Hello` | 1 s to the next line | `00:00:01:Hello` |
| TMPlayer+ `00:00:01,1=Hello` and `00:00:01,2=world` | one cue with two lines | `00:00:01:Hello\|world` |

- **MPL2 times**: tenths of a second.
- **Italics**: the MPL2 formatter writes `/` for a line whose whole text is inside `<i>`. Both formatters strip all other tags.
- **TMPlayer end times**: a cue ends at the next later time in the file, also when a line comes out of time order. For example, `00:00:07:B` before `00:00:04:A` gives A from 4 s to 7 s. A line without text only ends the cue before it. The last cue lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **TMPlayer gaps**: TMPlayer counts whole seconds, so the formatter rounds each time to the second. A cue that ends before the next cue starts gets a line without text at its end. For a cue shorter than 1 s, that line comes 1 s after the start.
- **`.txt` files**: see [cli.md](cli.md#formats-and-file-extensions).

## MPSub
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.mpsub'), Format::MpSub);
$subtitle->toString(Format::MpSub);                                               // FORMAT=TIME
$subtitle->toString(Format::MpSub, new WriteOptions(formats: [new MpSubWriteOptions(frameRate: 25)]));   // FORMAT=25 and frame counts
```

- **Header lines**: the parser reads `TITLE` and `AUTHOR` into the metadata. It keeps all other header lines, such as `TYPE` and `NOTE`, in the `mpsub` format data. `FORMAT` only sets the time unit. The formatter writes these lines back, and writes empty `TITLE` and `AUTHOR` lines when the values are not set.
- **Frame rate**: MPlayer and FFmpeg read only the integer part of `FORMAT=29.97`. So the parser uses 29 fps, and the formatter accepts only whole frame rates.
- **Overlapping cues**: a cue that starts before the previous cue ends gets a negative wait, for example `-1.5 2`. The parser reads it back. FFmpeg accepts a negative wait.
- **Output**: the formatter strips all tags and trims each line. Text with `<`, `>` and `&` round-trips. An empty line ends a cue in MPSub, so the formatter drops lines that are empty after the tags go, and skips a cue without text. The wait time of the next cue counts from the end of the last written cue.

## SAMI
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.smi'), Format::Sami);   // the first class of the STYLE block that a <P> uses
$subtitle = Subtitle::fromString(file_get_contents('movie.smi'), Format::Sami, new ReadOptions(formats: [new SamiReadOptions(languageClass: 'FRCC')]));   // the FRCC class
$subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);                             // 'fr-FR', from the lang property of .FRCC
$subtitle->findFormatData('sami');                                                // keys style, class and samiParam
```

- **Language class**: a SAMI file holds one CSS class per language, for example `.FRCC { Name: French; lang: fr-FR; }`. The parser reads the class in `SamiReadOptions::$languageClass`. Without it, the parser reads the first class of the STYLE block that a `<P>` uses. When no `<P>` has a class, it reads the first class of the STYLE block. Without a STYLE block, it reads the first class that a `<P>` uses. It does the same when no `<P>` uses a class of the STYLE block, and warns in lenient mode. A `<P>` without a class belongs to every class.
- **End times**: a cue ends at the next `SYNC` with a `<P>` of the same class. A `SYNC` with no `<P>` also ends it. A `SYNC` with only `&nbsp;` ends a cue and starts none. The parser also reads `&nbsp` without a semicolon this way. The last cue lasts [`ReadOptions::$lastCueDuration`](read-options.md). A negative `Start` becomes 0.
- **Text**: a line break in the file is a space, as in HTML. Only `<br>` starts a new cue line. `<b>`, `<i>`, `<u>`, `<s>`, `<strike>` and `<font color>` become core markup. `<font color>` accepts `#rrggbb`, `rrggbb` and the 16 color names of HTML 4. The parser drops other tags from the cue text.
- **Formatter**: it keeps `<b>`, `<i>`, `<u>`, `<s>` and `<font>` and strips all other tags. It writes the stored `<TITLE>`, STYLE block and `<SAMIParam>`, without the rules of the other language classes. Without a stored block, it names the class after the language metadata, for example `KOKRCC` for `ko-KR`, or `SUBTTL` without a language.
- **Timing**: the formatter writes a `&nbsp;` SYNC after each cue that has a gap before the next cue. A cue that overlaps the next cue ends where the next cue starts. An unchanged cue keeps the HTML of its `<P>`.
- **Encoding**: the parser reads UTF-8 only. It throws `ParsingException` for other encodings. For a file in EUC-KR or CP949, pass `ReadOptions::$encoding`, see [encodings.md](encodings.md).

## SBV
SBV is the YouTube caption format `0:00:01.500,0:00:04.000`.

- **Parser**: accepts any number of hour digits below the [time limit](#the-format-enum). A timing line without text gives a cue with no lines. A timing line starts a new cue, also without an empty line before it. Text after an empty line stays in the cue before it, as long as no timing line follows, see [lenient-parsing.md](lenient-parsing.md).
- **Lenient mode**: also reads `0:00:07.980.0:00:11.300`, `0:00:07,980,0:00:11,300` and `0:00:07.98,0:00:11.3`, and warns. A time has 1 or 2 minute and second digits, `.`, `,` or `:` before the fraction, and 1 to 4 fraction digits.
- **Formatter**: writes one hour digit below 10 hours, and no UTF-8 BOM. It strips all tags and decodes HTML entities. Text with `<`, `>` and `&` round-trips. It skips a cue with no lines.

## SCC
SCC (Scenarist Closed Captions) is the closed caption format of US broadcast. Many streaming services also take it. Each line of an SCC file is a time code and CEA-608 byte pairs, one pair per frame at 29.97 fps.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\SccRollUp;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('show.scc'));  // detects SCC
$subtitle->findFormatData('scc');                                                 // ['dropFrame' => true]
$subtitle->getCues()[0]->findFormatData('scc');                                   // ['mode' => 'pop-on', 'rows' => [14, 15], 'columns' => [4, 8]]
Subtitle::fromString($content, Format::Scc, new ReadOptions(formats: [new SccReadOptions(channel: 2)]));   // CC2 or CC4
Subtitle::fromString($content, Format::Scc, new ReadOptions(formats: [new SccReadOptions(rollUp: SccRollUp::Lines)]));   // one cue per row

$subtitle->wrapLines(32, 4)->toString(Format::Scc);
$subtitle->toString(Format::Scc, new WriteOptions(formats: [new SccWriteOptions(dropFrame: false)]));

$report = (new SccFormatter())->formatWithReport($subtitle, new WriteOptions(formats: [new SccWriteOptions(fit: true)]));
$report->content;                                                                 // the SCC file
$report->changes[0]->message;                                                     // Cue #0 at 1 s: replaced "Š" with "S".
```

- **Reads**: pop-on, roll-up and paint-on captions, as the screen model of [47 CFR 15.119](https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml) defines them. Each change of the displayed captions starts a new cue. So a roll-up file gives one cue per screen, and a row shows in each cue until it rolls off.
- **Roll-up by row**: with `SccReadOptions(rollUp: SccRollUp::Lines)` or the CLI option `--scc-roll-up lines`, each row of roll-up captions gives one cue. The cue starts at the first character of the row. It ends when the row rolls up, or when an EDM or EOC clears the screen. A row that is still on the screen at the end of the file lasts [`ReadOptions::$lastCueDuration`](read-options.md). So a transcript of a live roll-up file holds each line once. Pop-on and paint-on captions give the same cues as without the option.
- **Writes**: pop-on captions on data channel 1, with drop-frame time codes by default. A cue with more than 4 lines, a line longer than 32 characters or a character that CEA-608 lacks throws `UnwritableContentException`.
- **Late captions**: each caption needs 1 frame per 2 characters, and some frames for control codes, before it shows. When the frames before the cue start are too few, the caption shows late.
- **Fit**: `SccWriteOptions(fit: true)` or the CLI option `--scc-fit` changes what SCC cannot hold, in place of throwing. `SccFormatter::formatWithReport()` returns the output and an `SccFitChange` for each change. The CLI prints each change on standard error. Each change has one of the `SccFitAction` cases:

  | Action | Change |
  |:--- |:--- |
  | `Transliterated` | a character that CEA-608 lacks becomes a similar one: `Š` becomes `S`, `Œ` becomes `OE`, `…` becomes `...` and `–` becomes `-` |
  | `Wrapped` | the text wraps again into at most 4 lines of at most 32 characters |
  | `Delayed` | the caption shows after the cue start |
  | `Dropped` | the output leaves out a caption that cannot show before the cue end |

  Text that does not wrap into 4 lines of 32 characters, and a character without a replacement such as `日`, still throw. Split such cues first, as the exception message says.
- **Times**: a semicolon before the frames marks drop-frame time code, a colon marks non-drop time code. A caption that no command erases lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **Damaged data**: the parser ignores the second copy of a doubled control code and drops a byte with a parity error. It skips data channel 2, XDS packets and text mode.
- **Position**: rows 1 to 4 give `Alignment::TopCenter`, and all other rows give `Alignment::BottomCenter`. The `scc` format data keeps the row and column of each line. The formatter writes them back when they still fit the cue. Otherwise it places the lines by the alignment, at the bottom and centered by default.
- **Timing of the formatter**: it loads each caption before the cue start. So the caption shows on the first frame of the cue. When the frames after the previous caption are too few for the load, the caption shows late. Of two overlapping cues, the later one replaces the earlier one.
- **Markup**: styles become `<i>`, `<u>` and `<font color>` with `#ffffff`, `#00ff00`, `#0000ff`, `#00ffff`, `#ff0000`, `#ffff00` and `#ff00ff`, and back. The formatter writes other colors as white and strips all other tags. A style change inside a word adds a space. A row starts in white without styles, so the formatter repeats a style that spans lines at the start of each line.
- **Limits**: the formatter throws `UnwritableContentException` for content that SCC cannot hold. This is more than 4 lines, more than 32 characters per line, or a character outside the CEA-608 character sets. The message names 1 of 2 fixes for too many or too long lines.
  - Wrapping the cue text at 32 characters gives 4 lines or fewer. Call `wrapLines(32, 4)` first.
  - Wrapping the cue text at 32 characters gives more than 4 lines. Call `Resegmenter::apply()` with `ResegmentMode::SplitLong` and `new CueLimits(32, 4)`, then `wrapLines(32, 4)`.

## SubRip
| Input | Parser result | Formatter output |
|:--- |:--- |:--- |
| `0:00:01.5` | 1.5 s. Accepts a dot, one to three hour digits and one to three millisecond digits. | `00:00:01,500` |
| `00:00:01:105` | 1.105 s. A `:` before exactly 3 fraction digits is valid in strict mode. | `00:00:01,105` |
| `0:0:1,500`, `07:03,920`, `0000:00:01,000`, `00:28:41,1000` | 1.5 s, 423.92 s, 1 s and 1721.1 s in lenient mode, with a warning per time. Lenient mode reads 1 or 2 digit minutes and seconds, no hours, up to 5 hour digits and up to 4 fraction digits, rounded to milliseconds. | `00:00:01,500` |
| `00：00：01，000` | 1 s in lenient mode, with a warning. Lenient mode reads the full-width `：`, `，`, `．` and `。` in a timing line as `:`, `,` and `.`. Cue text keeps them. | `00:00:01,000` |
| `00:00:01,000-->00:00:02,000` | 1 s to 2 s. Accepts any spaces or tabs around `-->`, or none. Lenient mode also reads an arrow with 1 or more dashes, such as `->`, and warns. | `00:00:01,000 --> 00:00:02,000` |
| `X1:100 X2:600 Y1:40 Y2:80` after the end time | `findFormatData('srt')['coordinates']` | the same coordinates |
| `align:start position:0%` after the end time | `findFormatData('vtt')`, for the WebVTT settings `vertical`, `line`, `position`, `size` and `align`. The key `alignment` holds the value of the `{\an}` tag, when the cue has one. Other text after the end time throws. Lenient mode ignores it and warns. | nothing. The WebVTT formatter writes the settings. |
| `{\an8}` anywhere in the cue | `Alignment::TopCenter`. The first tag wins. SSA `{\a6}` also becomes `TopCenter`. | `{\an8}` at the start of the first line, nothing for `Alignment::BottomCenter` |
| `{\b1}`, `{\i1}`, `{\u1}`, `{\s1}` and their `0` forms | `<b>`, `<i>`, `<u>`, `<s>` and their closing tags. An open tag closes at the end of the cue. | the HTML-like tags |

- A timing line without text gives a cue with no lines. The formatter writes such a cue as its number and its timing line.
- A cue number and a timing line start a new cue, also without an empty line before them.
- Text after an empty line stays in the cue before it, when no timing line follows and its first line is no number. See [lenient-parsing.md](lenient-parsing.md).
- Other override tags such as `{\pos(10,20)}` stay in the cue text.
- A tag is `<b>`, `<i>`, `<u>`, `<s>`, `<font>` or `<v>`, or a name with only `name=value` attributes, such as `<foo>` or `<span class="x">`. Other text in angle brackets, such as `<a sentence in brackets>`, is cue text.
- The formatter keeps `<b>`, `<i>`, `<u>`, `<s>` and `<font>`, and strips all other tags.

## SubViewer
SubViewer 1 and 2 are `.sub` formats from older DivX releases and DVD rippers. One parser reads both versions.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString(file_get_contents('movie.sub'), Format::SubViewer);
$subtitle->findFormatData('subviewer');  // ['version' => 2, 'header' => ['DELAY' => '0', 'CD TRACK' => '0'], 'style' => '[COLF]&HFFFFFF,[STYLE]bd,[SIZE]18,[FONT]Arial']
$subtitle->toString(Format::SubViewer);                                             // SubViewer 2
$subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V1)]));  // SubViewer 1
```

- **Version**: a `******** START SCRIPT ********` line makes a file SubViewer 1. All other files are SubViewer 2.
- **Header**: `[TITLE]` and `[AUTHOR]` become the metadata keys `title` and `author`. The parser keeps the other header tags and the `[COLF]` style line in the `subviewer` format data. The formatter writes them back after `[TITLE]` and `[AUTHOR]`. For a subtitle from another format, it writes the tags that Subtitle Edit writes.
- **Delay**: the parser adds the SubViewer 1 `[DELAY]` seconds to every time, as FFmpeg does, and stores `[DELAY]` as 0. It keeps the SubViewer 2 `[DELAY]` value and does not apply it.
- **SubViewer 2 text**: `[br]` and each text line become a cue line. The formatter writes all lines of a cue on one line, joined by `[br]`.
- **SubViewer 1 cues**: a `[00:00:01]` line with text below starts a cue. A time line with an empty line below ends the cue before it. A cue without such an end line ends where the next cue starts. The last cue lasts [`ReadOptions::$lastCueDuration`](read-options.md). `|` is a line break. As in FFmpeg, the parser reads only the first text line after a time line.
- **Output**: times in centiseconds for version 2 and in seconds for version 1. The formatter strips all tags and decodes HTML entities. It skips cues without text, because an empty line ends a cue.

## TTML
TTML covers TTML 1, TTML 2, IMSC and DFXP files. The parser reads the TTML namespace, a `<tt>` without a namespace and two DFXP namespaces. These are `http://www.w3.org/2006/10/ttaf1` and the older `http://www.w3.org/2006/04/ttaf1` of Flash caption files.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.ttml'), Format::Ttml);
$subtitle->findFormatData('ttml')['head'];             // <head> without ttm:title, as XML
$subtitle->getCues()[0]->findFormatData('ttml');       // ['attributes' => ['region' => 'bottom'], 'alignment' => 2, 'div' => [...]]
$subtitle->toString(Format::Ttml);
```

- **Time expressions**: `00:00:01.500`, `00:00:01:12` with frames, and `1.5s`, `1500ms`, `36f`, `15000000t`. Frames use `ttp:frameRate` and `ttp:frameRateMultiplier`, 30 fps by default. The frames field has 2 digits. Without `ttp:frameRate`, a frame label of 30 or more throws in strict mode. Lenient mode then reads the frames at 50 fps, or at 60 fps for a label of 50 or more, with a `repaired` warning. No frame rate fits a label of 60 or more, so lenient mode then reads the last field as hundredths of a second, for example `00:00:01:75` as 1.75 s. Ticks use `ttp:tickRate`. Lenient mode also reads these forms with a `repaired` warning: `00:00:7.250` with 1-digit fields, `02:15.500` without hours, `00:00:02:250` with milliseconds in the fourth field, and `4200` as milliseconds. The parser adds the `begin` of the parent `body` and `div` elements. A paragraph ends at the latest when its parent ends. A paragraph that begins at or after the end of its parent throws in strict mode. Lenient mode then reads the `begin`, `end` and `dur` of the paragraph as absolute times with a `repaired` warning, or skips the paragraph when they give no valid interval. The formatter writes `00:00:01.500`.
- **End times**: a paragraph without `end` or `dur` ends with its parent. Without a parent end, the cue runs from the first timed `<span>` to the last end of a timed `<span>`. An empty `end` or `dur` counts as missing. Without any end, strict mode throws `ParsingException`. Lenient mode ends the cue at the `begin` of the next paragraph in document order that begins later, with a `repaired` warning. It skips a paragraph that no later paragraph follows.
- **Timed spans**: the parser writes a word timestamp before each `<span>` with `begin`, `end` or `dur` that starts inside the cue, for example `One <00:00:02.000>Two`.
- **Styles**: the parser resolves the `style` references and the inline `tts:` attributes. Styles inherit in the order region, `<body>`, `<div>`, `<p>`, `<span>`. Bold, italic, oblique, underline, line-through and the text color become core markup. White text gives no `<font>` tag. The formatter writes each tag as a `<span>` with an inline style.
- **Colors in output**: the formatter writes a valid TTML color as it is, for example `rgba(0,0,255,128)` or `teal`. It writes a color name in lower case, so `Yellow` becomes `yellow`. It writes other [color values](markup.md) as `#rrggbb`, or as `#rrggbbaa` with an alpha channel. So `#FF0` becomes `#ffff00` and `rgba(255,0,0,0.5)` becomes `#ff000080`. A value that is no color stays as it is.
- **Inherited styles in output**: the formatter keeps the stored styles of the region, `<body>`, `<div>` and `<p>`. Core markup cannot turn an inherited style off. So the formatter wraps text without that style in a `<span>` with the default value, such as `tts:color="white"` or `tts:fontStyle="normal"`.
- **Speakers**: `ttm:agent` becomes `<v Name>`, with the name from the `ttm:name` of the agent. The formatter adds a `ttm:agent` element to the head for a new name.
- **Alignment**: the parser maps the region to an alignment only for `tts:textAlign` `left`, `center` or `right`. Other regions give `Alignment::BottomCenter`. A text anchor in the top third of the screen gives the top row, in the bottom third the bottom row. The `alignment` key of the `ttml` format data keeps the alignment that the parser read.
- **Regions from alignment**: a cue without a stored `region` gets a region such as `topCenter` that matches its alignment. A cue with `Alignment::BottomCenter` gets `bottomCenter` only in a subtitle from another format. A cue keeps its stored `region` while it has the alignment that the parser read. After `setAlignment()` with another value, the formatter writes the region of the new alignment. It also writes `tts:textAlign` on the `<p>` when a style of the `<p>`, `<div>` or `<body>` sets another column.
- **Metadata**: `xml:lang` of `<tt>` is the `language` and the first `ttm:title` is the `title`. The formatter writes the title as the first child of `<head>`.
- **Kept as is**: the `<head>`, the namespace and the attributes of `<tt>`, `<body>`, `<div>` and `<p>`. So a DFXP file stays DFXP. The formatter drops `ttp:timeBase`, `ttp:clockMode`, `ttp:dropMode` and `ttp:markerMode`, because it writes media times.
- **Characters XML forbids**: XML 1.0 forbids the C0 control characters except tab, line feed and carriage return, and U+FFFE and U+FFFF. The TTML and iTT formatters drop them from text, attributes, the title and speaker names. For example, the text `a`, U+0001 and `b` becomes `ab`.
- **Limits**: the parser reads `seq` time containers as `par`. The formatter strips word timestamps. A cue identifier that is not a valid `xml:id` is not written.
- **Unique IDs**: two cues with the ID `c1` come out as `c1` and `c1_2`. Each `xml:id` value occurs once in the output. A cue ID that a region or a style already uses also gets the next free suffix. The IDs in `<head>` keep their value. The IDs of `<tt>`, `<body>`, `<div>` and `<p>` follow in output order.
- **Text before the XML**: the parser skips white space before the XML declaration. For other text there, see [lenient-parsing.md](lenient-parsing.md#other-formats).
- **Security**: the parser loads no external entity or DTD and makes no network access.
- **Forced cues**: see [subtitle.md](subtitle.md#forced-cues).

## WebVTT
| Input | Goes to |
|:--- |:--- |
| `NOTE` block | `$subtitle->getComments()` |
| Cue identifier | `$cue->getIdentifier()` |
| Text after `WEBVTT`, lines up to the first empty line | `$subtitle->findFormatData('vtt')`, keys `header` and `headerLines` |
| `STYLE` blocks, CSS not parsed | `$subtitle->findFormatData('vtt')['styles']` |
| `REGION` blocks | `$subtitle->findFormatData('vtt')['regions']`, one `name => value` array per region |
| Cue settings `vertical`, `line`, `position`, `size`, `align`, `region` | `$cue->findFormatData('vtt')`, exact values. The key `alignment` holds the alignment that the parser read from them. |
| `&nbsp;`, `&lrm;`, `&rlm;` | the characters U+00A0, U+200E, U+200F |

- **Alignment from cue settings**: `line:0` is the top row, and `line:50%,center` is the middle row. No `line`, `line:-1` and `line:100%,end` are the bottom row. `align:left`, `center` and `right` set the column. Other values, `align:start`, `align:end` and `vertical` give `Alignment::BottomCenter`.
- **Cue settings from alignment**: a cue without `vtt` format data gets settings from its alignment. `Alignment::TopCenter` becomes `line:0`, `Alignment::TopLeft` becomes `line:0 align:left`. A cue keeps its stored settings while it has the alignment that the parser read. After `setAlignment()` with another value, the formatter drops the stored `line`, `position` and `align` and writes the settings of the new alignment. It keeps `size`, `vertical` and `region`.
- **Cue settings without a space**: the parser also reads settings that follow the end time directly, as in `00:01.000line:40%`. The formatter writes a space before them.
- **Hours**: the parser reads hours with any number of digits, such as `0:00:00.800` and `0010:00:40.750`, as the spec allows. The [time limit](#the-format-enum) applies. A time without hours has exactly 2 minute digits. The formatter writes at least 2 hour digits, so `0010` becomes `10`.
- **Lines of white space**: only an empty line ends a cue. A line with only spaces or tabs inside a cue is an empty text line, and the parser drops it. YouTube automatic captions have such a line in each cue.
- **YouTube automatic captions**: yt-dlp `--write-auto-subs` saves rolling cues. Each cue repeats the line before it above a new line with `<c>` word timestamps, and a 10 ms cue repeats the new line. The parser and `WebVttStreamReader` drop the 10 ms cues and the repeated lines, so each spoken line becomes one cue with its word timestamps. The next cue starts where the dropped 10 ms cue started. A file without `<c>` word timestamps or without the 10 ms repeat cues stays as it is.
- **Cues without text**: a timing line without text gives a cue with no lines. The formatter writes such a cue as its identifier and its timing line.
- **Output**: the formatter writes the header, comments, styles, regions and cue settings back. It numbers cues without an identifier and always writes hours. It writes `REGION` blocks before `STYLE` blocks, and both before the comments that come before the first cue.
- **Markup**: the formatter keeps `<b>`, `<i>`, `<u>`, `<v>`, `<lang>`, `<c>`, `<ruby>`, `<rt>` and word timestamps. It keeps classes such as `<c.yellow>` and strips all other tags.
- **Colors**: the formatter writes `<font color>` as a [color class](markup.md) when the color is one of the 8 WebVTT colors. `#FFFF00`, `#ff0` and `yellow` all become `<c.yellow>`. WebVTT has no class for other colors, so the formatter drops them. `WebVttFormatter::formatWithReport()` returns the output and a `WebVttDroppedColor` for each dropped color. `WebVttStreamWriter::findDroppedColors()` returns the same list for the cues it wrote. The CLI prints each one on standard error.

  ```php
  $report = (new WebVttFormatter())->formatWithReport($subtitle);
  $report->content;                       // the WebVTT file
  $report->droppedColors[0]->message;     // Cue #3 at 4 s: dropped the color "#123456", because WebVTT has classes for 8 colors only.
  ```
- **Colors in other formats**: the SubRip, ASS, TTML, iTunes Timed Text, MicroDVD, SAMI, EBU STL and SCC formatters write a color class as a text color. TTML also writes a background class such as `bg_black` as `tts:backgroundColor`. SubRip, ASS, TTML, iTunes Timed Text, MicroDVD and SAMI keep colors that have no WebVTT class.
- **Timing arrow in text**: the formatter writes `-->` in cue text as `--&gt;`. A raw `-->` would start a new cue.
