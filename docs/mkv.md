# MKV, WebM, MP4 and MPEG-TS subtitle tracks

Media servers and subtitle managers get MKV files with embedded subtitles. The library reads the subtitle tracks of MKV, WebM and MP4 files and the teletext and DVB subtitles of MPEG-TS recordings in PHP, without `ffmpeg` or `mkvextract`. This page covers MKV and WebM first. The [MP4](#mp4) section covers MP4 and MOV, the [MPEG-TS teletext](#mpeg-ts-teletext) section DVB recordings, and the [DVB subtitles](#dvb-subtitles) section the bitmap subtitles of DVB recordings in MPEG-TS and MKV files. It reads only. In the command line tool, `info movie.mkv` lists the tracks and `convert movie.mkv --to srt -o out.srt --track 3` reads one. See [cli](cli.md).

```php
use SubtitleToolbox\Subtitle;

foreach (Subtitle::tracks('/media/movie.mkv') as $track) {
    echo "$track->number $track->codecId $track->language $track->name", PHP_EOL;   // 3 S_TEXT/UTF8 de Deutsch (Forced)
}
Subtitle::loadTrack('/media/movie.mkv', 3)->save('movie.de.srt');
Subtitle::load('/media/one-track.webm');                       // reads the only subtitle track
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
| `S_DVBSUB` | image cues, see [DVB subtitles](#dvb-subtitles) |

- **Tracks**: `Subtitle::tracks()` lists only tracks of type subtitle. It returns `SubtitleTrack` objects from the namespace `SubtitleToolbox\Container`. It reads MKV, WebM, MP4, MOV and MPEG-TS files.

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

- **Format**: `findFormat()` of the subtitle is the format of the codec in the table.
- **Detection**: `load()` and `fromString()` without a format know an MKV or WebM file by its first 4 bytes, not by its extension.
- **Language**: the reader takes `LanguageBCP47`. Without it, the reader takes `Language`. Without either, the language is `eng`, as the spec defines. `loadTrack()` puts it into the `language` metadata.
- **Forced**: on a track with the forced flag, `loadTrack()` sets the forced flag of every cue. PGS cues also keep the forced flag of their objects.
- **End times**: a text block without a duration ends at the start of the next block of the track. The last such block lasts [`ReadOptions::$lastCueDuration`](read-options.md). A VobSub unit ends at its stop command. Without one, it ends at the end of its block, by the same rules.
- **Compression**: the reader reads zlib compression and header stripping. It throws `ParsingException` for bzlib compression, LZO compression and encrypted tracks.
- **Live recordings**: the reader accepts a file with elements of unknown size, as live recordings write them.
- **Memory**: the reader skips video and audio data, so memory grows with the subtitle track, not with the file. In the tests, a 64 MB file with 64 cues needs less than 4 MB.
- **Speed**: in a file from mkvmerge, the reader reads only the blocks that the `Cues` element lists for the track. It does so only when their number equals the `NUMBER_OF_FRAMES` tag that mkvmerge writes for the track. For a 2-hour, 1.3 GB film with 2,000 cues, `loadTrack()` then takes 0.24 s instead of 1.1 s, and 3.2 s instead of 6.8 s from disk. In other files, and when the numbers differ, the reader walks the whole file for each `loadTrack()` call.
- **Errors**: `loadTrack()`, `MatroskaReader::extract()` and `MatroskaReader::trackFormat()` throw `InvalidArgumentException` for a number that is not a subtitle track. `trackFormat()` returns null for a codec that the reader does not extract, such as `S_ARIBSUB`. `loadTrack()` and `extract()` throw `ParsingException` in these cases:
  - Another codec, such as `S_ARIBSUB`.
  - A `S_VOBSUB` track without a `size` or `palette` line in `CodecPrivate`.
  - Laced subtitle blocks.
  - A block that starts or ends at 100,000 hours or more, the [time limit](formats.md) of the parsers.
  - An unsigned integer element, such as a cluster `Timestamp`, of more than 8 bytes or of 2^63 or more.
  - An element whose size reaches past the end of the file. The reader checks the size before it reads, so a damaged size cannot exhaust the memory.
  - Zlib data that is not valid or that inflates to more than 64 MB. A full-screen 4K PGS display set holds about 8 MB.
  - A file that is not Matroska or WebM.
- **Spec**: [Matroska elements](https://www.matroska.org/technical/elements.html), [Matroska subtitles](https://www.matroska.org/technical/subtitles.html).

## MP4

`ffmpeg -c:s mov_text`, HandBrake and phones store text subtitles in MP4 and MOV files as 3GPP timed text (`tx3g`). Final Cut Pro, Compressor, Premiere Pro and iTunes Store files carry CEA-608 closed captions in a `c608` track, and CEA-708 captions in a `c708` track. The same calls read them: `Subtitle::tracks()`, `loadTrack()` and `load()` without a format. `Mp4Reader` in the namespace `SubtitleToolbox\Container\Mp4` has the methods of `MatroskaReader`.

```php
Subtitle::loadTrack('/media/phone-video.mp4', 2)->save('phone-video.srt');
```

| Property | MP4 value |
|:--- |:--- |
| `container` | `ContainerFormat::Mp4` |
| `number` | the track ID of `tkhd` |
| `codecId` | the sample entry type, for example `tx3g` or `c608` |
| `format` | `Format::SubRip` for `tx3g`, `Format::Scc` for `c608`, `Format::Mcc` for `c708`, null for other codecs and encrypted tracks |
| `language` | the `elng` box, else the `mdhd` language, for example `eng` |
| `name` | the `udta/name` box |
| `default` | the track enabled flag of `tkhd` |
| `forced` | the `tx3g` display flag "all samples are forced" |

- **Tracks**: `tracks()` lists tracks with the handler `text`, `sbtl`, `subt` or `clcp` (closed captions).
- **Cues**: each `tx3g` sample with text becomes a cue with the sample times. An empty sample is a gap. The text is UTF-8, or UTF-16 after a byte order mark. The reader turns the bold, italic and underline runs of a `styl` box into `<b>`, `<i>` and `<u>` tags, so `ffmpeg -c:s mov_text` output keeps the tags of the SubRip input. It ignores the font, the size and the color of a run, and other modifier boxes such as `hlit`.
- **Closed captions**: each `c608` sample holds a `cdat` box with the CEA-608 byte pairs of field 1, one pair per frame at 29.97 fps. The reader decodes them as [SCC](formats.md#scc) does, so `ffmpeg -i captions.scc -c:s copy captions.mov` gives the cues of `captions.scc`, with the `scc` format data. [`SccReadOptions`](formats.md#scc) and `--scc-roll-up` apply. The reader ignores field 2 (`cdt2`), as SCC files hold field 1 only.
- **CEA-708**: each `c708` sample holds a `ccdp` box with a caption distribution packet of SMPTE 334-2, as each line of an [MCC](formats.md#mcc) file does. The reader reads it as MCC: CEA-708 service 1, else the CEA-608 data of field 1. `MccReadOptions(service: 2)` reads service 2.
- **Edit list**: the reader moves the samples by the empty edits at the start of the `elst` box, and by the media time of the first edit after them. ffmpeg writes an empty edit for a `c608` track that starts after 0 s. A sample that ends before 0 s drops. Later edits do not change the times.
- **Format**: `findFormat()` is `Format::SubRip` for `tx3g`. The cues hold text with SubRip tags, as from an `S_TEXT/UTF8` track of an MKV file. It is `Format::Scc` for `c608`, so `convert movie.mov --track 3` without `--to` writes SCC. It is `Format::Mcc` for `c708`.
- **Detection**: `load()` and `fromString()` without a format know an MP4 file by the `ftyp` box at byte 4.
- **Memory**: the reader reads the sample tables of the wanted track and its samples only. It skips the media data with `fseek()`.
- **Errors**: `extract()` throws `ParsingException` for another codec, such as `wvtt`, and for an encrypted track (`enct` or a `sinf` box). It also throws `ParsingException` for a sample outside the file. The same applies when the `stsz` box lists more sample bytes than the file has.
- **Not read**: CEA-608 data inside the H.264 or HEVC video stream. Tools such as CCExtractor and ffmpeg extract it.
- **Spec**: ISO/IEC 14496-12 for the boxes, 3GPP TS 26.245 for timed text, the [QuickTime File Format](https://developer.apple.com/documentation/quicktime-file-format/closed_captioning_sample_data) for `c608` and `c708` samples.

## MPEG-TS teletext

DVB-T, DVB-S and DVB-C recordings in Europe, the `.ts` files of receivers and TV cards, carry subtitles as teletext pages, for example page 150 or 777. The same calls read them: `Subtitle::tracks()`, `loadTrack()` and `load()` without a format. `MpegTsReader` in the namespace `SubtitleToolbox\Container\MpegTs` has the methods of `MatroskaReader`.

```php
Subtitle::loadTrack('/media/recording.ts', 777)->save('recording.srt');
```

| Property | MPEG-TS value |
|:--- |:--- |
| `container` | `ContainerFormat::MpegTs` |
| `number` | the page number, for example `888`. When a teletext stream lists a page that an earlier stream of the file also lists, its number is the PID times 1,000 plus the page, for example `513888` for PID 513 |
| `codecId` | `dvb_teletext` |
| `format` | `Format::SubRip` |
| `language` | the language of the page in the teletext descriptor, for example `deu` or `ger` |
| `name` | `hearing impaired` for a subtitle page for hearing impaired people, else null |
| `default` | false |
| `forced` | false |

- **Tracks**: `tracks()` lists the pages of the teletext descriptors in the PMT with the teletext type "subtitle page" or "subtitle page for hearing impaired people". It lists DVB bitmap subtitles after the pages, see [DVB subtitles](#dvb-subtitles).
- **Cues**: each state of the page that shows text becomes a cue. A state starts at the PTS of the page header and lasts until the next page header that changes the text. A header with the erase flag clears the page. A header without it keeps the rows, and the rows that follow replace single rows. The same text sent again continues the cue. The last cue ends at the last PTS of the teletext stream, or lasts [`ReadOptions::$lastCueDuration`](read-options.md) when no later PTS exists.
- **Times**: cue times count from the first PTS of the program, as ffmpeg counts them. A recording longer than the PTS cycle of 26.5 hours keeps counting.
- **Text**: a subtitle page shows only the text in its boxes. Each row with text becomes a line. The reader turns red, green, yellow, blue, magenta, cyan and black text into `<font color>` tags, for example `<font color="#ffff00">` for yellow. It ignores double height, flashing, background colors and mosaic graphics.
- **Character sets**: the reader reads the Latin set with its 13 national option subsets, the 3 Cyrillic sets and the Greek set. Packet X/28/0 or M/29/0 picks the group of sets. Without them, the reader takes the group of the language: `pol` gives Polish, `tur` Turkish, `rus`, `ukr` and `bul` Cyrillic, and `gre` Greek, for example. Other languages give Western Europe. The page header picks the set in the group. The reader does not read packet X/26, which some broadcasters use for single accented letters.
- **Transmission errors**: Hamming 8/4 and Hamming 24/18 correct 1 bit error. The reader skips a packet whose address or page header has 2 bit errors, and shows a character with a parity error as a space.
- **Detection**: `load()` and `fromString()` without a format know an MPEG-TS file by the sync byte `0x47` at the start of its first 3 packets of 188 bytes. Blu-ray M2TS files with packets of 192 bytes are not read.
- **Memory**: the reader reads the file once from the start and keeps only the teletext packets of the page. In the tests, a 378 MB file with 10 minutes of 5 Mbit/s video takes 0.4 s and needs less than 3 MB.
- **Errors**: `extract()` throws `ParsingException` for a page in the Arabic or Hebrew set. `open()` throws it for a file that is not MPEG-TS.
- **Spec**: ISO/IEC 13818-1 for the packets, the PAT, the PMT and the PES packets. [ETSI EN 300 468](https://www.etsi.org/deliver/etsi_en/300400_300499/300468/01.17.01_60/en_300468v011701p.pdf) for the teletext descriptor, [ETSI EN 300 472](https://www.etsi.org/deliver/etsi_en/300400_300499/300472/01.04.01_60/en_300472v010401p.pdf) for teletext in PES packets and [ETS 300 706](https://www.etsi.org/deliver/etsi_i_ets/300700_300799/300706/01_60/ets_300706e01p.pdf) for teletext.

## DVB subtitles

Many DVB broadcasts in Europe send subtitles as bitmaps (`dvb_subtitle`), instead of teletext or next to it. Recordings keep them in `.ts` files, and mkvmerge and ffmpeg copy them into MKV `S_DVBSUB` tracks. The same calls read them as image cues, as PGS and VobSub tracks are read. OCR then turns them into text, see [ocr.md](ocr.md).

```php
foreach (Subtitle::tracks('/media/recording.ts') as $track) {
    echo "$track->number $track->codecId $track->language", PHP_EOL;   // 777 dvb_teletext deu, then 2050 dvb_subtitle deu
}
Subtitle::loadTrack('/media/recording.ts', 2050)->recognizeText(OcrEngineChooser::create(), OcrLanguage::German)->save('recording.srt');
```

| Property | MPEG-TS value |
|:--- |:--- |
| `number` | the PID of the stream, for example `2050` for PID 0x802. When a teletext page has that number, or for the second and later service of the same stream, it is the PID times 1,000 plus the index of the service, for example `2050001` |
| `codecId` | `dvb_subtitle` |
| `format` | `Format::Pgs`, so `convert` without `--to` writes a `.sup` file |
| `language` | the language of the service in the subtitling descriptor, for example `deu` |
| `name` | `hearing impaired` for the subtitling types 0x20 to 0x25, else null |
| `default`, `forced` | false |

- **Tracks**: `tracks()` lists each service of the subtitling descriptor (tag 0x59) in the PMT. A **service** is one entry of the descriptor: a language with its composition page and ancillary page. Teletext pages keep their numbers.
- **MKV**: an `S_DVBSUB` track has the format `Format::Pgs`. The first 4 bytes of `CodecPrivate` hold the composition page and the ancillary page. Without them, the reader reads the segments of every page. A block with a duration ends its cue at the latest at the end of the block.
- **Cues**: each display set that shows pixels gives one cue. It ends at the next display set that shows other pixels or none, or at the page time-out. A display set that repeats the same image, such as an acquisition point, continues the cue. A last cue without a page time-out lasts [`ReadOptions::$lastCueDuration`](read-options.md). Times count from the first PTS of the program, as for teletext.
- **Image**: the reader draws the objects into their regions and places the regions on the display of the display definition segment, or on 720x576 without one. One PNG covers all regions with visible pixels, on a transparent background, as for [PGS](ocr.md#pgs). A region without visible pixels drops out, so a page of transparent regions ends the cue.
- **Colors**: the CLUT entries hold Y, Cr and Cb codes of ITU-R BT.601. The reader converts them to RGB with the math of the PGS parser. A CLUT entry with Y 0 is transparent. Regions without a CLUT definition use the default CLUTs of the spec.
- **Pixels**: the reader reads 2-bit, 4-bit and 8-bit pixel code strings, the map tables between them and the non-modifying color. It ends a pixel code string at the end of the region row too. ffmpeg 7.0 ends its 8-bit strings with 1 zero byte instead of 2, and its own decoder reads them the same way. Objects coded as character strings show nothing.
- **Errors**: `extract()` throws `ParsingException` for a region or a set of regions larger than the [size limit](ocr.md#image-cues) of images. The reader skips damaged and unknown segments.
- **Spec**: [ETSI EN 300 743](https://www.etsi.org/deliver/etsi_en/300700_300799/300743/01.06.01_60/en_300743v010601p.pdf) for the segments, [ETSI EN 300 468](https://www.etsi.org/deliver/etsi_en/300400_300499/300468/01.17.01_60/en_300468v011701p.pdf) for the subtitling descriptor, [Matroska subtitles](https://www.matroska.org/technical/subtitles.html) for `S_DVBSUB`.
