# Backward compatibility

The library follows semantic versioning. A 5.x minor or patch release does not break code that uses the parts below as this page describes. A break waits for 6.0.

## Covered
| Part | What stays stable in 5.x |
|:--- |:--- |
| PHP API | public classes, methods, properties, constants and enums that are not `@internal` |
| Method behaviour | the behaviour that the docs describe for a method, and the default value of each method parameter, such as `maxGap` of `removeDuplicateCues()`. Only a major release changes them |
| Parameter names | every parameter name. Call options constructors with named arguments, for example `new WriteOptions(bom: true)` |
| Exceptions | the exception classes, their codes and the parents that [errors.md](errors.md) lists, including the SPL parents |
| CLI | the commands, the options, the meaning of each exit code and the `--json` shapes of the binary `stbox`. The CLI never overwrites a file |
| Library JSON | version 1 of the JSON that `toString(Format::Json)` and `toArray()` write, and that `Format::Json` and `fromArray()` read. See [json.md](json.md) |
| Format data | the keys and the value types of the format data that `findFormatData()` returns. See [formats.md](formats.md) |

## Changes a minor or patch release can make
- **Bug fixes and new formats**: a bug fix or a new format can change the written bytes, the parsed cues and the detection result of a file. The release notes list each change.
- **Strict mode**: strict mode, the default without `new ReadOptions(lenient: true)`, can start to reject bad input that it read before. That is a bug fix, not a break. See [lenient-parsing.md](lenient-parsing.md).

## Changes a minor release can make
- **Enum cases**: an enum such as `Format`, `ValidationRule` or `CommonErrorRule` can get a new case. Give a `match` on an enum a `default` arm.
- **String values in JSON**: a string field of the `--json` output or the library JSON can get a new value. Examples are `kind`, `rule`, `action` and `format`.
- **Option defaults**: an options constructor parameter, such as `bom` of `WriteOptions`, can get a new default value, also a CLI default such as `hls --segment 6`. The release notes list each change. Pass the value to keep it.
- **PHP version and extensions**: a minor release can raise the minimum PHP version or need a new PHP extension. The release notes announce it.
- **Interface methods**: implement only `OcrEngine`, `TranslationEngine` and `HttpClient`. They stay as they are. The other interfaces, such as `CueStreamReader`, can get new methods.
- **Parameter order of options**: an options constructor can get a new parameter at any position. Pass its arguments by name.
- **New fields**: reports, the `--json` output, the library JSON and the format data of `findFormatData()` can get new fields. Ignore fields that you do not know.
- **Format data checks**: `setFormatData()` can check the type of a new field. Format data that passed before can then throw `InvalidArgumentException`.
- **Exceptions and exit codes**: only the classes and their codes are API. The class that a given cause throws, and the exit code that it gives, can change.
- **Messages**: the text of an exception message and the text output of the CLI can change. Test the exception class, `getCode()` or `ParsingException::getLineNumber()`, and parse `--json` output.

## Text fixes and presets
- **Existing rules**: a patch release can change a rule so that it stops changing text that it should keep. This applies to `CommonErrorFixer`, `HearingImpairedRemover`, `ProfanityFilter` and `SpeakerLabels`.
- **New rules**: a new rule is off by default. Turn it on with its option.
- **Presets**: `ValidationRules::netflixEnglish()`, `ValidationRules::bbc()` and the CLI `validate --preset` never get a new rule in 5.x.

## Major releases
A major release can remove or change anything above. Its upgrade guide lists each change.

## Not covered
- **`@internal`**: a class, method or constant marked `@internal` can change in any release. An example is `ImageFormatter`.
- **Constructors of reports and results**: only the library creates reports and results. Read their fields, but do not create them. `RecognizedText` and `Comment` are the 2 exceptions with a public constructor, because an `OcrEngine` returns `RecognizedText`. These constructors are `@internal`:
  - Reports: `CommonErrorReport`, `HearingImpairedReport`, `OcrReport`, `ProfanityReport`, `ReferenceSyncReport`, `ResegmentReport`, `SccWriteReport`, `ShotChangeReport`, `SpeakerLabelReport`, `TranslationReport`, `WebVttWriteReport` and `WordHighlightReport`.
  - Results: `AppliedFix`, `CueDifference`, `ValidationViolation`, `TranslationWarning`, `MuteRange`, `SccFitChange`, `SubtitleTrack`, `SyncSegment`, `WebVttDroppedColor` and `HlsWebVttRendition`.
  - `ParseWarning`, also `ParseWarning::skipped()`.
- **Parsers and formatters as base classes**: do not extend `SubtitleParser` or `SubtitleFormatter`. Their protected members can change in any release. Call a parser or formatter from your own class.
- **Exceptions as base classes**: do not extend an exception class. `UnsupportedFormatException` is not `final`, because `UnknownFormatException` extends it. The protected `CODE` constant of an exception class is not API. `GenericException` is not API, also as a parent.
- **`SubtitleToolbox\Cli`**: the PHP classes of the command line tool. Run the binary instead.
- **`SUBTITLE_TOOLBOX_TRANSLATE_URL`**: the environment variable that points `translate` at a fake server in the tests. `translate` reads it only when its host is `127.0.0.1`, `localhost` or `[::1]`, and ignores it otherwise. It is a test hook and can change or go away in any release.
- **`CurlHttpClient`**: the default `HttpClient` of the translation engines is `@internal`, also its timeouts. Implement `HttpClient` to set your own.
