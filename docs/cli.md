# Command line tool

`subtitle-toolbox` converts, retimes, checks and fixes subtitle files. Composer installs it as `vendor/bin/subtitle-toolbox`. It needs no package beyond the library.

```sh
vendor/bin/subtitle-toolbox convert movie.srt --to vtt -o movie.vtt
vendor/bin/subtitle-toolbox convert season1/ --to vtt --output-dir out/ --keep-going
vendor/bin/subtitle-toolbox convert movie.sub --to srt -o movie.srt --fps 23.976
vendor/bin/subtitle-toolbox retime movie.srt --shift -2.5 --output movie.fixed.srt
vendor/bin/subtitle-toolbox retime *.srt --from-fps 25 --to-fps 23.976 --output-dir fixed/
vendor/bin/subtitle-toolbox convert movie.srt -o movie.fixed.srt --timing-fix-overlaps --timing-min-gap 0.083 --structure-wrap
vendor/bin/subtitle-toolbox validate movie.srt --preset netflix-en --json
curl -s https://example.com/movie.srt | vendor/bin/subtitle-toolbox convert - --to vtt > movie.vtt
```

## Install without Composer
Every release also ships the tool as a PHAR file and as a container image.

| Form | Needs | Example |
|:--- |:--- |:--- |
| PHAR on the [GitHub release](https://github.com/yama6a/subtitle-toolbox-php/releases) | PHP 8.2 or later with `ext-dom`, `ext-iconv` and `ext-zlib`, plus `ext-curl` for `translate` | `php subtitle-toolbox.phar convert in.srt --to vtt -o out.vtt` |
| Image `ghcr.io/yama6a/subtitle-toolbox-php` | Docker or another container runtime | `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox-php:3.0.0 convert in.srt --to vtt -o out.vtt` |
| Image `ghcr.io/yama6a/subtitle-toolbox-php:tesseract` | the same, for OCR with Tesseract in every language | `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" ghcr.io/yama6a/subtitle-toolbox-php:3.0.0-tesseract convert in.sup --to srt -o out.srt --ocr --ocr-language deu` |

```sh
curl -fsSLO https://github.com/yama6a/subtitle-toolbox-php/releases/latest/download/subtitle-toolbox.phar
php subtitle-toolbox.phar --version
```

- **Version**: the PHAR file, the image tag, the Git tag and the Packagist version are the same string, for example `3.0.0`. The image also has the tags `3.0`, `3` and `latest`. The Tesseract image has the tags `3.0.0-tesseract`, `3.0-tesseract`, `3-tesseract` and `tesseract`.
- **Image**: the tool runs in `/work`, so mount your files there. `--user` makes the tool write files that you own. Without it, the tool runs as `www-data` and cannot write to most mounted folders.
- **Platforms**: the image is for `linux/amd64` and `linux/arm64`.
- **OCR**: `convert --ocr` works in every form with no extra steps, because all include php-glyph-ocr. The Tesseract image adds Tesseract with the `tessdata_fast` models of all its languages, see [ocr.md](ocr.md#tesseract). It is about 340 MB larger.
- **Memory**: the tool raises a `memory_limit` of 128 MB, the PHP default, to 512 MB. The image sets 512 MB too. The tool keeps any other value, so raise it for larger files, for example `php -d memory_limit=1G vendor/bin/subtitle-toolbox convert big.json --to srt -o big.srt`. A word-level JSON transcript of 50,000 cues needs about 520 MB.

## Commands
| Command | Does |
|:--- |:--- |
| `convert` | writes each input in the format of `--to` or in its own format, and runs OCR, text, structure and timing edits on the way, see [Convert](#convert) |
| `retime` | shifts and scales all cue times, or fits them to a video with another frame rate, see [Retime](#retime) |
| `info` | prints the format, the cue count and statistics, as text or with `--json`. Lists the tracks of an MKV, WebM or MP4 file |
| `validate` | prints each broken rule, as text or with `--json`, see [Validate](#validate) |
| `sync` | retimes a subtitle to a reference subtitle or to the speech, see [Sync](#sync) |
| `diff` | lists the added, removed and changed cues of two files, see [Diff](#diff) |
| `translate` | translates the cue text with DeepL, Google Cloud Translation or an OpenAI-compatible service, see [Translate](#translate) |
| `dual` | merges two languages into one file, see [Dual](#dual) |
| `hls` | cuts a subtitle into WebVTT segments and writes an HLS playlist, see [HLS](#hls) |
| `formats` | lists the format names and extensions for `--from` and `--to` |

- **Help**: `subtitle-toolbox help CMD` and `subtitle-toolbox CMD --help` list the options of a command. For `convert`, they list the common options and the option groups, see [Order](#order).
- **Command list**: `subtitle-toolbox`, `subtitle-toolbox help` and `subtitle-toolbox help help` list the commands and exit with 0.
- **Help width**: every help line fits in 80 columns.
- **Version**: `subtitle-toolbox --version` prints the installed release, for example `3.0.0`, or `dev` in a Git checkout.
- **Exit code**: see the table. A batch with a failed file exits with 3, also when another file broke a rule.

| Code | Meaning | Example |
|:--- |:--- |:--- |
| 0 | every file succeeded, and `validate` and `diff` found nothing | `validate movie.srt --preset bbc` with no broken rule |
| 1 | a result: `validate` found a broken rule, or `diff` found a difference | `diff old.srt new.srt` for 2 files that differ |
| 2 | a usage error, before the tool reads a file | an unknown option, a directory without subtitle files, an output that exists, a number too large for a float such as `--shift 1e999`, `--ass-karaoke-tag` with `--to srt`, an unknown `--ass-style` field, `validate --video-fps` without `--preset netflix-en`. `--ocr` without an installed OCR engine or without the data of the `--ocr-language`, see [OCR](#ocr). `translate` without `ext-curl` or without an API key, see [Translate](#translate) |
| 3 | a file could not be read or written | a missing input, a file that does not parse, an output that cannot be created, content that the output format cannot hold such as 5 lines in SCC, a side file that is missing or does not parse, a translation service that answers with an error |

- **Failures**: a failed file prints `FILE: MESSAGE` to standard error. The message of a library exception starts with its class, for example `ParsingException (Error #100):`. Any other PHP error prints its class and message, for example `movie.json: TypeError: ...`, and fails that file with exit code 3. An error outside a file, such as an output that cannot be created, prints `Error: MESSAGE` and exits with code 3. A PHP error outside a file also prints its class.
- **Side files**: a side file is a file that an option names besides the inputs. These options take a side file: `--reference`, `--silence-log`, `--mask-words`, `--errors-replace-list`, `--snap-shot-changes` and `--ocr-database`. The tool reads each side file once, after the checks of the arguments. So `convert a.srt b.srt --to vtt --mask-words missing.txt` fails with exit code 2, because 2 inputs need `--output-dir`. A side file that is missing or does not parse stops the run before the first input, also with `--keep-going`. The tool prints `Error: PATH: MESSAGE`, for example `Error: words.txt: The file does not exist.`, and exits with code 3.
- **Stable parts**: see [compatibility.md](compatibility.md) for the parts of the tool that semantic versioning covers.
- **Messages**: where a library message names a PHP method or option, the tool names the CLI option. For example "Call loadTrack() with one of them" becomes "Pass --track N with one of them".

## Input and output
- **Inputs**: a file, a directory, a glob such as `"season1/*.srt"`, or `-` for standard input. A directory gives its files with a known extension. The tool counts the inputs after it expands directories and globs.
- **Positional files**: only inputs with the same role, so their order does not matter. A file with another role takes an option, for example `sync --reference FILE` and `dual --primary FILE --secondary FILE`. `diff OLD NEW` keeps 2 positional files, as `diff` and `git diff` do.
- **Input format**: `--from`. Without it, the tool detects the format from the content. When that fails, it uses the file extension. Chapters and cloud speech-to-text JSON need `--from`, for example `--from deepgram` or `--from ffmeta-chapters`. `--from` and `--to` also take the aliases `ytchapter`, `podcast`, `ogm` and `ffmeta`. The tool reads like `Subtitle::loadAutoDetectFormat()`, see [formats.md](formats.md#load-and-save).
- **Output**: never positional. `convert`, `retime`, `sync`, `translate` and `dual` write one input to standard output, or to the file of `-o FILE` (`--output FILE`). Several inputs need `--output-dir DIR`. `hls` always needs `--output-dir`.

| Call | Writes |
|:--- |:--- |
| `convert movie.srt --to vtt` | standard output |
| `convert movie.srt --to vtt -o movie.vtt` | `movie.vtt` |
| `convert a.srt b.srt --to vtt --output-dir out` | `out/a.vtt` and `out/b.vtt` |
| `convert a.srt b.srt --to vtt` | nothing. Exit code 2, because 2 inputs need `--output-dir` |
| `retime a.srt b.srt --shift 1 -o fixed.srt` | nothing. Exit code 2, because `-o` takes one input |

- **Output names**: in `--output-dir`, each output takes the base name of its input and the extension of the output format. An input keeps its extension when the output format uses it, so `movie.ssa` with `--to ass` writes `out/movie.ssa`.
- **Output format**: `--to FORMAT`. Without `--to`, `convert`, `retime`, `sync` and `translate` keep the input format, for example `convert movie.srt --timing-fix-overlaps -o fixed.srt`. `dual` keeps the format of the primary file. A format that the tool can read but not write, such as VobSub, needs `--to`. Without it, the file fails with exit code 3.
- **Output extension**: the extension of `-o` never picks the format. An extension of another format than `--to` fails with exit code 2, for example `convert movie.srt --to srt -o movie.vtt`. Without `--to`, `retime`, `sync` and `dual` keep the input format whatever the extension, so `retime movie.srt --shift 1 -o movie.vtt` writes SubRip. `convert` without `--to` fails with exit code 2 when the extension belongs to another format that the tool writes, for example `convert movie.srt -o movie.vtt`. Pass `--to vtt` to convert. For standard input, the format is known only after the read, so the file fails with exit code 3. An extension of no format, such as `.bak`, works.
- **Never overwrite**: no command overwrites a file. This covers subtitle outputs, the files of `--mute-edl` and `--mute-filter`, and the `hls` playlist and segments.
- **Check before the work**: before it reads the first input, the tool collects every output path. It fails with exit code 2 and writes nothing in these cases:

| Case | Example |
|:--- |:--- |
| an output exists | `-o movie.vtt` with an existing `movie.vtt` |
| 2 inputs would write the same output | `a/movie.srt b/movie.srt --output-dir out` |
| an output is a file that the command reads | `-o movie.srt` with the input `movie.srt` |
| `-o` ends with a slash, or its last part is `.` or `..` | `-o out/`, `-o out/.`, `-o ..` |
| `-o` names a directory | `-o out` with an existing directory `out`. Pass `--output-dir out` |
| `--output-dir` names a file, also for `hls` | `--output-dir movie.srt` |

- **Read files**: a read file is a file that the command reads. The inputs, the `.sub` file of a VobSub input and each side file are read files. A file passed twice, such as `movie.srt ./movie.srt`, counts once.
- **Output paths**: the tool resolves `..` in an output path before it checks the path or creates a directory. `-o new/../keep.vtt` writes `keep.vtt` and creates no directory `new`. With an existing `keep.vtt`, it fails with exit code 2.
- **Race**: the tool creates each file with exclusive create (`fopen($path, 'x')`). When another process creates the file between the check and the write, the create fails. The tool then removes every file that it wrote in the run and exits with code 3.
- **Standard output**: the tool does not check it. A shell redirect such as `> movie.vtt` overwrites a file. `-o -` also writes standard output.
- **Standard input**: `-` takes `-o FILE`, not `--output-dir`. With `--output-dir`, the tool fails with exit code 2.
- **Batch**: the tool prints one line per file and a summary. It stops at the first failed file, unless you pass `--keep-going`.
- **Option names**: `--no-X` always turns X off, for example `--no-bom`. A time option is in seconds, unless its name ends in `-frames`.
- **Defaults**: `subtitle-toolbox help COMMAND` prints the default of each option after `Default:`. This page does not repeat the values. Without the option, the tool passes no value, so the library default applies.
- **Choice values**: a value from a fixed list ignores case. `--line-ending CRLF`, `--mode Top-Bottom`, `--preset BBC` and `--errors-enable MusicNotes` work.
- **Encoding**: `--encoding` names the encoding of input that is not UTF-8, for example `Windows-1252`. It replaces code page detection. Files with a BOM, valid UTF-8 files and UTF-16 files stay as they are. See [encodings.md](encodings.md#order-of-the-checks).
- **Output bytes**: `--line-ending lf|crlf`, `--bom` and `--no-bom`.
- **Broken files**: `--lenient` skips or repairs broken cues and prints one warning for each broken cue. It applies to each file that a command reads, also a second file or a `--reference`. Without `--lenient`, the error message of a file that fails to parse is followed by the line `Pass --lenient to skip or repair broken cues.` on standard error. Chapter files get no such line. See [lenient-parsing.md](lenient-parsing.md).
- **Frame rate**: see [Frame rates](#frame-rates).
- **SCC roll-up**: `--scc-roll-up lines` reads each row of SCC roll-up captions as one cue, as `SccReadOptions::$rollUp`. `screen` gives one cue per screen. See [SCC](formats.md#scc).
- **Word timestamps**: `--word-timestamps` keeps the word times of the speech-to-text JSON formats, YouTube timed text and Podcasting 2.0 transcripts. `--structure-resegment`, `--karaoke` and `--ass-karaoke-tag` turn it on.
- **MKV and WebM**: `--track` picks a subtitle track, see [MKV and WebM](#mkv-and-webm).
- **Image cues**: `--skip-image-cues` leaves out image cues without text in place of failing.
- **WebVTT output**: WebVTT has classes for 8 text colors only. The tool drops other colors and prints each one on standard error, for example `movie.srt: Cue #3 at 4 s: dropped the color "#123456", because WebVTT has classes for 8 colors only.` See [WebVTT](formats.md#webvtt).
- **SCC output**: `--scc-fit` wraps lines, replaces characters, and delays or drops captions that SCC cannot hold, in place of failing. It prints each change on standard error. See [SCC](formats.md#scc).

## Frame rates
| Option | Sets | Commands |
|:--- |:--- |:--- |
| `--input-fps RATE` | the frame rate of a MicroDVD input without a `{1}{1}<fps>` first line, and of CSV or TSV times in `hh:mm:ss:ff`, as `MicroDvdReadOptions::$frameRate` and `CsvReadOptions::$frameRate` | all that read a file |
| `--output-fps RATE` | the frame rate of MicroDVD and iTT output, as `MicroDvdWriteOptions::$frameRate` and `IttWriteOptions::$frameRate` | all that write a file |
| `--video-fps RATE` | the frame rate of the video for the frame rules, see [Timing](#timing) and [Validate](#validate) | `convert`, `validate` |
| `--fps RATE` | each of the 3 options above that the command has | all that read a file |

- **Override**: a specific option wins over `--fps`. `convert movie.sub --to microdvd -o new.sub --fps 25 --output-fps 23.976` reads at 25 fps and writes at 23.976 fps.
- **Output default**: without `--output-fps`, the frame rate of the output comes from the input, see [Load and save](formats.md#load-and-save).
- **Formats**: `--from` and `--to` always name formats. `retime` changes the frame rate with `--from-fps` and `--to-fps`.

## Times
Every option with the value `SECONDS` takes a time, in every command. `--sync` takes times too. A time has one of these shapes:

| Shape | Example | Seconds |
|:--- |:--- |:--- |
| seconds | `2.5`, `-2.5` | 2.5, -2.5 |
| `h:mm:ss` with an optional fraction | `01:02:03.456`, `00:00:02,500` | 3723.456, 2.5 |
| `m:ss` with an optional fraction | `01:02.5` | 62.5 |

- **Timecodes**: the shapes are those of [`Timecode::parse()`](subtitle.md#cues-from-timecode-strings). A minus sign before a timecode makes it negative, for example `--shift=-00:00:02,500` or `--shift -00:00:02,500`.
- **Errors**: another value, such as `1:2:3:4:5`, is a usage error, exit code 2. The message quotes the value.
- **Limit**: a time must be below 100000 hours, as in the parsers. `--shift 1e300` and `--shift 100000:00:00` are usage errors, exit code 2.

## Formats and file extensions
Run `subtitle-toolbox formats` for the list. For an extension that two formats share, see [Shared extensions](formats.md#the-format-enum).

- **Output extension**: `--to` sets the output format, never the extension of `-o`. So `--to mpl2 -o film.txt` writes MPL2, and `--to txt -o film.txt` writes plain text. Without `--to`, the output has the format of the input. So pass `--to json` to convert Whisper JSON to the library JSON.
- **`.sub`**: MicroDVD. Pass `--from subviewer` for SubViewer. A directory skips a `.sub` file that has an `.idx` file next to it.
- **VobSub**: pass the `.idx` file. The tool reads the `.sub` file next to it. Standard input does not work.
- **`.json` and `.txt` input**: format detection finds 6 of these formats by their content. These are the library JSON, Whisper JSON, YouTube json3, Podcasting 2.0 transcripts, MPL2 and TMPlayer. Other `.json` and `.txt` input fails without `--from`.
- **Other output formats**: pass `--to`, for example `--to mpl2`, `--to podcast-transcript` or `--to youtube-chapters`.
- **CSV and TSV**: see [Load and save](formats.md#load-and-save) for the delimiter of the output.

## MKV and WebM
Every command reads a subtitle track of an MKV, WebM or MP4 file with [`Subtitle::loadTrack()`](mkv.md). `--track` takes the track number that `info` lists.

```sh
vendor/bin/subtitle-toolbox info movie.mkv
vendor/bin/subtitle-toolbox convert movie.mkv --to srt -o movie.srt --track 3
vendor/bin/subtitle-toolbox convert movie.mkv --to srt -o movie.srt --track 5 --ocr
```

```
movie.mkv
  Container: matroska
  Track 3: S_TEXT/UTF8, de, "Deutsch (Forced)", forced
  Track 4: S_TEXT/ASS, eng, "English", default
  Track 5: S_HDMV/PGS, eng
```

- **Detection**: the tool knows an MKV, WebM or MP4 file by its first 8 bytes, not by its extension. Standard input works too.
- **Memory**: the tool reads an MKV, WebM or MP4 file the same way as `MatroskaReader` and `Mp4Reader`, see [mkv.md](mkv.md). Standard input is different. The tool reads the whole input into memory first.
- **Track**: a file with one subtitle track needs no `--track`. For a file with more, the tool fails and lists the tracks.
- **Format**: an `S_TEXT/UTF8` track is SubRip, ASS and SSA tracks are ASS, `S_TEXT/WEBVTT` is WebVTT, `S_HDMV/PGS` is PGS and `S_VOBSUB` is VobSub. An MP4 `tx3g` track is SubRip. Without `--to`, `convert`, `retime` and `sync` keep this format. `convert movie.mkv --to srt --track 3 --output-dir out` writes `out/movie.srt`.
- **Second file**: `diff` applies `--track` to both files. `--new-track` sets the track of the new file, for example `diff old.mkv new.mkv --track 3 --new-track 8`. `dual` takes `--primary-track` and `--secondary-track`.
- **Info**: without `--track`, `info` lists the tracks of a file whose extension names no subtitle format, such as `.mkv`, `.webm` and `.mp4`. The JSON object has `file`, `container` and `tracks`. Each track has `number`, `codecId`, `language`, `name`, `default` and `forced`. With `--track`, `info` prints the statistics of the track.
- **Directories**: a directory argument skips MKV, WebM and MP4 files. Pass them by name or with a glob.
- **Errors**: `S_DVBSUB` and `c608` tracks, encrypted tracks and tracks with bzlib or LZO compression fail, see [mkv.md](mkv.md).

## Retime
`retime` changes the cue times with one or more edits. It applies them in this order: `--sync`, `--shift`, `--scale`, then `--from-fps` and `--to-fps`. The word timestamps in the cue text move with the cues.

```sh
vendor/bin/subtitle-toolbox retime trip.srt --shift -1.5 --scale 1.001 -o trip.fixed.srt
vendor/bin/subtitle-toolbox retime movie.sub --from-fps 25 --to-fps 23.976 --input-fps 25 --to srt -o movie.srt
vendor/bin/subtitle-toolbox retime movie.srt --sync first=00:00:12.5 --sync '#120=00:42:10,300' --sync last=01:41:05 -o fixed.srt
```

| Option | Calls |
|:--- |:--- |
| `--shift SECONDS` | [`shift()`](editing.md#retiming) with the [time](#times) to add to every time. A negative value shows the cues earlier |
| `--shift-after SECONDS` | `shift()` with the [time](#times) `$fromTime`, so only the cues that start at this time or later move. Needs `--shift` |
| `--shift-before SECONDS` | `shift()` with the [time](#times) `$toTime`, so only the cues that start before this time move. It must be after `--shift-after`. Needs `--shift` |
| `--scale FACTOR` | `scale()`. `--scale 1.001` fixes a subtitle that drifts 3.6 s per hour |
| `--sync OLD=NEW` | `syncByPoints()` with a point that moves `OLD` to the [time](#times) `NEW`. Repeatable |
| `--from-fps RATE`, `--to-fps RATE` | `convertFrameRate()`. `--from-fps 25 --to-fps 23.976` fits a subtitle for a 25 fps release to a 23.976 fps video |

- **Negative times**: a time that becomes negative becomes 0.
- **Time limit**: an edit that moves a cue end to 100000 hours or more, such as `--scale 1e300`, fails that file with exit code 3.
- **Shift range**: the start time of a cue decides if it moves. `--shift 2 --shift-after 10:00 --shift-before 15:00` moves a cue from 14:59 to 15:03 as a whole.
- **Sync points**: `OLD` is a [time](#times), `first` or `last` for the start of the first or last cue, or `#N` for the start of cue N, counted from 1. 1 point shifts all cues.
- **Sync errors**: `--sync` with `--shift` or `--scale` is a usage error, exit code 2. So are points whose old or new times do not increase in the order of the options. A point that does not fit a file, such as `#120` in a file with 80 cues, fails that file with exit code 3.

## Convert
`convert` reads each input, runs the edits of its options, and writes the result in the format of `--to`, or else in the format of the input. One call can run OCR, fix text, remove hearing-impaired annotations (`--sdh`), retime and convert:

```sh
vendor/bin/subtitle-toolbox convert movie.sup --to srt -o movie.srt --ocr --errors-fix --sdh --shift -1.5
vendor/bin/subtitle-toolbox convert lecture.json --to srt -o lecture.srt --structure-resegment --timing-min-duration 1
vendor/bin/subtitle-toolbox convert song.json --to ass -o song.ass --ass-karaoke-tag kf
vendor/bin/subtitle-toolbox convert season1/*.srt --output-dir fixed/ --errors-fix
```

### Order
`convert` always runs the edits in this order, whatever the order of the options. The options form groups. `convert --help GROUP` lists the options of one group, and `convert --help all` lists every option. A word after `--help` that holds a dot or a slash, or names a file, is not a group name. So `convert in.srt -h out.srt` prints the convert help. Most groups use the group name as the option prefix, for example `--structure-wrap` and `--timing-min-gap`. The `retime`, `text` and `masking` groups have no prefixed option. In the `snap` group, `--video-fps` has no prefix. An option that turns a default off puts `--no-` before the prefix, for example `--no-snap-chain`.

| Step | Group | Options | Why here |
|:--- |:--- |:--- |:--- |
| 1. Read | | input options | |
| 2. Forced | `forced` | `--forced-only` | OCR then reads only the cues that stay |
| 3. OCR | `ocr` | `--ocr` | the later steps need text |
| 4. Text | `errors`, `sdh`, `replace`, `text` | `--errors-fix`, `--sdh`, `--replace`, `--speakers`, `--case`, `--strip-tags`, `--rtl` | the removal of hearing-impaired annotations changes the line lengths, so it runs before wrapping |
| 5. Structure | `structure` | `--structure-resegment`, `--structure-unwrap`, `--structure-merge-same-time`, `--structure-merge-short`, `--structure-split-long`, `--structure-wrap`, `--structure-merge-duplicates` | |
| 6. Timing | `retime`, `snap`, `timing` | `--shift`, `--scale`, `--from-fps` and `--to-fps`, `--snap-shot-changes`, `--timing-fix-overlaps`, `--timing-min-duration`, `--timing-lead-in`, `--timing-lead-out`, `--timing-max-duration` | splits in step 5 create new cues |
| 7. Masking | `masking` | `--mask-words` | the mute ranges of `--mute-edl` and `--mute-filter` need the final times |
| 8. Karaoke | `karaoke` | `--karaoke` | it multiplies the cues |
| 9. Write | `ass` | output options, `--ass-karaoke-tag`, `--ass-style` | |

### OCR and forced cues
| Option | Effect |
|:--- |:--- |
| `--ocr` | reads the text of image cues, see [OCR](#ocr) |
| `--ocr-engine ENGINE` | `tesseract` or `glyph`. Default: `tesseract` when it is installed |
| `--ocr-language CODE` | the Tesseract language, for example `deu` or `deu+eng` |
| `--ocr-database FILE` | the `.nocr` glyph database for `--ocr`. It selects the glyph engine |
| `--forced-only` | keeps only the [forced cues](subtitle.md#forced-cues) |

### Text
| Option | Effect |
|:--- |:--- |
| `--errors-fix` | [`CommonErrorFixer::apply()`](text.md#fixing-common-errors) with all default fixes |
| `--errors-enable NAME[,NAME]` | adds fixes that are off by default to `--errors-fix`, by their `CommonErrorRule` value: `doubleApostrophes`, `loneLowercaseI`, `unneededPeriods`, `dialogueOnOneLine`, `musicNotes` and `sentenceStartCase`. An unknown name exits with code 2 and lists the valid names |
| `--errors-replace-list FILE` | adds a Subtitle Edit OCR replace list to `--errors-fix` |
| `--errors-list-fixes` | prints each change of `--errors-fix` to standard error, for example `movie.srt: cue 15: ocrLowercaseL: "lt's late." -> "It's late."`. A byte that is not valid UTF-8 prints as U+FFFD |
| `--sdh` | removes everything that [`HearingImpairedRemover::apply()`](text.md#hearing-impaired-annotations) removes by default. It also removes a cue with no text left |
| `--sdh-keep-square-brackets`, `--sdh-keep-parentheses`, `--sdh-keep-speaker-labels`, `--sdh-keep-music-lines` | turns off one rule of `--sdh` |
| `--sdh-any-case-labels` | also removes speaker labels that are not upper case, such as `Baker:` |
| `--sdh-lyrics` | also removes text between two music symbols |
| `--sdh-brackets PAIR` | also removes text between this pair, for example `"{}"` or `"**"`. Repeatable |
| `--replace FROM=TO` | [`replaceText()`](text.md#transforms) on the text between tags. Repeatable. The first `=` ends FROM |
| `--replace-regex` | reads each FROM as a regular expression with delimiters, for example `--replace '/\.{4,}/=...'` |
| `--replace-ignore-case` | matches FROM in any case |
| `--speakers MODE` | `prefix`, `dashes`, `colors` or `from-prefix`. Calls `SpeakerLabels::apply()` with `to: SpeakerStyle::Prefix`, `DialogueDashes` or `Colors`, or with `readPrefixes: true`, and the other options at their defaults, see [Speakers](text.md#speakers) |
| `--case MODE` | `upper`, `lower` or `sentence`, with `changeCase()` |
| `--strip-tags` | removes all formatting tags, such as `<i>` and `<font>` |
| `--rtl MODE` | `fix` calls [`fixRightToLeft()`](text.md#transforms), which wraps right-to-left lines in Unicode embedding marks. `clean` calls `removeBidiControls()`, which removes them and all other bidi controls |
| `--language CODE` | the language of `--case` and `--errors-fix`, for example `en`, `de-AT` or `tr`. `tr` and `az` map `i` to `İ` and `ı` to `I`. Without it, `--errors-fix` takes the `language` metadata |

### Masking
| Option | Effect |
|:--- |:--- |
| `--mask-words FILE` | masks the words of a word file, as [`ProfanityFilter::apply()`](text.md#profanity-filter) does |
| `--mask STYLE` | `stars`, `first-letter`, `remove`, or `none`. `none` keeps the text and only finds the times for `--mute-edl` and `--mute-filter` |
| `--mute-edl FILE` | writes the times of the matches to an EDL (edit decision list) file with [`MuteRange::toEdl()`](text.md#profanity-filter), for Kodi and MPlayer |
| `--mute-filter FILE` | writes the FFmpeg volume filter of `MuteRange::toFfmpegVolumeFilter()` |
| `--mute-padding SECONDS` | widens each time range on both sides. The value is a [time](#times) |

```sh
vendor/bin/subtitle-toolbox convert movie.srt --to srt -o clean.srt --mask-words words.txt --mute-filter mute.txt --mute-padding 0.1
ffmpeg -i movie.mp4 -af "$(cat mute.txt)" -c:v copy clean.mp4
```

- **Mute files**: they need `--mask-words` and one input file. They hold the times after `--shift`, `--scale`, snapping and the timing fixes.
- **Mute file names**: `--mute-edl` and `--mute-filter` must name 2 different files that do not exist. Neither may name the subtitle output or a file that the command reads. Otherwise the tool fails with exit code 2 before it writes a file.
- **No match**: the filter file is empty. Then leave out `-af`.

### Structure
[editing.md](editing.md) describes each method.

| Option | Calls |
|:--- |:--- |
| `--structure-resegment` | `Resegmenter::apply()` with `ResegmentMode::ByWords`. `--structure-max-word-gap` sets `maxWordGap` as a [time](#times). It turns on `--word-timestamps` |
| `--structure-unwrap` | `unwrapLines()`. Each dialogue dash line starts a line of its own |
| `--structure-merge-same-time` | `mergeSameTimeCues()` with the default `tolerance` of 0. It runs after `--structure-unwrap`, so the lines of each joined cue stay apart |
| `--structure-merge-short` | `mergeShortCues()` with the default options |
| `--structure-split-long` | `Resegmenter::apply()` with `ResegmentMode::SplitLong` and the default options |
| `--structure-wrap` | `wrapLines()` with `--structure-max-cpl` and `--structure-max-lines` |
| `--structure-merge-duplicates` | `removeDuplicateCues()` with the default `maxGap` of 0. Joins same-text cues that overlap or touch and have the same alignment, forced flag and format data |
| `--structure-max-cpl CHARS` | `maxCharactersPerLine` of `--structure-resegment`, `--structure-merge-short`, `--structure-split-long` and `--structure-wrap` |
| `--structure-max-lines LINES` | `maxLinesPerCue` of `--structure-resegment`, `--structure-merge-short`, `--structure-split-long` and `--structure-wrap` |

- **Limits without their fix**: `--structure-max-cpl`, `--structure-max-lines` and `--timing-min-gap` alone are a usage error, exit code 2. The message names the fix options that use them.

### Timing
`--shift`, `--shift-after`, `--shift-before`, `--scale`, `--sync`, `--from-fps` and `--to-fps` work as in [Retime](#retime).

| Option | Calls |
|:--- |:--- |
| `--snap-shot-changes FILE` | [`ShotChangeTiming::apply()`](editing.md#shot-changes-and-gaps) with the shot changes of the file. The file is the log of the FFmpeg `showinfo` filter, or it has one time per line in seconds or `hh:mm:ss.mmm` |
| `--video-fps RATE` | `frameRate`, the frame rate of the shot changes and of the frame options. Required with the `--snap-` options |
| `--snap-window-frames FRAMES` | `snapWindowFrames` |
| `--snap-min-gap-frames FRAMES` | `minGapFrames` |
| `--snap-min-duration-frames FRAMES` | `minDurationFrames` |
| `--no-snap-chain` | `chain: false`, see [Closing gaps](editing.md#closing-gaps) |
| `--timing-fix-overlaps` | `fixOverlaps()` with `--timing-min-gap` seconds |
| `--timing-min-duration SECONDS` | `extendShortCues()` with this [time](#times) and `--timing-min-gap` |
| `--timing-lead-in SECONDS` | `addLeadInOut()` with this [time](#times) as the lead-in and `--timing-min-gap` |
| `--timing-lead-out SECONDS` | `addLeadInOut()` with this [time](#times) as the lead-out and `--timing-min-gap` |
| `--timing-max-duration SECONDS` | `limitLongCues()` with this [time](#times) |
| `--timing-min-gap SECONDS` | the gap of `--timing-fix-overlaps`, `--timing-min-duration`, `--timing-lead-in` and `--timing-lead-out`, a [time](#times) |

```sh
ffmpeg -i movie.mp4 -vf "select='gt(scene,0.3)',showinfo" -f null - 2> scenes.log
vendor/bin/subtitle-toolbox convert movie.srt --to srt -o movie.timed.srt --video-fps 24 --snap-shot-changes scenes.log
```

- **Gaps only**: without `--snap-shot-changes`, a `--snap-` option such as `--snap-min-gap-frames 2` only [closes gaps](editing.md#closing-gaps).
- **Frame rates**: `--input-fps` sets only the frame rate of the input. It does not set `--video-fps`.
- **Timing order**: the `timing` group runs `--timing-fix-overlaps` first, then `--timing-min-duration`, then the lead-in and lead-out, then `--timing-max-duration`. So no cue lasts longer than `--timing-max-duration`, also after a lead-out. One of `--timing-lead-in` and `--timing-lead-out` alone uses 0 for the other.

### Karaoke and ASS output
| Option | Effect |
|:--- |:--- |
| `--karaoke` | writes one cue per word with the active word styled, with [`WordHighlight::apply()`](text.md#word-highlight-and-karaoke) |
| `--karaoke-style TAG` | `b`, `i`, `u`, `s` or `'font color="#ffff00"'` |
| `--ass-karaoke-tag TAG` | `k`, `kf` or `ko`, the ASS tag for word timestamps, see [formats.md](formats.md#ass-and-ssa). Needs ASS output. Pass only one of `--karaoke` and `--ass-karaoke-tag` |
| `--ass-style STYLE` | changes the `Default` style, for example `'Fontname=Roboto,Fontsize=48,Outline=2'`, see [formats.md](formats.md#ass-and-ssa). Needs ASS output |

- **Library only**: the cumulative mode and the word limit of `WordHighlightOptions` have no option. Call `WordHighlight::apply()` for them.

## JSON output
`info`, `validate` and `diff` print JSON with `--json`: a list with one object for each input. For `diff`, the list holds one object for its pair of files. A file that fails has no object, so the list is `[]` when all files fail.

| Command | Object |
|:--- |:--- |
| `info` | `file`, `format`, `encoding`, `metadata`, `statistics`, `imageCues` and `warnings`. `statistics` is `SubtitleStatistics::toArray()`, with `gaps` and a `mostUsedWords` list of `{"word", "count"}`. A statistic without data is null, for example `gaps` of a file with 1 cue. The text output prints `-`. `encoding` is `Subtitle::findSourceEncoding()`, for example `Windows-1252`, and null for a binary format |
| `info` of an MKV, WebM or MP4 file without `--track` | `file`, `container` with the value `matroska` or `mp4`, and `tracks` |
| `validate` | `file`, `format`, `valid`, `violations` and `warnings`. A violation has `cueIndex`, `rule`, `value`, `infinite` and `limit` |
| `diff` | `oldFile`, `newFile`, `equal`, `differences`, `oldWarnings` and `newWarnings`. A difference has `kind`, `oldIndex`, `newIndex`, `old` and `new`. A cue has `start`, `end`, `lines` and `forced` |

- **Indexes**: `cueIndex`, `oldIndex`, `newIndex` and `blockIndex` start at 0, as in the library. The text output counts cues from 1.
- **Standard input**: `file`, `oldFile` and `newFile` hold `-` for standard input. The text output prints `stdin`.
- **Limits**: `limit` is null for a rule without a number limit, such as `noOverlap`.
- **Infinite values**: a cue with text and a duration of 0 has infinite characters per second. JSON cannot hold infinity, so its violation has `"value": null` and `"infinite": true`. The text output prints `INF`.
- **Warnings**: a list of the parse warnings of the file, empty without `--lenient`. A warning has `lineNumber`, `blockIndex`, `message` and `action`, see [lenient-parsing.md](lenient-parsing.md).

## Info
- **Warnings**: with `--lenient`, `info` prints `Warnings: 1` for a file with one broken cue.
- **MKV and WebM**: see [MKV and WebM](#mkv-and-webm).

## Validate
`--preset` takes `netflix-en` or `bbc`, see [validation.md](validation.md#presets). A rule option overrides the value of the preset. `--video-fps` sets the frame rate for the 2-frame gap of `netflix-en`.

| Option | Rule |
|:--- |:--- |
| `--max-cps`, `--max-cpl`, `--max-lines` | `maxCharactersPerSecond`, `maxCharactersPerLine`, `maxLinesPerCue` |
| `--min-duration`, `--max-duration`, `--min-gap` | `minDuration`, `maxDuration`, `minGap`. Each value is a [time](#times) |
| `--max-wpm`, `--min-seconds-per-word` | `maxWordsPerMinute`, `minSecondsPerWord`. `--min-seconds-per-word` is a [time](#times) |
| `--max-speakers`, `--dialogue-dash STYLE`, `--allowed-characters LIST` | `maxSpeakersPerCue`, `dialogueDashStyle`, `allowedCharacters` |
| `--check-overlaps`, `--check-empty-cues`, `--check-double-spaces` | `noOverlap`, `noEmptyCues`, `noDoubleSpaces` |
| `--check-leading-or-trailing-spaces`, `--check-unbalanced-tags`, `--check-all-caps-lines` | `noLeadingOrTrailingSpaces`, `noUnbalancedTags`, `noAllCapsLines` |

```sh
vendor/bin/subtitle-toolbox validate movie.srt --preset bbc --check-unbalanced-tags --dialogue-dash '- '
```

## Sync
`sync` finds the scale and the offset with [`ReferenceSync`](sync.md), applies them and prints them to standard error.

```sh
vendor/bin/subtitle-toolbox sync movie.de.srt --reference movie.en.srt -o movie.de.synced.srt

ffmpeg -i movie.mkv -af silencedetect=noise=-30dB:d=0.4 -f null - 2> silence.log
vendor/bin/subtitle-toolbox sync movie.de.srt --silence-log silence.log --media-duration 5400 -o movie.de.synced.srt
```

```
movie.de.srt: scale 1.04271, offset -2.3 s, score 0.89
```

| Option | Sets |
|:--- |:--- |
| `--reference FILE` | the subtitle in sync with the video, in any format that the tool reads. A Whisper JSON transcript of the audio also works |
| `--silence-log FILE`, `--media-duration SECONDS` | the speech in an FFmpeg `silencedetect` log as the reference, with [`SpeechReference`](sync.md#sync-to-speech). The value is a [time](#times) |
| `--min-offset SECONDS`, `--max-offset SECONDS` | `minOffset` and `maxOffset`, see [sync.md](sync.md#sync-to-a-reference-subtitle) for their limits. Each value is a [time](#times) |
| `--no-scale` | `searchScale: false` |
| `--max-splits SPLITS`, `--split-penalty SCORE` | `maxSplits` and `splitPenalty` |

- **Score**: below 0.5, the tool also prints that the files likely do not match. The exit code stays 0.
- **Splits**: for each part, the tool prints a line such as `movie.de.srt: from 414.32 s: offset 147.7 s`.
- **Reference**: the tool detects the format of the reference. `--from` and `--track` apply only to the input.

## Diff
`diff` compares an old and a new file with [`SubtitleDiff`](compare.md) and prints `toText()`. The files can have different formats. The exit code is 1 when they differ, as with the Unix `diff` command. Equal files give no output. The first argument must name one file. A directory or a glob that matches more than one file fails with exit code 2.

```sh
vendor/bin/subtitle-toolbox diff episode1_v1.srt episode1_v2.srt --ignore-formatting
```

| Option | Sets |
|:--- |:--- |
| `--time-tolerance SECONDS` | `timeTolerance`. The value is a [time](#times) |
| `--ignore-formatting`, `--ignore-whitespace`, `--text-only` | `ignoreFormatting`, `ignoreWhitespace`, `textOnly` |
| `--new-from FORMAT`, `--new-track NUMBER` | the format and the MKV or WebM track of the new file. Without them, `--from` and `--track` apply to the new file as well. `--track` is ignored for a new file that has no tracks, such as an SRT file. `--encoding` always applies to both files |
| `--json` | prints JSON, see [JSON output](#json-output) |

- **Standard input**: only the old file can be `-`. `diff old.srt -` fails with exit code 2.
- **New file**: a new file that is missing or does not parse fails with exit code 3. The message names only the new file, for example `episode1_v2.srt: The file does not exist.`

## Translate
`translate` translates the cue text with [`TranslationRunner`](translation.md) and the built-in engine `DeepLEngine`, `GoogleTranslateEngine` or `OpenAiCompatibleEngine`. It needs the PHP extension curl.

```sh
vendor/bin/subtitle-toolbox translate movie.de.srt --engine deepl --source-language de --target-language en-US -o movie.en.srt
DEEPL_API_KEY=... vendor/bin/subtitle-toolbox translate movie.de.srt --engine deepl --target-language en-US
GOOGLE_TRANSLATE_API_KEY=... vendor/bin/subtitle-toolbox translate season1/ --engine google --target-language fr --to vtt --output-dir fr/
OPENAI_BASE_URL=http://localhost:11434/v1 OPENAI_MODEL=llama3 vendor/bin/subtitle-toolbox translate movie.de.srt --engine openai --target-language en
```

| Option | Sets |
|:--- |:--- |
| `--engine deepl\|google\|openai` | the engine, `DeepLEngine`, `GoogleTranslateEngine` or `OpenAiCompatibleEngine`. Required |
| `--api-key KEY` | `apiKey` of the engine options. Default: `DEEPL_API_KEY` for `deepl`, `GOOGLE_TRANSLATE_API_KEY` for `google`, `OPENAI_API_KEY` for `openai` |
| `--source-language CODE` | the language of the input, for example `de`. Default: the engine detects it |
| `--target-language CODE` | the language of the output, for example `en-US` for DeepL or `fr` for Google. Required |
| `--fps`, `--input-fps`, `--output-fps` | `frameRate` of the MicroDVD, CSV and iTT read and write options, see [Frame rates](#frame-rates) |

- **Key**: the tool reads only the variable of the chosen engine. With `--engine deepl`, a set `GOOGLE_TRANSLATE_API_KEY` does not help. The key never appears in the output or in error messages.
- **openai**: the tool reads the model from `OPENAI_MODEL`, which is required. It reads the base URL from `OPENAI_BASE_URL`, default `https://api.openai.com/v1`. The key is optional, because a local service such as Ollama needs none.
- **Shared machine**: other users can read the arguments of a process, for example with `ps`, and the shell history keeps them. On a shared machine, set the variable in place of `--api-key`.
- **Language codes**: the tool passes the codes to the service as they are and does not check them. The service rejects an unknown code, and the file fails.
- **Usage errors**: the tool stops with exit code 2 before it reads a file in these cases:
  - A missing or unknown `--engine`.
  - A missing key for `deepl` or `google`.
  - A missing `OPENAI_MODEL`, or an `OPENAI_BASE_URL` without `http://` or `https://`, for `openai`.
  - A missing `--target-language`.
- **Service errors**: an error of the service fails the file with exit code 3. The message names the cause, for example a wrong key (HTTP 403), too many requests (HTTP 429), a used-up DeepL quota (HTTP 456) or no response. `--keep-going` goes on with the next file.
- **Warnings**: when the service drops a placeholder tag, the tool prints `movie.srt: cue 4: ...` to standard error and writes the cue without tags.
- **No curl**: without `ext-curl`, `translate` stops with exit code 2 and names the extension. The other commands run without it. The container images include it. For the PHAR, install the curl extension of your PHP.

## Dual
`dual` merges a primary and a secondary subtitle with [`DualSubtitle::fromPair()`](editing.md#dual-subtitles). The output has the format of the primary file, unless `--to` sets another one.

```sh
vendor/bin/subtitle-toolbox dual --primary movie.en.srt --secondary movie.de.srt --secondary-style i -o movie.en-de.srt
vendor/bin/subtitle-toolbox dual --primary movie.en.srt --secondary movie.de.srt --mode top-bottom --to ass -o movie.en-de.ass
```

| Option | Sets |
|:--- |:--- |
| `--primary FILE` | the subtitle whose cues set the times, or `-` for standard input. Required |
| `--secondary FILE` | the subtitle in the second language. Required |
| `--mode MODE` | `stack` or `top-bottom` |
| `--secondary-style TAG` | `secondaryStyle`, for example `i` or `'font color="#ffff00"'` |
| `--secondary-alignment 1-9` | `secondaryAlignment` for `top-bottom`, as the value of the `Alignment` case, for example 8 for `Alignment::TopCenter` |
| `--snap-tolerance SECONDS` | `snapTolerance` for `top-bottom`. The value is a [time](#times) |
| `--primary-from FORMAT`, `--primary-track NUMBER` | the format and the MKV or WebM track of the primary file. `dual` has no `--from` and `--track` |
| `--secondary-from FORMAT`, `--secondary-track NUMBER` | the format and the MKV or WebM track of the secondary file. Without `--secondary-from`, the tool detects the format of the secondary file. `--primary-from` does not apply to it |

- **Output**: one result, so it goes to standard output unless `-o` or `--output-dir` sets a file.
- **Secondary file**: a secondary file that is missing or does not parse fails with exit code 3. The message names only the secondary file, for example `movie.de.srt: The file does not exist.`

## HLS
`hls` cuts one subtitle into WebVTT segments with [`HlsWebVttSegmenter`](hls.md) and writes them with the playlist into `--output-dir`.

```sh
vendor/bin/subtitle-toolbox hls movie.srt --output-dir hls/ --segment 6 --media-duration 5400
```

This writes `hls/sub0.vtt` to `hls/sub899.vtt` and `hls/subs.m3u8`.

| Option | Sets |
|:--- |:--- |
| `--output-dir DIR` | the directory of the segments and the playlist. Required |
| `--segment SECONDS` | `segmentDuration`. The value is a [time](#times) |
| `--playlist NAME` | the file name of the playlist |
| `--pattern PATTERN` | `fileNamePattern`. `%d` is the segment number from 0 |
| `--mpegts TICKS` | `mpegts`, the 90 kHz MPEG-2 timestamp at which subtitle time 0 plays. A whole number of 0 or more |
| `--local SECONDS` | `local`, the WebVTT cue time that maps to `--mpegts`. The value is a [time](#times) |
| `--media-duration SECONDS` | `mediaDuration`. Set it to the video duration, so the playlist covers the whole video. Without it, the playlist ends with the last cue. The value is a [time](#times) |

- **Overwrite**: `hls` fails with exit code 2 before it reads the input when the playlist exists. It also fails when a file matches `--pattern`. A pattern with a directory part, such as `../seg%d.vtt`, puts the segments in that directory, and the check looks there. The playlist can be outside `--output-dir`, so `--output-dir new --playlist ../keep.txt` fails when `keep.txt` exists. The segment count is known only after the read, so `hls/sub950.vtt` blocks a run that writes 900 segments.
- **Names**: `hls` fails with exit code 2 before it writes a file when `--playlist` matches `--pattern`, for example `--playlist sub0.vtt`. It also fails when the playlist or a segment would overwrite the input.

## OCR
`convert --ocr` reads the image cues of PGS and VobSub files before it writes the output. It uses [Tesseract](ocr.md#tesseract) when the `tesseract` program is on the `PATH`. Otherwise it uses [php-glyph-ocr](ocr.md#php-glyph-ocr).

```sh
vendor/bin/subtitle-toolbox convert movie.sup --to srt -o movie.srt --ocr
vendor/bin/subtitle-toolbox convert movie.sup --to srt -o movie.srt --ocr --ocr-language deu
vendor/bin/subtitle-toolbox convert movie.sup --to srt -o movie.srt --ocr --ocr-engine glyph
vendor/bin/subtitle-toolbox convert movie.idx --to srt -o movie.srt --ocr --ocr-database my-font.nocr
```

- **Engine**: `--ocr-engine` picks one engine. When that engine is not installed, the tool stops with exit code 2 and an install hint.
- **Language**: `--ocr-language` takes Tesseract language codes. A language without installed data stops the tool before the first file with exit code 2 and lists the installed languages. The glyph engine ignores the option and prints a warning.
- **Database**: `--ocr-database` loads a `.nocr` file in place of the subtitle fonts database. See [Training a database](ocr.md#training-a-database).
- **No engine**: when neither Tesseract nor php-glyph-ocr is installed, `--ocr` stops with exit code 2 and prints the install commands of both.
- **Progress**: the tool prints `movie.sup: OCR 100/1500` to standard error after every 100 image cues and after the last one.
- **Memory**: php-glyph-ocr can need more than the default `memory_limit` of 128 MB, see [ocr.md](ocr.md#php-glyph-ocr). The tool raises 128 MB to 512 MB, see [Install without Composer](#install-without-composer).
- **Info**: `info` prints `Image cues: 12, 0 with text` for a file with image cues. The JSON holds `"imageCues": {"count": 12, "withText": 0}` for every file.
