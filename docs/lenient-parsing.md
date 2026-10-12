# Lenient parsing

A subtitle download is often broken in one place. By default, the parsers throw `ParsingException` at the first broken block. In lenient mode, the parser skips or repairs the broken block, records a `ParseWarning` and goes on.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($download, Format::SubRip, new ReadOptions(lenient: true));
foreach ($subtitle->getParseWarnings() as $warning) {
    $line = $warning->lineNumber ?? '-';   // lineNumber is null for EBU STL, PGS, VobSub and JSON
    $logger->warning("line $line: $warning->message ({$warning->action->value})");
}
// line 6: Block #1 has no timing line on its second line. The line is "00:00:05,000 => 00:00:07,000". (skipped)
```

## SubRip, WebVTT and SBV
| Damage | SubRip | WebVTT | SBV |
|:--- |:--- |:--- |:--- |
| cue without a cue number | repaired | not an error | not an error |
| bad timestamp | skipped | skipped | skipped |
| time with 1-digit fields, no hours, 4 hour digits or 4 fraction digits, as in `0:0:1,500` | repaired | repaired | does not apply |
| WebVTT time with 1 or 2 fraction digits, or `,` before the fraction, as in `00:01,5` | not an error | repaired | does not apply |
| `->` or `--->` arrow | repaired | skipped | no arrow in the format |
| full-width `：`, `，`, `．` or `。` in a timing line | repaired | skipped | skipped |
| SBV timing line with `.` between the times, `,` or `:` before the fraction, or 1, 2 or 4 fraction digits | does not apply | does not apply | repaired |
| unknown text after the end time | repaired | dropped as the spec says, no warning | skipped |
| no empty line between two cues | repaired | split as the spec says, no warning | repaired |
| empty line inside the cue text, or between the timing line and the text | repaired | repaired | repaired |
| no empty line after the `WEBVTT` header | not an error | repaired | not an error |
| no `WEBVTT` line, a damaged one, or text before it | not an error | repaired | not an error |
| C0 control character or DEL, see [formats.md](formats.md#load-and-save) | repaired. NUL throws in strict mode | repaired. NUL becomes U+FFFD in both modes | repaired. NUL throws in strict mode |
| text before the first cue | skipped | skipped | skipped |
| truncated last cue | skipped | skipped | skipped |

- **No empty line between two cues**: SubRip and SBV split the block before each timing line in strict mode too, without a warning. A SubRip cue without a cue number still throws in strict mode.
- **No `WEBVTT` line**: in lenient mode, the WebVTT parser skips the lines before the first line that starts with `WEBVTT`. Without such a line before the first cue, it skips the lines before the first cue. The skipped lines go into the `block` of the warning. A damaged `WEBVTT` line also loses the header text and `STYLE` and `REGION` blocks before the first cue.
- **Empty line inside a cue**: SubRip and SBV add a block without a timing line to the cue before it, in strict mode too. Lenient mode warns. The block stays apart and the parser skips it when it follows no cue, starts with a time, or starts with a number in SubRip.
- **Empty line inside a WebVTT cue**: only lenient mode adds the block to the cue before it, because the spec ends a cue at an empty line. Strict mode throws for the block. A `NOTE`, `STYLE` or `REGION` block stays apart.
- **Loose times**: a time whose last field has 1 digit and no fraction, such as `00:00:0`, stays an error. It is a time that the end of the file cut off.
- **Minutes or seconds of 60 or more**: a time such as `00:75:02,000` or `00:00:75,000` is a bad timestamp in lenient mode too. A carried-over value can put a cue far from its place. The hours field can have more than 2 digits.
- **Cue without text**: a timing line without text lines gives a cue with no lines, in strict and lenient mode. WebVTT allows an empty cue. To drop these cues, call `$subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => $cue->getLines() === [])`.

## Other formats
| Parser | Skipped with a warning | `blockIndex` counts |
|:--- |:--- |:--- |
| ASS, SSA | a `Dialogue:` or `Comment:` line with too few fields or a bad time. A file without a `Format:` line is not an error. The parser then uses the default fields. A time without a fraction, with 4 fraction digits, or with `,` or `:` before the fraction gets a `repaired` warning, for example `0:00:01`, `0:00:01.5000` or `0:00:01,50`. The parser rounds it to milliseconds. A time with minutes or seconds of 60 or more is a bad time in every shape, for example `0:75:02.00`. A number as a field name in the `[Events]` `Format:` line, such as `1`, throws in strict mode. Lenient mode gives a `repaired` warning with `blockIndex` null, and the parser drops the values of that field | events |
| MicroDVD | a line without `{start}{end}` frames, also before the `{1}{1}<fps>` line. A file without a frame rate gets a `repaired` warning with `lineNumber` and `blockIndex` null, and the parser uses 23.976 fps | non-empty lines |
| MPL2 | a line without `[start][end]` | non-empty lines |
| TMPlayer | a line without a time | non-empty lines |
| SubViewer 1 | a bad header line, a time line with minutes or seconds of 60 or more, for example `[00:75:02]`. A cue without its end line then ends at the next cue | cues |
| SubViewer 2 | a timing line with one bad time and its text, and text before the first cue. Minutes or seconds of 60 or more make a bad time, for example `00:00:01.00,00:75:02.00`. A timing line without fractions, with 4 fraction digits or with 1-digit fields gets a `repaired` warning, for example `0:0:1.50,0:0:2.0000`. The parser rounds it to milliseconds. Strict mode reads such a line as cue text | cues |
| MPSub | a bad wait and duration pair and its text, a cue without text, a bad `FORMAT=` value. A file without `FORMAT=` gets a `repaired` warning, and the parser reads the times as seconds | cues |
| LRC | a line with a time tag that the parser cannot read, for example `[01:2x.00]`, or with hours and minutes of 60 or more, for example `[1:75:00.00]`. Without hours, minutes of 60 or more are valid, for example `[75:00.00]` | non-empty lines |
| SAMI | a `<SYNC>` tag without a valid `Start`. A negative `Start` gets a `repaired` warning, and the parser reads it as 0. A file whose `<P>` classes are all missing from the STYLE block gets a `repaired` warning with `blockIndex` null | `<SYNC>` tags |
| TTML, iTT | a `<p>` with a bad time or without an end time | `<p>` elements |
| EBU STL | a subtitle with a time code out of range, a cut-off last TTI block | TTI blocks |
| SCC | a line without a time code, and an invalid byte pair such as the `9` of a cut-off last line. The other byte pairs of the line keep their frames. In roll-up and paint-on captions, the last cue keeps the characters before the damage | non-empty lines, the header line is 0 |
| PGS | the rest of the file from a segment that is cut off or does not start with `PG`. A display set that the damage cut off ends the cue on the screen and shows nothing | segments |
| VobSub | a subpicture packet that is cut off, starts outside the `.sub` content or fails to decode. The cues of the other packets stay | timestamp lines of the track in the `.idx` |
| CSV, TSV | a row with a bad time. The rows before the header row, with 1 warning that has `blockIndex` null. A quoted cell without a closing quote gets a `repaired` warning, and the cell ends at the end of its line | rows after the header, without empty rows |
| JSON | a cue with a bad field. The parser also drops a bad metadata field, a bad comment and the bad format data of one format. Their warnings have `blockIndex` null | cues |
| Whisper JSON | a segment without `start`, `end` or `text`, or with a time that is negative or not a finite number | segments |
| YouTube timed text | an event or element with a bad or negative time, for example `"tStartMs": -5000` | events or elements |
| Amazon Transcribe, Deepgram, AssemblyAI, Google | a word, segment, utterance, sentence or result with a bad or negative time or a bad text. Also a Google result whose `alternatives` is not a list of objects | the index in its own list. Each list starts at 0, for example the sentences of each Deepgram paragraph. So 2 warnings can have the same `blockIndex` |
| Podcasting 2.0 transcript JSON | a segment with a bad field, for example `"startTime": -5` | segments |
| HTML transcript | a paragraph with a bad time or without a `<time>` | the paragraphs that each `<cite>` or `<time>` starts |

- **Ignored**: the chapter parsers ignore `ReadOptions::$lenient` and always throw. For example, the Podcasting 2.0 chapters parser throws for `"startTime": -5`.
- **`ParseWarning`**: see [ParseWarning fields](#parsewarning-fields). A skipped block reports the line of the error, as `ParsingException::getLineNumber()` does in strict mode. A repair reports the line where the parser split or read the cue. SubRip and WebVTT report a repaired time or arrow on the timing line.
- **No line numbers**: binary EBU STL, PGS, VobSub and the JSON formats have no line numbers, so their warnings have `lineNumber` null. The YouTube XML formats report the line of the XML element.
- **Warnings**: `Subtitle::getParseWarnings()` returns the warnings of the read that made the subtitle.
- **Not the format**: lenient mode still throws for a WebVTT file without `WEBVTT` when the first timing line is not a WebVTT timing line, for example `00:00:01,000 --> 00:00:02,000`. Autodetection still needs the `WEBVTT` line. SubRip and SBV have no signature, so a file without one readable cue gives no cues and warnings.
- **Whole-file errors**: lenient mode still throws for a problem outside one cue. Examples:
  - Invalid XML in TTML, or invalid JSON.
  - A Whisper `segments` or YouTube `events` field that is an object, not a list.
  - An ASS file without `[Script Info]` and without `[Events]`. A file with `[Script Info]` and no `[Events]` gives an empty subtitle in both modes.
- **Invalid UTF-8**: lenient mode adds one `repaired` warning with the offset of the first bad byte. SAMI, TTML, iTT and YouTube srv1, srv2 and srv3 read each bad byte as U+FFFD, and the other formats keep it. See [encodings.md](encodings.md).
- **Text before the XML**: the TTML, iTT and YouTube XML parsers skip white space before the XML in both modes. Other text before the XML declaration or the root element throws in strict mode. Lenient mode skips it with a `repaired` warning that has `blockIndex` null, for example for a `Subtitles by ...` line.
- **HTML entities in TTML**: XML defines only `&amp;`, `&lt;`, `&gt;`, `&quot;` and `&apos;`. Strict mode throws for an HTML entity such as `&eacute;` or `&nbsp;`. Lenient mode reads each HTML5 named entity as its character and adds one `repaired` warning. The parser never loads a DTD or an external entity.
- **Malformed XML in TTML**: strict mode throws. Lenient mode keeps a bare `&` and an unknown entity such as `&foo;` as text, and reads `<br>` as `<br/>`. libxml then repairs the rest, for example a missing end tag. The parser adds one `repaired` warning with the first libxml error and its line. A file that gives no `<tt>` root still throws.
- **Strict mode without an exception**: the LRC parser drops a line with a bad time tag. The EBU STL parser reads a time code out of range as it is. In lenient mode, both record a warning, and the EBU STL parser also skips the subtitle.
- **Stream readers**: `SubRipStreamReader` and `WebVttStreamReader` take `ReadOptions(lenient: true)` in the constructor and have `getWarnings()`. They give the same cues and warnings as a lenient `Subtitle::fromString()`.
- **Command line tool**: `--lenient` turns on lenient mode and prints each warning to standard error.

## Cue that ends before it starts
`00:00:05,000 --> 00:00:02,000` throws `ParsingException` in strict mode, with the line of the times. A cue that ends when it starts stays valid.

| Parser | Lenient mode |
|:--- |:--- |
| SubRip, WebVTT, SBV, ASS and SSA, SubViewer, MPL2, MicroDVD, library JSON, Podcasting 2.0 transcript JSON, Whisper JSON, Amazon Transcribe, Deepgram, AssemblyAI, Google | swaps the start and the end with a `repaired` warning when the cue then lasts 30 s or less. A longer cue is skipped |
| CSV, TSV | skips the row. A row that a comma in a time split, or a cut-off last row, gives such an end, and a swap would give wrong times |

- **Why 30 s**: a swapped typo gives a normal cue length. A cue of more than 30 s comes from a broken time, so a swap would invent a cue.
- **Grouped words**: Amazon Transcribe, Deepgram, AssemblyAI and Google group words into cues when the file has no segments or utterances. The warning of such a cue has the 0-based index of the group as `blockIndex`, and the JSON of its words as `block`.

## ParseWarning fields
| Field | Content |
|:--- |:--- |
| `message` | the message of the error |
| `lineNumber` | the 1-based line of the problem, or null |
| `blockIndex` | the 0-based index of the block. Null for a library JSON field outside the cues |
| `block` | the trimmed lines of the block |
| `action` | `ParseWarningAction::Skipped` or `ParseWarningAction::Repaired` |
