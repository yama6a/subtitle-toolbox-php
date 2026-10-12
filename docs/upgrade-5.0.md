# Upgrade from 4.x to 5.0

```sh
composer require yama6a/subtitle-toolbox-php:^5.0
```

5.0 needs the same PHP version and extensions as 4.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 5.x. 5.0 has no deprecation period. Each section shows the 4.x call and the 5.0 call side by side.

## Command name

TODO #960

## Cue order

TODO #962

## Removed commands and aliases

| 4.x | 5.0 |
|:--- |:--- |
| `subtitle-toolbox retime movie.srt --shift 1.5 -o out.srt` | `subtitle-toolbox convert movie.srt --shift 1.5 -o out.srt` |

- `retime` is removed. Use `convert` with the same options and without `--to`.
- The output files are byte-equal.
- `retime` exits with code 2 as an unknown command.
- `retime` with no edit option exited with code 2. `convert` without an edit option copies the file.

TODO #964

## Output file extension

TODO #965

## Exceptions

Image cues without text now throw `UnwritableContentException`, like all other content that a format cannot hold. `ImageCueWithoutTextException` no longer exists.

| Old | New |
|:--- |:--- |
| `catch (ImageCueWithoutTextException $e)` | `catch (UnwritableContentException $e)` |
| error code 103 | error code 108 |

- Code 103 is retired and never used again.
- A catch of `UnwritableContentException` now also catches this error. Check the message if you handle the two cases differently.
- The message still names the cue, and says that the cue needs OCR or a bitmap format.

## Line wrapping of Chinese and Japanese

`wrapLines()` breaks Chinese and Japanese lines between 2 characters. 4.x broke only at spaces, so it kept such a line long. The call stays the same.

| Call | 4.x | 5.0 |
|:--- |:--- |:--- |
| `wrapLines(16)` on `我们明天早上八点在火车站见面，然后一起坐车去北京看望奶奶。` | 1 line of 29 characters | `我们明天早上八点在火车站见面，` and `然后一起坐车去北京看望奶奶。` |
| `unwrapLines()` on `我们明天早上八点在火车站见面，` and `然后一起坐车去北京看望奶奶。` | joins them with a space | joins them without a space |

- `--structure-wrap`, `mergeShortCues()` and `Resegmenter` with `ResegmentMode::SplitLong` measure lines as `wrapLines()` wraps them. So `mergeShortCues()` can join more Chinese and Japanese cues, and `SplitLong` splits fewer.
- Korean text and text with spaces keep their line breaks.
- [editing.md](editing.md#overlaps-short-cues-and-line-breaks) lists the break rules.

## Public API cleanup

TODO #968

## Renamed methods

TODO #969 #971

## Load and parse

TODO #970

## Options and results

TODO #972
