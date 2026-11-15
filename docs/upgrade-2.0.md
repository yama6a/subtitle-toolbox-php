# Upgrade from 1.x to 2.0

7.0 removed the command line tool from this package. The CLI parts below describe 6.x and older. The tool lives on in [subtitle-toolbox-go](https://github.com/yama6a/subtitle-toolbox-go).

```sh
composer require ymakhloufi/subtitle-toolbox:^2.0
```

2.0 needs the same PHP version and extensions as 1.x. OCR without Tesseract needs version 0.3 of `yama6a/php-glyph-ocr`. [compatibility.md](compatibility.md) says what semantic versioning covers in 2.x.

Each table puts a 1.x call next to the 2.0 call that does the same. In the examples, `$subtitle`, `$german` and `$english` are `Subtitle` objects. `$content` is the text of an SRT file. Each other variable holds the object that its name says, such as `$runner` for a `TranslationRunner`. [Behavior changes](#behavior-changes) lists the code that runs in both versions but gives another result.

## General rules
PHP errors point out each of these changes. The tables show the main cases.

- **Formats**: the `Format` enum names a format, for example `Format::SubRip`. Its value is the CLI format name, such as `'srt'`. No method takes a parser class, a formatter class or a format name string.
- **Options**: a setting goes in `ReadOptions` or `WriteOptions`. A setting of one format goes in a class of that format, such as `MicroDvdReadOptions` or `CsvWriteOptions`. Parser constructors take no arguments.
- **Wrong options**: a misspelled named argument is a PHP `Error`. An options class of another format throws `InvalidArgumentException`.
- **Final classes**: every concrete class is `final`, except `InvalidArgumentException` and `InvalidParserException`. Wrap a class in your own class instead of extending it.
- **Internal members**: the methods and constants of parsers and formatters are `@internal`, private or removed. `FormatDetector`, `FormatRegistry`, `Cea608`, `CodePage`, `Iso6937`, `PaletteReducer`, `EbmlReader` and the `StringHelpers` methods other than `isValidUtf8()` and `convertToUtf8()` are `@internal`. Use the `Format` enum in place of `FormatDetector` and `FormatRegistry`.
- **Copy or change**: a `Subtitle` method that returns a new subtitle starts with `with`. A method that converts the subtitle to another type starts with `to`. A method that changes the subtitle is a verb.
- **get and find**: a lookup that starts with `find` returns null or an empty array when nothing matches. A lookup that starts with `get` throws.
- **Services**: a service is an edit class with a static `apply($subtitle, $options)`, for example `HearingImpairedRemover`. It changes the subtitle that you pass and returns a report.
- **Results**: reports and other results have `public readonly` fields instead of getters. Only the library creates them.
- **Enums**: each set of string constants, such as `ProfanityOptions::MASK_STARS`, is a backed enum. The value of each case is the 1.x string.
- **Names**: the library writes names in full and in US spelling. For example, `fps` becomes `frameRate`, `maxLines` becomes `maxLinesPerCue` and `colour` becomes `color`.
- **Namespaces**: the classes of hearing-impaired removal moved to `SubtitleToolbox\HearingImpaired`, of resegmenting to `SubtitleToolbox\Resegmenting`, and of dual subtitles to `SubtitleToolbox\Dual`.
- **Strict types**: every library file declares `strict_types`. A callback that you pass, for example to `mapText()`, must return a string. Your own files without `strict_types` call the library as before.
- **CLI classes**: the classes in `SubtitleToolbox\Cli` are `@internal`. See [compatibility.md](https://github.com/yama6a/subtitle-toolbox-php/blob/6.11.5/docs/compatibility.md) for the parts of the CLI that stay stable.

## Load and save
| 1.x | 2.0 |
|:--- |:--- |
| `Subtitle::parse($content, SubRipParser::class)` | `Subtitle::fromString($content, Format::SubRip)` |
| `Subtitle::parse($content)` | `Subtitle::fromStringAutoDetectFormat($content)` |
| `Subtitle::parse(file_get_contents('movie.srt'))` | `Subtitle::loadAutoDetectFormat('movie.srt')` |
| `Subtitle::parse($content, SubRipParser::class, 'Windows-1252')` | `Subtitle::fromString($content, Format::SubRip, new ReadOptions(encoding: 'Windows-1252'))` |
| `$subtitle->format(WebVttFormatter::class)` | `$subtitle->toString(Format::WebVtt)` |
| `file_put_contents('out.vtt', $subtitle->format(WebVttFormatter::class))` | `$subtitle->save('out.vtt')` |
| `file_put_contents('out.txt', $subtitle->format(WebVttFormatter::class))` | `$subtitle->save('out.txt', Format::WebVtt)` |
| `(new SubRipParser())->parse($content)` | `(new SubRipParser())->parse($content, new ReadOptions())` |
| `MatroskaReader::open('movie.mkv')->getSubtitleTracks()` | `Subtitle::tracks('movie.mkv')` |
| `MatroskaReader::open('movie.mkv')->extract(3)` | `Subtitle::loadTrack('movie.mkv', 3)` |
| `(new VobSubParser(file_get_contents('dvd.idx'), 'de'))->parse(file_get_contents('dvd.sub'))` | `Subtitle::load('dvd.idx', Format::VobSub, new ReadOptions(format: new VobSubReadOptions(language: 'de')))` |

## Formats
| 1.x | 2.0 |
|:--- |:--- |
| `Subtitle::detectParser($content)` | `Format::detect($content)` |
| `FormatRegistry::find('srt')` | `Format::tryFrom('srt')` |
| `FormatRegistry::forPath('movie.sub')` | `Format::fromPath('movie.sub')` |
| `FormatRegistry::names()` | `array_column(Format::cases(), 'value')` |
| `FormatRegistry::extensions('ass')` | `Format::Ass->extensions()` |
| `FormatRegistry::parserClass('vobsub')` | `Format::VobSub->canRead()` |
| `FormatRegistry::find('ytchapter')`, `FormatRegistry::find('podcast')`, `FormatRegistry::find('ogm')`, `FormatRegistry::find('ffmeta')` | `Format::YouTubeChapters`, `Format::PodcastChapters`, `Format::OgmChapters`, `Format::FfMetadataChapters` |
| `$subtitle->getFormatData('sub')`, `$subtitle->getFormatData('smi')` | `$subtitle->findFormatData('microdvd')`, `$subtitle->findFormatData('sami')` |
| `$subtitle->getFormatData('ffmetadata')`, `$subtitle->getFormatData('chapters')`, `$subtitle->getFormatData('podcast')` | `$subtitle->findFormatData('ffmeta-chapters')`, `$subtitle->findFormatData('podcast-chapters')`, `$subtitle->findFormatData('podcast-transcript')` |

The key of the format data is the value of the `Format` case. The values of the 4 chapter cases are `youtube-chapters`, `podcast-chapters`, `ogm-chapters` and `ffmeta-chapters`.

## Read options
| 1.x | 2.0 |
|:--- |:--- |
| `(new SubRipParser())->setLenient()` | `new ReadOptions(lenient: true)` |
| `$parser->getWarnings()` | `$subtitle->getParseWarnings()` |
| `new MicroDvdParser(23.976)` | `new ReadOptions(format: new MicroDvdReadOptions(frameRate: 23.976))` |
| `new TmPlayerParser(4)` | `new ReadOptions(lastCueDuration: 4)` |
| `new SamiParser('ENUSCC', 10)` | `new ReadOptions(lastCueDuration: 10, format: new SamiReadOptions(languageClass: 'ENUSCC'))` |
| `new VobSubParser(file_get_contents('dvd.idx'), 1)` | `new ReadOptions(format: new VobSubReadOptions(idx: file_get_contents('dvd.idx'), track: 1))` |
| `new CsvParser(new CsvColumns(start: 'TC', frameRate: 25), ';')` | `new ReadOptions(format: new CsvReadOptions(columns: new CsvColumns(start: 'TC'), delimiter: ';', frameRate: 25))` |
| `new SccParser(2)` | `new ReadOptions(format: new SccReadOptions(channel: 2))` |
| `new EbuStlParser(true)` | `new ReadOptions(format: new EbuStlReadOptions(subtractStartOfProgramme: true))` |
| `new FfMetadataChaptersParser(3600)` | `new ReadOptions(format: new ChapterReadOptions(mediaDuration: 3600))` |
| `new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true])` | `new ReadOptions(format: new TranscriptReadOptions(wordTimestamps: true))` |
| `new DeepgramParser([DeepgramParser::OPTION_SPEAKER_VOICES => true])` | `new ReadOptions(format: new TranscriptReadOptions(speakerVoices: true))` |
| `new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_KEEP_SEGMENTS => true])` | `new ReadOptions(format: new TranscriptReadOptions(keepSegments: true))` |

`ChapterReadOptions` serves every chapter format. `TranscriptReadOptions` serves the speech-to-text, YouTube timed text and Podcasting 2.0 formats.

## Write options
`FooFormatter::OPTION_BAR_BAZ` becomes the field `barBaz` of `FooWriteOptions`. The options of `SubtitleFormatter` go in `WriteOptions`. The table shows the example and each option that changed more.

| 1.x | 2.0 |
|:--- |:--- |
| `$subtitle->format(MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 23.976])` | `$subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdWriteOptions(frameRate: 23.976)))` |
| `$subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"])` | `$subtitle->toString(Format::SubRip, new WriteOptions(lineEnding: LineEnding::Crlf))` |
| `$subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS])` | `$subtitle->toString(Format::SubRip, new WriteOptions(stripTags: true))` |
| `[AssFormatter::OPTION_KARAOKE_TAG => 'kf']` | `new AssWriteOptions(karaokeTag: AssKaraokeTag::Fill)` |
| `[CsvFormatter::OPTION_TIME_FORMAT => CsvParser::TIME_COMMA]` | `new CsvWriteOptions(timeFormat: CsvTimeFormat::Comma)` |
| `[SubViewerFormatter::OPTION_VERSION => 1]` | `new SubViewerWriteOptions(version: SubViewerVersion::V1)` |
| `[CsvFormatter::OPTION_SECOND_TEXT => $german]` | `new CsvWriteOptions(secondText: $german)` |

## Streaming
| 1.x | 2.0 |
|:--- |:--- |
| `(new SubRipStreamReader())->setLenient()` | `new SubRipStreamReader(new ReadOptions(lenient: true))` |
| `new SubRipStreamWriter($stream, [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"])` | `new SubRipStreamWriter($stream, new WriteOptions(lineEnding: LineEnding::Crlf))` |
| `new WebVttStreamWriter($stream, $reader->getHeader())` | `new WebVttStreamWriter($stream, header: $reader->getHeader())` |

## Edit a subtitle
| 1.x | 2.0 |
|:--- |:--- |
| `$subtitle->slice(10, 20, true)` | `$subtitle->withSlice(10, 20, true)` |
| `$subtitle->forcedOnly()` | `$subtitle->withForcedCuesOnly()` |
| `$subtitle->filterCues(fn (SubtitleCue $cue) => $cue->isForced())` | `$subtitle->removeCuesWhere(fn (SubtitleCue $cue) => !$cue->isForced())` |
| `$subtitle->addCue(new SubtitleCue(1, 2, 'Hi.'), false)` | `$subtitle->addCues([new SubtitleCue(1, 2, 'Hi.')])` |
| `$subtitle->removeCue(0, false)` | `$subtitle->removeCue(0)` |
| `$subtitle->changeCase('upper', 'tr')` | `$subtitle->changeCase(CaseMode::Upper, 'tr')` |
| `$subtitle->replaceText('/x+/', 'y', true, false)` | `$subtitle->replaceText('/x+/', 'y', new ReplaceTextOptions(regex: true, caseSensitive: false))` |
| `$subtitle->getCues()[0]->setLinesByArray(['Hi.', 'Bye.'])`, `$subtitle->getCues()[0]->setLinesByString("Hi.\nBye.")` | `$subtitle->getCues()[0]->setLines(['Hi.', 'Bye.'])`, `$subtitle->getCues()[0]->setLines("Hi.\nBye.")` |
| `$subtitle->getCuesAt(83.2)`, `$subtitle->getCuesBetween(600, 660)`, `$subtitle->getMetadata('title')` | `$subtitle->findCuesAt(83.2)`, `$subtitle->findCuesBetween(600, 660)`, `$subtitle->findMetadata('title')` |
| `$subtitle->convertFrameRate(fromFps: 25, toFps: 23.976)` | `$subtitle->convertFrameRate(from: 25, to: 23.976)` |
| `$subtitle->wrapLines(maxCharsPerLine: 32, maxLines: 3)` | `$subtitle->wrapLines(maxCharactersPerLine: 32, maxLinesPerCue: 3)` |
| `$subtitle->mergeShortCues(new MergeShortCuesOptions(maxCharactersPerLine: 37, maxLines: 1))` | `$subtitle->mergeShortCues(new MergeShortCuesOptions(limits: new CueLimits(maxCharactersPerLine: 37, maxLinesPerCue: 1)))` |
| `$subtitle->mergeShortCues(new MergeShortCuesOptions(sameSpeakerOnly: true))` | `$subtitle->mergeShortCues(new MergeShortCuesOptions(mergeSameSpeakerAnyDuration: true))` |

- `addCue()` always sorts the cues. `addCues()` adds many cues and sorts once.
- `removeCue()` always numbers the cues from 0 again. `removeCuesWhere()` removes the cues for which the callback returns true.
- `getComments()` returns readonly `Comment` objects with the fields `text` and `beforeCueIndex`, not arrays.
- `CueLimits` holds `maxCharactersPerLine`, `maxLinesPerCue`, `minDuration`, `maxDuration` and `maxCharactersPerSecond`. `MergeShortCuesOptions` and `ResegmentOptions` take it as `limits`.

## Services
| 1.x | 2.0 |
|:--- |:--- |
| `$subtitle->removeHearingImpaired(new HearingImpairedOptions())` | `HearingImpairedRemover::apply($subtitle, new HearingImpairedOptions())` |
| `(new HearingImpairedOptions())->isHearingImpaired('[DOOR]')` | `HearingImpairedRemover::isAnnotation('[DOOR]', new HearingImpairedOptions())` |
| `$subtitle->splitLongCues(new ResegmentOptions(maxCharactersPerLine: 42))` | `Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::SplitLong, limits: new CueLimits(maxCharactersPerLine: 42)))` |
| `$subtitle->resegmentByWords(new ResegmentOptions())` | `Resegmenter::apply($subtitle, new ResegmentOptions(mode: ResegmentMode::ByWords))` |
| `CommonErrorFixer::fix($subtitle, new CommonErrorOptions())` | `CommonErrorFixer::apply($subtitle, new CommonErrorOptions())->fixes` |
| `CommonErrorFixer::fix($subtitle, new CommonErrorOptions(dryRun: true))` | `CommonErrorFixer::preview($subtitle, new CommonErrorOptions())->fixes` |
| `new CommonErrorOptions(dialogueDash: '-')` | `new CommonErrorOptions(dialogueDashStyle: DialogueDashStyle::Hyphen)` |
| `ProfanityFilter::apply($subtitle, new ProfanityOptions(wordFile: 'words.txt'))` | `ProfanityFilter::apply($subtitle, new ProfanityOptions(words: ['damn*', 'hell']))->muteRanges` |
| `WordHighlight::expand($subtitle, new WordHighlightOptions())` | `WordHighlight::apply(clone $subtitle, new WordHighlightOptions())` |
| `SpeakerLabels::fromPrefix($subtitle, false)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(readPrefixes: true, readUpperCaseOnly: false))` |
| `SpeakerLabels::toPrefix($subtitle, false, ' - ')` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Prefix, writeUpperCase: false, separator: ' - '))` |
| `SpeakerLabels::toDialogueDashes($subtitle, '- ')` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes, dialogueDashStyle: DialogueDashStyle::HyphenSpace))` |
| `SpeakerLabels::toColours($subtitle, SpeakerLabels::BBC_COLOURS)` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Colors, colors: SpeakerLabels::BBC_COLORS))` |
| `SpeakerLabels::rename($subtitle, ['MAN' => 'TOM'])` | `SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(rename: ['MAN' => 'TOM']))` |
| `DualSubtitle::merge($english, $german, new DualSubtitleOptions())` | `DualSubtitle::fromPair($english, $german, new DualSubtitleOptions())` |

## Sync and timing
| 1.x | 2.0 |
|:--- |:--- |
| `ReferenceSync::sync($german, $english)->apply($german)` | `ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $english))` |
| `ReferenceSync::sync($german, $english)->getOffset()` | `ReferenceSync::apply($german, new ReferenceSyncOptions(reference: $english))->offset` |
| `ShotChangeTiming::apply($subtitle, [10.0, 20.0], new ShotChangeOptions(24, snapWindow: 12, minDuration: 20))` | `ShotChangeTiming::apply($subtitle, new ShotChangeOptions(frameRate: 24, shotChanges: [10.0, 20.0], snapWindowFrames: 12, minDurationFrames: 20))` |
| `ShotChangeTiming::chainGaps($subtitle, new ShotChangeOptions(24))` | `ShotChangeTiming::apply($subtitle, new ShotChangeOptions(frameRate: 24))` |

## Validation and statistics
| 1.x | 2.0 |
|:--- |:--- |
| `$subtitle->getErrors()` | `$subtitle->validate(ValidationRules::structure())` |
| `$subtitle->validate(ValidationRules::netflixEnglish(fps: 24))[0]->getRule()` | `$subtitle->validate(ValidationRules::netflixEnglish(frameRate: 24))[0]->rule` |
| `ValidationResult::RULE_OVERLAP` | `ValidationRule::NoOverlap` |
| `new ValidationRules(dialogueDashStyle: "\u{2013} ")` | `new ValidationRules(dialogueDashStyle: DialogueDashStyle::EnDashSpace)` |
| `SubtitleStatistics::of($subtitle)->getGap()` | `SubtitleStatistics::of($subtitle)->gaps` |
| `SubtitleStatistics::of($subtitle)->getMostUsedWords(10)` | `array_slice(SubtitleStatistics::of($subtitle)->mostUsedWords, 0, 10)` |

- `validate()` returns `ValidationViolation` objects. Each `ValidationRule` case has the name of the `ValidationRules` field, so `RULE_EMPTY_CUE` becomes `NoEmptyCues`.
- `YouTubeChapters::check()` returns `ValidationViolation` objects too. `chapterIndex` becomes `cueIndex`.
- `mostUsedWords` holds every word, the most used first, as `[['word' => 'you', 'count' => 211]]`.

## OCR and HLS
| 1.x | 2.0 |
|:--- |:--- |
| `new TesseractOcrEngine('deu+eng', 6)` | `new TesseractOcrEngine(new TesseractOcrOptions(language: 'deu+eng', pageSegmentationMode: 6))` |
| `new GlyphOcrEngine(null, ['italicSlant' => 0.2])` | `new GlyphOcrEngine(new GlyphOcrOptions(italicSlant: 0.2))` |
| `OcrEngineChooser::choose(OcrEngineChooser::ENGINE_GLYPH)` | `OcrEngineChooser::choose(OcrEngineName::Glyph)` |
| `(new OcrRunner($engine))->run($subtitle)` | `(new OcrRunner($engine))->run($subtitle)->texts` |
| `HlsWebVttSegmenter::segment($subtitle)->getSegments()` | `iterator_to_array(HlsWebVttSegmenter::segment($subtitle)->getSegments())` |

- `OcrResult` becomes `RecognizedText`. `HlsWebVttResult` becomes `HlsWebVttRendition`.
- `getSegments()` and `getDurations()` return generators that write each segment when you read it. `getSegmentCount()` returns the number.

## Exceptions
| 1.x | 2.0 |
|:--- |:--- |
| `(new ParsingException('x'))->getErrorCode()` | `(new ParsingException('x'))->getCode()` |
| `GenericException::class` | `SubtitleToolboxException::class` |

Some failures throw another class or code. Check each `catch` of a 1.x class in this table.

| Failure | 1.x | 2.0 |
|:--- |:--- |:--- |
| auto-detection finds no format | `InvalidParserException`, code 102 | `UnknownFormatException`, code 106. It extends `InvalidParserException` |
| the output format cannot hold the content, such as 5 lines in SCC | `InvalidArgumentException`, code 104 | `UnwritableContentException`, code 108. It extends `InvalidArgumentException` |
| JSON output of text that is not UTF-8 | `JsonException` | `UnwritableContentException`, code 108 |
| a stored TTML head that is not valid XML | `InvalidFormatterException`, code 101 | `UnwritableContentException`, code 108 |
| Tesseract or php-glyph-ocr fails on an image | `InvalidArgumentException`, code 104 | `OcrException`, code 107. A missing engine or language still throws `InvalidArgumentException` |

## Command line tool
See [cli.md](https://github.com/yama6a/subtitle-toolbox-php/blob/6.11.5/docs/cli.md) for every command and option.

### Output and input
| 1.x | 2.0 |
|:--- |:--- |
| `convert movie.srt --to vtt` | `convert movie.srt --to vtt -o movie.vtt` |
| `convert movie.srt movie.vtt` | `convert movie.srt --to vtt -o movie.vtt` |
| `convert movie.srt -o out.srt` | `convert movie.srt --to srt -o out.srt` |
| `shift movie.srt --by 1 -o movie.vtt` | `retime movie.srt --shift 1 --to vtt -o movie.vtt` |
| `convert a.srt b.srt --to vtt` | `convert a.srt b.srt --to vtt --output-dir out` |
| `shift a.srt b.srt --by 1 --in-place` | `retime a.srt b.srt --shift 1 --output-dir out` |
| `convert movie.srt --to vtt -o movie.vtt --force` | `convert movie.srt --to vtt -o new.vtt` |
| `convert call.json --to srt` | `convert call.json --from deepgram --to srt` |
| `convert chapters.txt --from ytchapter --to ffmeta` | `convert chapters.txt --from youtube-chapters --to ffmeta-chapters` |
| `dual movie.en.srt movie.de.srt` | `dual --primary movie.en.srt --secondary movie.de.srt` |
| `dual movie.mkv movie.de.srt --track 3` | `dual --primary movie.mkv --secondary movie.de.srt --primary-track 3` |
| `dual movie.en.srt movie.de.srt --from srt` | `dual --primary movie.en.srt --secondary movie.de.srt --primary-from srt` |

- **Output**: an output is never positional. One input goes to standard output or to `-o FILE`. Several inputs need `--output-dir DIR`. `convert` always needs `--to`.
- **Extension**: the extension of `-o` never picks the format. An extension of another format than `--to` fails with exit code 2.
- **Never overwrite**: no command overwrites a file. `--force` and `--in-place` are gone. The tool fails with exit code 2 before it reads a file in these cases:
  - An output exists.
  - 2 inputs write the same output.
  - An output is an input.
- **Format names**: `--from` and `--to` still take the 1.x names `ytchapter`, `podcast`, `ogm` and `ffmeta`. The output prints the new names.
- **Second file**: `diff` reads the new file with `--from2` and `--track2`. `dual` reads its files with `--primary-from`, `--primary-track`, `--secondary-from` and `--secondary-track`. `diff` takes one old file. A directory or a glob that matches more than one file fails with exit code 2.
- **Frame rate**: `--fps` still works and sets each frame rate that the command has. `--input-fps`, `--output-fps` and `--video-fps` set one each, see [Frame rates](https://github.com/yama6a/subtitle-toolbox-php/blob/6.11.5/docs/cli.md#frame-rates).

### Renamed options
| 1.x | 2.0 |
|:--- |:--- |
| `convert movie.srt --to srt --case upper --case-language tr -o out.srt` | `convert movie.srt --to srt --case upper --language tr -o out.srt` |
| `convert movie.srt --to srt --replace '/a/=b' --regex --ignore-case -o out.srt` | `convert movie.srt --to srt --replace '/a/=b' --replace-regex --replace-ignore-case -o out.srt` |
| `convert movie.srt --to ass --karaoke-tag kf --word-timestamps -o out.ass` | `convert movie.srt --to ass --ass-karaoke-tag kf -o out.ass` |
| `convert movie.srt --to srt --karaoke --karaoke-mode cumulative --karaoke-words 3 -o out.srt` | `convert movie.srt --to srt --karaoke -o out.srt` |
| `convert movie.srt --to srt --speakers colours -o out.srt` | `convert movie.srt --to srt --speakers colors -o out.srt` |
| `validate movie.srt --no-overlap --no-empty-cues --no-double-spaces` | `validate movie.srt --check-overlaps --check-empty-cues --check-double-spaces` |
| `validate movie.srt --no-leading-or-trailing-spaces --no-unbalanced-tags --no-all-caps-lines` | `validate movie.srt --check-leading-or-trailing-spaces --check-unbalanced-tags --check-all-caps-lines` |
| `validate movie.srt --preset netflix-en --fps 24` | `validate movie.srt --preset netflix-en --video-fps 24` |

2.0 has no `--karaoke-mode` and `--karaoke-words`. Use `WordHighlightOptions` in PHP for them.

### Removed commands
`convert` and the new `retime` command take over the commands `shift`, `scale`, `fps`, `sync-fps`, `fix`, `strip-sdh` and `snap`.

| 1.x | 2.0 |
|:--- |:--- |
| `shift movie.srt --by 2 --after 60 -o out.srt` | `retime movie.srt --shift 2 --shift-after 60 -o out.srt` |
| `scale movie.srt --factor 1.001 -o out.srt` | `retime movie.srt --scale 1.001 -o out.srt` |
| `fps movie.srt --from 25 --to 23.976 -o out.srt`, `sync-fps movie.srt --from 25 --to 23.976 -o out2.srt` | `retime movie.srt --from-fps 25 --to-fps 23.976 -o out.srt` |
| `fix movie.srt --overlaps --min-duration 1 --min-gap 0.083 -o out.srt` | `convert movie.srt --to srt --timing-fix-overlaps --timing-min-duration 1 --timing-min-gap 0.083 -o out.srt` |
| `fix movie.srt --common-errors --replace-list ocr.xml --list-fixes --language en -o out.srt` | `convert movie.srt --to srt --errors-fix --errors-replace-list ocr.xml --errors-list-fixes --language en -o out.srt` |
| `fix movie.srt --wrap 32 --max-lines 3 -o out.srt` | `convert movie.srt --to srt --structure-wrap --structure-max-cpl 32 --structure-max-lines 3 -o out.srt` |
| `fix words.json --resegment --max-word-gap 0.3 --max-cpl 32 --max-lines 1 -o out.srt` | `convert words.json --to srt --structure-resegment --structure-max-word-gap 0.3 --structure-max-cpl 32 --structure-max-lines 1 -o out.srt` |
| `fix movie.srt --unwrap --merge-short --split-long --merge-duplicates -o out.srt` | `convert movie.srt --to srt --structure-unwrap --structure-merge-short --structure-split-long --structure-merge-duplicates -o out.srt` |
| `strip-sdh movie.srt --lyrics --brackets "{}" --any-case-labels --keep-music-lines -o out.srt` | `convert movie.srt --to srt --sdh --sdh-lyrics --sdh-brackets "{}" --sdh-any-case-labels --sdh-keep-music-lines -o out.srt` |
| `strip-sdh movie.srt --keep-square-brackets --keep-parentheses --keep-speaker-labels -o out.srt` | `convert movie.srt --to srt --sdh --sdh-keep-square-brackets --sdh-keep-parentheses --sdh-keep-speaker-labels -o out.srt` |
| `snap movie.srt --shot-changes shots.txt --fps 24 --snap-window 12 --min-gap-frames 2 --min-duration-frames 20 --no-chain -o out.srt` | `convert movie.srt --to srt --snap-shot-changes shots.txt --video-fps 24 --snap-window-frames 12 --snap-min-gap-frames 2 --snap-min-duration-frames 20 --no-snap-chain -o out.srt` |
| `snap movie.srt --fps 24 -o out.srt` | `convert movie.srt --to srt --video-fps 24 --snap-min-gap-frames 2 -o out.srt` |

- **Rule**: each `fix --X` becomes `convert --timing-X`, `--errors-X` or `--structure-X`. Each `strip-sdh --X` becomes `convert --sdh-X`. Each `snap --X` becomes `convert --snap-X`.
- **Width**: `--structure-wrap` takes no value. Its width is `--structure-max-cpl`, default 42.
- **Unknown command**: a removed command fails with exit code 2, like any unknown command.

## JSON shapes
Each call runs in both versions and prints another shape.

| Call | 1.x | 2.0 |
|:--- |:--- |:--- |
| `info movie.srt --json` | one object | a list with one object per input, also for `validate`. `diff` prints a list with one object. Read `[0]` for one input |
| `info missing.srt --json` | nothing | `[]`, also for `validate` and `diff` |
| `validate movie.srt --max-cpl 10 --json` | `results`, each with `cueIndex` and `cueNumber` | `violations`, each with `cueIndex` and `infinite`. The cue number is `cueIndex + 1`. The object also has `warnings` |
| `diff old.srt new.srt --json` | `old` and `new` for the file names | `oldFile`, `newFile`, `oldWarnings` and `newWarnings`. Each difference keeps `old` and `new` for the cues |
| `info movie.srt --json` | `statistics.gap`, and `statistics.mostUsedWords` as `{"you": 3}` | `statistics.gaps`, and `statistics.mostUsedWords` as `[{"word": "you", "count": 3}]` |
| `info - --json < movie.srt` | `"file": "stdin"` | `"file": "-"`, also for `oldFile` and `newFile` |
| `info movie.mkv --json` | `"format": "matroska"` | `"container": "matroska"` |
| `info chapters.txt --from ytchapter --json` | `"format": "ytchapter"` | `"format": "youtube-chapters"` |

- **Statistics**: `span`, `charactersPerSecond`, `wordsPerMinute`, `charactersPerLine` and `gaps` are null when there is nothing to measure, for example `gaps` with 1 cue. `SubtitleStatistics::toArray()` has the same keys.
- **Library JSON**: the format data keys are the `Format` values, see [Formats](#formats). 2.0 keeps the data of an old key, and no formatter reads it. Rename the keys before you read 1.x JSON.

## Behavior changes
The same code runs in both versions and gives another result.

| Change | 1.x | 2.0 | To keep the 1.x result |
|:--- |:--- |:--- |:--- |
| Last cue without an end time in TMPlayer and SCC | 4 s | 5 s | `new ReadOptions(lastCueDuration: 4)` |
| Last cue without an end time in SubViewer 1, LRC, SAMI, CSV, TSV, HTML transcripts and Podcasting 2.0 transcripts | 10 s | 5 s | `new ReadOptions(lastCueDuration: 10)` |
| Word timestamps in the cue text, such as `<00:00:02.000>`, after `shift()`, `scale()`, `convertFrameRate()` and the other retiming calls | stay at their old times | move with the cue | nothing. The 1.x times were wrong |
| Auto-detection of chapters and cloud speech-to-text JSON | finds them | throws `UnknownFormatException` | `Subtitle::load('call.json', Format::Deepgram)` |
| `ParseWarning::$lineNumber` and `$blockIndex` without a value | 0 or -1 | null | test for null |
| `ParseWarning::$message` | ends with " (line N)" for some formats | has no line suffix | read `$lineNumber` |
| `SubtitleStatistics` without data | 0 | null | test for null |
| `$runner->translate($german, 'de', 'en')` | returns a translated copy | translates `$german` and returns a `TranslationReport` | `$copy = clone $german;`, then `$runner->translate($copy, 'de', 'en')` |
| CLI with one input and no `-o` | writes a file next to the input | writes to standard output | `-o FILE` |
| CLI exit code of a file that fails | 1 | 3. Exit code 1 only means a broken `validate` rule or a `diff` difference | test for 3 |
| CLI `--ocr-language` without its data | exit code 1 for each file | exit code 2 before the first file | install the language |
| `convert --help` | lists every option | lists the common options and the option groups | `convert --help all` |
