# Subtitle Toolbox
[![Packagist version](https://img.shields.io/packagist/v/yama6a/subtitle-toolbox-php)](https://packagist.org/packages/yama6a/subtitle-toolbox-php)
[![CI](https://github.com/yama6a/subtitle-toolbox-php/actions/workflows/ci.yaml/badge.svg?branch=master)](https://github.com/yama6a/subtitle-toolbox-php/actions/workflows/ci.yaml)
[![License](https://img.shields.io/packagist/l/yama6a/subtitle-toolbox-php)](LICENSE)

A PHP library and command line tool that reads, edits and writes subtitles, transcripts and chapter lists in more than 30 formats.

Upgrading from 2.x? The package has a new name. See the [upgrade guide](docs/upgrade-3.0.md).

Upgrading from 3.x? See the [4.0 upgrade guide](docs/upgrade-4.0.md).

## Install
```sh
composer require yama6a/subtitle-toolbox-php
# Optional, for OCR of PGS and VobSub image subtitles:
composer require yama6a/php-glyph-ocr:^0.3   # php-glyph-ocr, the pure PHP OCR engine
apt install tesseract-ocr                    # or Tesseract on Debian and Ubuntu, for more than 100 languages
```

The core package needs neither OCR engine. See [ocr.md](docs/ocr.md) for the install commands of other systems and languages.

The library needs PHP 8.2 or later with `ext-dom`, `ext-iconv` and `ext-mbstring`. These extensions are optional:

- `ext-zlib` for PGS output and compressed MKV tracks
- `ext-curl` for the translation engines

The command line tool also comes as a PHAR file and as two container images. The `-tesseract` image includes Tesseract for OCR. See [Install without Composer](docs/cli.md#install-without-composer) for the commands.

## Supported formats

**Case** is the case of the enum `Format`, for example `Format::SubRip`. **Name** is the format name for `--from` and `--to`. An **image cue** holds a bitmap in place of text.

### Text subtitles

| Format | Case | Name | Extensions | Read | Write | Notes |
|:--- |:--- |:--- |:--- |:---:|:---:|:--- |
| [ASS](https://aegisub.org/docs/latest/ass_tags/), [SSA](https://en.wikipedia.org/wiki/SubStation_Alpha) | `Ass` | `ass` | `.ass`, `.ssa` | ✅ | ✅ | |
| [CSV](https://www.rfc-editor.org/rfc/rfc4180), [TSV](https://www.iana.org/assignments/media-types/text/tab-separated-values) | `Csv`, `Tsv` | `csv`, `tsv` | `.csv`, `.tsv` | ✅ | ✅ | not detected from the content |
| [EBU STL](https://tech.ebu.ch/docs/tech/tech3264.pdf) | `EbuStl` | `stl` | `.stl` | ✅ | ✅ | binary, 25 or 30 fps |
| [iTunes Timed Text](https://help.apple.com/itc/videoaudioassetguide/#/itc0f1b0a2e0) | `Itt` | `itt` | `.itt` | ✅ | ✅ | needs a frame rate to write |
| [JSON of this library](docs/json.md) | `Json` | `json` | `.json` | ✅ | ✅ | |
| [LRC](https://en.wikipedia.org/wiki/LRC_(file_format)) | `Lyrics` | `lrc` | `.lrc` | ✅ | ✅ | with enhanced LRC word times |
| [MicroDVD](https://en.wikipedia.org/wiki/MicroDVD) | `MicroDvd` | `microdvd` | `.sub` | ✅ | ✅ | needs the frame rate of the video |
| [MPL2](https://pysubs2.readthedocs.io/en/latest/supported-formats.html) | `Mpl2` | `mpl2` | `.txt` | ✅ | ✅ | |
| [MPSub](https://man.archlinux.org/man/mplayer.1.en) | `MpSub` | `mpsub` | `.mpsub` | ✅ | ✅ | |
| [SAMI](https://learn.microsoft.com/en-us/previous-versions/windows/desktop/dnacc/understanding-sami-1.0) | `Sami` | `sami` | `.smi`, `.sami` | ✅ | ✅ | one language class per parse |
| [SBV](https://support.google.com/youtube/answer/2734698) | `Sbv` | `sbv` | `.sbv` | ✅ | ✅ | |
| [SCC](https://www.nationalarchives.gov.uk/PRONOM/fmt/1274) | `Scc` | `scc` | `.scc` | ✅ | ✅ | CEA-608 closed captions |
| [SubRip](https://en.wikipedia.org/wiki/SubRip) | `SubRip` | `srt` | `.srt` | ✅ | ✅ | |
| [SubViewer](https://man.archlinux.org/man/mplayer.1.en) 1 and 2 | `SubViewer` | `subviewer` | `.sub` | ✅ | ✅ | |
| [TMPlayer](https://pysubs2.readthedocs.io/en/latest/supported-formats.html) | `TmPlayer` | `tmplayer` | `.txt` | ✅ | ✅ | |
| [TTML](https://www.w3.org/TR/ttml2/), [IMSC](https://www.w3.org/TR/ttml-imsc1.2/), [DFXP](https://www.w3.org/TR/ttaf1-dfxp/) | `Ttml` | `ttml` | `.ttml`, `.dfxp`, `.xml` | ✅ | ✅ | |
| [WebVTT](https://www.w3.org/TR/webvtt1/) | `WebVtt` | `vtt` | `.vtt` | ✅ | ✅ | also chapters |
| [YouTube timed text](https://support.google.com/youtube/answer/2734698) | `YouTubeTimedText` | `youtube` | `.srv3`, `.json3`, `.srv1` | ✅ | ✅ | json3, srv1, srv2, srv3 and transcript XML |

### Image subtitles

| Format | Case | Name | Extensions | Read | Write | Notes |
|:--- |:--- |:--- |:--- |:---:|:---:|:--- |
| [PGS](https://en.wikipedia.org/wiki/Presentation_Graphic_Stream) | `Pgs` | `pgs` | `.sup` | ✅ | ✅ | Blu-ray bitmaps as image cues |
| [VobSub](https://www.loc.gov/preservation/digital/formats/fdd/fdd000570.shtml) | `VobSub` | `vobsub` | `.idx` with `.sub` | ✅ | ❌ | DVD bitmaps as image cues |

### Transcripts

| Format | Case | Name | Extensions | Read | Write | Notes |
|:--- |:--- |:--- |:--- |:---:|:---:|:--- |
| Cloud speech-to-text JSON: [AWS Transcribe](https://docs.aws.amazon.com/transcribe/latest/dg/how-it-works.html), [Deepgram](https://developers.deepgram.com/reference/speech-to-text/listen-pre-recorded), [AssemblyAI](https://www.assemblyai.com/docs/api-reference/transcripts/get), [Google Speech-to-Text](https://cloud.google.com/speech-to-text/docs/reference/rest/v1/speech/recognize) | `AwsTranscribe`, `Deepgram`, `AssemblyAi`, `GoogleSpeech` | `aws-transcribe`, `deepgram`, `assemblyai`, `google-speech` | `.json` | ✅ | ✅ | not detected from the content |
| [HTML transcript](https://podcasting2.org/docs/podcast-namespace/tags/transcript) | `HtmlTranscript` | `html` | `.html`, `.htm` | ✅ | ✅ | the Podcasting 2.0 HTML format |
| [Plain text](docs/transcripts.md#plain-text) | `PlainText` | `txt` | `.txt` | ❌ | ✅ | transcript |
| [Podcasting 2.0 transcript JSON](https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/examples/transcripts/transcripts.md) | `PodcastTranscript` | `podcast-transcript` | `.json` | ✅ | ✅ | |
| [Whisper JSON](https://platform.openai.com/docs/api-reference/audio/verbose_json_object) | `Whisper` | `whisper` | `.json` | ✅ | ✅ | OpenAI API, openai-whisper, faster-whisper, WhisperX, whisper.cpp |

### Chapters

| Format | Case | Name | Extensions | Read | Write | Notes |
|:--- |:--- |:--- |:--- |:---:|:---:|:--- |
| [FFmpeg metadata chapters](https://ffmpeg.org/ffmpeg-formats.html#Metadata-1) | `FfMetadataChapters` | `ffmeta-chapters` | `.ffmeta` | ✅ | ✅ | not detected from the content |
| [OGM chapters](https://mkvtoolnix.download/doc/mkvmerge.html) | `OgmChapters` | `ogm-chapters` | `.txt` | ✅ | ✅ | not detected from the content |
| [Podcasting 2.0 chapters](https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/examples/chapters/jsonChapters.md) | `PodcastChapters` | `podcast-chapters` | `.json` | ✅ | ✅ | not detected from the content |
| [YouTube chapters](https://support.google.com/youtube/answer/9884579) | `YouTubeChapters` | `youtube-chapters` | `.txt` | ✅ | ✅ | not detected from the content |

### Containers

| Format | Case | Name | Extensions | Read | Write | Notes |
|:--- |:--- |:--- |:--- |:---:|:---:|:--- |
| [MKV](https://www.rfc-editor.org/rfc/rfc9559) and [WebM](https://www.webmproject.org/docs/container/) tracks | | | `.mkv`, `.webm` | ✅ | ❌ | text, ASS, SSA, WebVTT, PGS and VobSub tracks |
| [MP4 tracks](https://www.3gpp.org/dynareport/26245.htm) | | | `.mp4`, `.m4v`, `.mov` | ✅ | ❌ | `tx3g` (`mov_text`) tracks |

## Command line tool
Composer installs `vendor/bin/stbox`. Optional parts are in brackets.

```sh
stbox convert movie.srt --to vtt [--timing-fix-overlaps] [-o movie.vtt]
stbox convert movie.mkv --to srt --track 3 [-o movie.srt]
stbox convert movie.sup --to srt --ocr [--ocr-language deu] [-o movie.srt]
stbox convert movie.sub --input-fps 25 --from-fps 25 --to-fps 23.976 [-o movie.fixed.sub]
stbox info movie.srt [--json]
stbox validate movie.srt --preset netflix-en [--video-fps 23.976]
stbox sync movie.de.srt --reference movie.en.srt [-o movie.de.synced.srt]
stbox diff movie.v1.srt movie.v2.srt [--text-only]
stbox translate movie.de.srt --engine deepl --target-language en-US [-o movie.en.srt]
stbox dual --primary movie.en.srt --secondary movie.de.srt --to ass [--mode stack] [-o movie.en-de.ass]
stbox hls movie.vtt --output-dir hls/ [--segment 6]
stbox formats
```

- Without `-o`, the output goes to standard output.
- Several inputs need `--output-dir`, for example `convert season1/ --shift 2 --output-dir fixed/`.
- No command overwrites a file.
- When detection fails, pass `--from`.

`stbox convert --help` lists the option groups of `convert`. See [cli.md](docs/cli.md) for all commands and options.

## Library
### Load and write
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::load('movie.srt', Format::SubRip);
$subtitle = Subtitle::load('movie.srt');
$subtitle->findFormat();                           // Format::SubRip
$subtitle->save('movie.vtt');                     // the format comes from the extension
$vtt = $subtitle->toString(Format::WebVtt, new WriteOptions(lineEnding: LineEnding::Crlf, stripTags: true));
```

### Read options
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$latin1   = Subtitle::load('latin1.srt', Format::SubRip, new ReadOptions(encoding: 'Windows-1252'));
$microDvd = Subtitle::load('movie.sub', Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(frameRate: 23.976)]));
```

See [read-options.md](docs/read-options.md) for the options of each format.

### Edit
```php
use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Format;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::load('movie.srt', Format::SubRip);
$subtitle->shift(-2.5)                            // all cues 2.5 s earlier
         ->convertFrameRate(25, 23.976)
         ->fixOverlaps(0.083)                     // a gap of at least 0.083 s between cues
         ->wrapLines(42)                          // at most 42 characters per line, 2 lines
         ->changeCase(CaseMode::Sentence);
$firstMinute = $subtitle->withSlice(0, 60);       // a new Subtitle, $subtitle stays as it is

$report = HearingImpairedRemover::apply($subtitle, new HearingImpairedOptions(parentheses: false));
echo "$report->removedLines lines removed\n";
```

A service such as `HearingImpairedRemover` changes the subtitle in place and returns a report.

### Validate and count
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleStatistics;
use SubtitleToolbox\Validation\ValidationRules;

$subtitle = Subtitle::load('movie.srt', Format::SubRip);
foreach ($subtitle->validate(ValidationRules::netflixEnglish(23.976)) as $violation) {
    echo "cue index $violation->cueIndex: {$violation->rule->value} is $violation->value\n";
}
$stats = SubtitleStatistics::of($subtitle);
echo "$stats->cueCount cues, $stats->wordCount words\n";
```

### MKV and WebM tracks
```php
use SubtitleToolbox\Subtitle;

foreach (Subtitle::tracks('movie.mkv') as $track) {
    echo "$track->number: $track->codecId, $track->language, $track->name\n";   // 3: S_TEXT/UTF8, de, Deutsch (Forced)
}
$subtitle = Subtitle::loadTrack('movie.mkv', 3);
```

### OCR
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\OcrEngineName;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::load('movie.sup', Format::Pgs);
$subtitle->recognizeText(OcrEngineChooser::create());                       // Tesseract if installed, otherwise php-glyph-ocr
$subtitle->recognizeText(OcrEngineChooser::create(OcrEngineName::Glyph));   // always php-glyph-ocr
$subtitle->save('movie.srt');
```

`create()` takes the Tesseract language as its second argument, for example `'deu+eng'`. For engine settings, pass `TesseractOcrOptions` to `new TesseractOcrEngine()` or `GlyphOcrOptions` to `new GlyphOcrEngine()`.

### Translate
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\TranslationRunner;

$subtitle = Subtitle::load('movie.de.srt', Format::SubRip);
(new TranslationRunner(new DeepLEngine(new DeepLOptions(apiKey: $apiKey))))->translate($subtitle, 'de', 'en-US');
$subtitle->save('movie.en.srt');
```

`GoogleTranslateEngine` works the same way. See [translation.md](docs/translation.md).

### Sync to a reference
```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;

$german  = Subtitle::load('movie.de.srt', Format::SubRip);
$english = Subtitle::load('movie.en.srt', Format::SubRip);
$report  = ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $english));
echo "offset $report->offset s, scale $report->scale, score $report->score\n";
```

Every exception implements `SubtitleToolboxException`. See [errors.md](docs/errors.md).

## OCR
OCR turns the bitmaps of PGS and VobSub subtitles into text. The library uses Tesseract when it is installed. Otherwise it uses php-glyph-ocr. When neither engine is installed, the call throws `InvalidArgumentException` that names both engines. Tesseract reads more than 100 languages. php-glyph-ocr reads only Latin-script fonts. See [ocr.md](docs/ocr.md) for the install commands and a comparison of the engines.

## Compatibility
See [compatibility.md](docs/compatibility.md) for the parts that semantic versioning covers and for what a minor or patch release can change.

## Documentation
[docs/README.md](docs/README.md) lists every page. The most used pages:

- [cli.md](docs/cli.md): all commands and options
- [formats.md](docs/formats.md): what each parser reads and each formatter writes
- [editing.md](docs/editing.md): retiming, cutting, joining and splitting cues
- [text.md](docs/text.md): text changes, hearing-impaired removal, common error fixes
- [validation.md](docs/validation.md): rules and presets
- [sync.md](docs/sync.md): sync to a reference or to the speech
- [subtitle.md](docs/subtitle.md): metadata, comments, cue lookup, statistics

## Contributing
Pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the licence rules and the credit rule. Run the tests with `composer test`. Each pull request carries one label that sets the version bump: `major`, `minor`, `patch` or `skip-release`. Every merge to `master` publishes a release.

## License
MIT, see [LICENSE](LICENSE). Material from third parties keeps its own licence, see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

## Credits
The PHP libraries [mantas-done/subtitles](https://github.com/mantas-done/subtitles) and [captioning/captioning](https://github.com/captioning/captioning) shaped this library. Their formats, test files and user reports guided many decisions here. [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) lists every file copied from other projects.
