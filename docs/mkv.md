# MKV, WebM and MP4 subtitle tracks

Media servers and subtitle managers get MKV files with embedded subtitles. The library reads the subtitle tracks of MKV, WebM and MP4 files in PHP, without `ffmpeg` or `mkvextract`. This page covers MKV and WebM first. The [MP4](#mp4) section covers MP4 and MOV. It reads only. In the command line tool, `info movie.mkv` lists the tracks and `convert movie.mkv --to srt -o out.srt --track 3` reads one. See [cli](cli.md).

```php
use SubtitleToolbox\Subtitle;

foreach (Subtitle::tracks('/media/movie.mkv') as $track) {
    echo "$track->number $track->codecId $track->language $track->name", PHP_EOL;   // 3 S_TEXT/UTF8 de Deutsch (Forced)
}
Subtitle::loadTrack('/media/movie.mkv', 3)->save('movie.de.srt');
Subtitle::loadAutoDetectFormat('/media/one-track.webm');       // reads the only subtitle track
```

`MatroskaReader` is in the namespace `SubtitleToolbox\Container\Matroska`. Its `open()` takes a seekable stream resource too: `MatroskaReader::open($stream)->extract(3)`.

| Codec | Becomes |
|:--- |:--- |
| `S_TEXT/UTF8` | SubRip cues |
| `S_TEXT/ASS`, `S_TEXT/SSA` | an ASS or SSA subtitle, as `mkvextract` writes it |
| `S_TEXT/WEBVTT` | a WebVTT subtitle with its header, cue settings, identifiers and comments |
| `D_WEBVTT/SUBTITLES`, `D_WEBVTT/CAPTIONS`, `D_WEBVTT/DESCRIPTIONS` | a WebVTT subtitle with cue settings and identifiers, as ffmpeg writes WebVTT into WebM. `D_WEBVTT/METADATA` holds no text to display, and the reader does not extract it |
| `S_HDMV/PGS` | image cues from `PgsParser`, see [ocr.md](ocr.md#pgs) |
| `S_VOBSUB` | image cues from `VobSubParser`, as the `.idx` and `.sub` pair gives them. `CodecPrivate` holds the `.idx` header |

- **Tracks**: `Subtitle::tracks()` lists only tracks of type subtitle. It returns `SubtitleTrack` objects from the namespace `SubtitleToolbox\Container`. It reads MKV, WebM, MP4 and MOV files.

| Property | Type | MKV value |
|:--- |:--- |:--- |
| `container` | `ContainerFormat` | `ContainerFormat::Matroska` |
| `number` | `int` | `TrackNumber` |
| `codecId` | `string` | `CodecID`, for example `S_TEXT/UTF8` |
| `format` | `?Format` | the format of the codec in the table above, null for a codec that the reader does not extract |
| `language` | `string` | see **Language** below |
| `name` | `?string` | `Name` |
| `default` | `bool` | `FlagDefault` |
| `forced` | `bool` | `FlagForced` |

- **Format**: `getFormat()` of the subtitle is the format of the codec in the table.
- **Detection**: `loadAutoDetectFormat()` and `fromStringAutoDetectFormat()` know an MKV or WebM file by its first 4 bytes, not by its extension.
- **Language**: the reader takes `LanguageBCP47`. Without it, the reader takes `Language`. Without either, the language is `eng`, as the spec defines. `loadTrack()` puts it into the `language` metadata.
- **Forced**: on a track with the forced flag, `loadTrack()` sets the forced flag of every cue. PGS cues also keep the forced flag of their objects.
- **End times**: a text block without a duration ends at the start of the next block of the track. The last such block lasts [`ReadOptions::$lastCueDuration`](read-options.md). A VobSub unit ends at its stop command. Without one, it ends at the end of its block, by the same rules.
- **Compression**: the reader reads zlib compression and header stripping. It throws `ParsingException` for bzlib compression, LZO compression and encrypted tracks.
- **Live recordings**: the reader accepts a file with elements of unknown size, as live recordings write them.
- **Memory**: the reader skips video and audio data, so memory grows with the subtitle track, not with the file. In the tests, a 64 MB file with 64 cues needs less than 4 MB.
- **Speed**: the reader walks the whole file for each `loadTrack()` call. A 2-hour, 4 GB file takes about 3 s of CPU time. On a network volume it takes 30 to 45 s.
- **Errors**: `loadTrack()`, `MatroskaReader::extract()` and `MatroskaReader::trackFormat()` throw `InvalidArgumentException` for a number that is not a subtitle track. `trackFormat()` returns null for a codec that the reader does not extract, such as `S_DVBSUB`. `loadTrack()` and `extract()` throw `ParsingException` in these cases:
  - Another codec, such as `S_DVBSUB`.
  - A `S_VOBSUB` track without a `size` or `palette` line in `CodecPrivate`.
  - Laced subtitle blocks.
  - A block that starts or ends at 100,000 hours or more, the [time limit](formats.md) of the parsers.
  - An unsigned integer element, such as a cluster `Timestamp`, of more than 8 bytes or of 2^63 or more.
  - An element whose size reaches past the end of the file. The reader checks the size before it reads, so a damaged size cannot exhaust the memory.
  - Zlib data that is not valid or that inflates to more than 64 MB. A full-screen 4K PGS display set holds about 8 MB.
  - A file that is not Matroska or WebM.
- **Spec**: [Matroska elements](https://www.matroska.org/technical/elements.html), [Matroska subtitles](https://www.matroska.org/technical/subtitles.html).

## MP4

`ffmpeg -c:s mov_text`, HandBrake and phones store text subtitles in MP4 and MOV files as 3GPP timed text (`tx3g`). The same calls read them: `Subtitle::tracks()`, `loadTrack()` and `loadAutoDetectFormat()`. `Mp4Reader` in the namespace `SubtitleToolbox\Container\Mp4` has the methods of `MatroskaReader`.

```php
Subtitle::loadTrack('/media/phone-video.mp4', 2)->save('phone-video.srt');
```

| Property | MP4 value |
|:--- |:--- |
| `container` | `ContainerFormat::Mp4` |
| `number` | the track ID of `tkhd` |
| `codecId` | the sample entry type, for example `tx3g` or `c608` |
| `format` | `Format::SubRip` for `tx3g`, null for other codecs and encrypted tracks |
| `language` | the `elng` box, else the `mdhd` language, for example `eng` |
| `name` | the `udta/name` box |
| `default` | the track enabled flag of `tkhd` |
| `forced` | the `tx3g` display flag "all samples are forced" |

- **Tracks**: `tracks()` lists tracks with the handler `text`, `sbtl`, `subt` or `clcp` (closed captions).
- **Cues**: each `tx3g` sample with text becomes a cue with the sample times. An empty sample is a gap. The text is UTF-8, or UTF-16 after a byte order mark. The reader turns the bold, italic and underline runs of a `styl` box into `<b>`, `<i>` and `<u>` tags, so `ffmpeg -c:s mov_text` output keeps the tags of the SubRip input. It ignores the font, the size and the color of a run, and other modifier boxes such as `hlit`.
- **Format**: `getFormat()` is `Format::SubRip`. The cues hold text with SubRip tags, as from an `S_TEXT/UTF8` track of an MKV file.
- **Detection**: `loadAutoDetectFormat()` and `fromStringAutoDetectFormat()` know an MP4 file by the `ftyp` box at byte 4.
- **Memory**: the reader reads the sample tables of the wanted track and its samples only. It skips the media data with `fseek()`.
- **Errors**: `extract()` throws `ParsingException` for a `c608` track, another codec and an encrypted track (`enct` or a `sinf` box). It also throws `ParsingException` for a sample outside the file. The same applies when the `stsz` box lists more sample bytes than the file has.
- **Spec**: ISO/IEC 14496-12 for the boxes, 3GPP TS 26.245 for timed text.
