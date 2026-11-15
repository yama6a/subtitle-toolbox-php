# Upgrade from 2.x to 3.0

7.0 removed the command line tool from this package. The CLI parts below describe 6.x and older. The tool lives on in [subtitle-toolbox-go](https://github.com/yama6a/subtitle-toolbox-go).

```sh
composer remove ymakhloufi/subtitle-toolbox
composer require yama6a/subtitle-toolbox-php:^3.0
```

3.0 needs the same PHP version and extensions as 2.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 3.x. [Behavior changes](#behavior-changes) lists the code that runs in both versions but gives another result.

## Package
| | 2.x | 3.0 |
|:--- |:--- |:--- |
| Composer package | `ymakhloufi/subtitle-toolbox` | `yama6a/subtitle-toolbox-php` |
| Image | `ghcr.io/yama6a/subtitle-toolbox:2.18.10` | `ghcr.io/yama6a/subtitle-toolbox-php:3.0.0` |
| Tesseract image | `ghcr.io/yama6a/subtitle-toolbox:2.18.10-tesseract` | `ghcr.io/yama6a/subtitle-toolbox-php:3.0.0-tesseract` |
| Repository | https://github.com/yama6a/subtitle-toolbox | https://github.com/yama6a/subtitle-toolbox-php |

- **Never both**: never install both packages in one project. Both hold the namespace `SubtitleToolbox\`, so one package overwrites the classes of the other. Remove the old package before you require the new one.
- **Conflict**: 2.18.9 and later declare a conflict with `yama6a/subtitle-toolbox-php`, so Composer refuses to install both. An older 2.x has no such conflict, and Composer installs both without an error.
- **Same names**: the namespace `SubtitleToolbox\` and the binary `subtitle-toolbox` do not change.

## Changed calls
| 2.x | 3.0 |
|:--- |:--- |
| `new CueLimits(minDuration: 8, maxDuration: 5)` | `new CueLimits(minDuration: 5, maxDuration: 5)` |
| `use SubtitleToolbox\Container\Matroska\MatroskaTrack;` | `use SubtitleToolbox\Container\SubtitleTrack;` |
| `array_map(fn (MatroskaTrack $track): int => $track->number, Subtitle::tracks($path))` | `array_map(fn (SubtitleTrack $track): int => $track->number, Subtitle::tracks($path))` |
| `$subtitle->setFormatData('csv', ['timeFormat' => 'hh:mm:ss;fff'] + $subtitle->findFormatData('csv'))` | `$subtitle->setFormatData('csv', ['timeFormat' => CsvTimeFormat::Dot->value] + $subtitle->findFormatData('csv'))` |

- **CueLimits**: `new CueLimits()` throws `InvalidArgumentException` when `minDuration` is greater than `maxDuration`. A joined cue never lasts longer than `maxDuration`, so `mergeShortCues()` gives the same result with `minDuration` set to `maxDuration`.
- **Tracks**: `Subtitle::tracks()` and `MatroskaReader::getSubtitleTracks()` return `SubtitleTrack`. `MatroskaTrack` is gone. `SubtitleTrack` has the fields of `MatroskaTrack`. It adds `container`, for example `ContainerFormat::Matroska`, and `format`, the `Format` that `loadTrack()` reads. See [mkv.md](mkv.md).
- **CSV format data**: `delimiter` must be `,`, `;` or a tab. `timeFormat` must be a value of `CsvTimeFormat`. `setFormatData()` throws `InvalidArgumentException` for another value. `Subtitle::fromArray()` and the JSON reader throw `ParsingException`, or skip the format data in lenient mode. 2.x wrote an unknown time format as `hh:mm:ss.mmm`.

## Behavior changes
The same code runs in both versions and gives another result. The CLI option in the first column changes the same way.

| Call | 2.x | 3.0 | To keep the 2.x result |
|:--- |:--- |:--- |:--- |
| `changeCase(CaseMode::Sentence)` on 2 cues `WE WENT TO THE`, `STORE AND I LEFT.`. CLI `--case sentence` | `We went to the`, `Store and i left.` | `We went to the`, `store and I left.` | no option |
| `changeCase(CaseMode::Sentence)` on 1 cue with the lines `[BELL RINGS]`, `>> TICKETS ARE VALID` | `[Bell rings]`, `>> tickets are valid` | `[Bell rings]`, `>> Tickets are valid` | no option |
| `changeCase(CaseMode::Sentence)` on 1 cue `WAIT… WHAT` | `Wait… what` | `Wait… What` | no option |
| `unwrapLines()` on 1 cue with the lines `- Are you coming?`, `- Yes, in a minute,`, `I promise you.`. CLI `--structure-unwrap` | 1 line `- Are you coming? - Yes, in a minute, I promise you.` | 2 lines `- Are you coming?`, `- Yes, in a minute, I promise you.` | `foreach ($subtitle->getCues() as $cue) { $cue->setLines([implode(' ', $cue->getLines())]); }` |
| `removeDuplicateCues()` on 2 cues `Hello`, 1 s to 3 s and 2 s to 4 s, with the same alignment, forced flag and format data. CLI `--structure-merge-duplicates` | 2 cues | 1 cue, 1 s to 4 s | no option |
| `removeDuplicateCues()` on 2 ASS events `Hello`, `Glow` on layer 0 from 1 s to 3 s and `Default` on layer 1 from 3 s to 5 s | 1 event, 1 s to 5 s | 2 events | no option |
| `HlsWebVttJoiner::join()` on a segment with the same 2 cues | 2 cues | 1 cue, 1 s to 4 s | no option |
| `validate(new ValidationRules(noUnbalancedTags: true))` on a cue `<c.yellow>Hi`. CLI `validate --check-unbalanced-tags` | no violation | 1 violation | no option |
| `toString(Format::MpSub)` | `NOTE=Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)` | `NOTE=Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox-php)` | `$subtitle->setFormatData('mpsub', ['NOTE' => 'your note'] + $subtitle->findFormatData('mpsub'))` |

- **Sentence case**: a cue continues the sentence of the cue before it. A cue starts a new sentence only in these cases:
  - It is the first cue.
  - The cue before it ends with `.`, `!`, `?` or the ellipsis U+2026. Closing quotes or brackets can follow.
  - The cue before it ends with `]`, `)`, `♪` or the `:` of a speaker label such as `JOHN:`. Closing tags can follow.
  - It starts with `[`, `(` or `♪`. Opening tags can come first.
  - It starts 2 s or more after the cue before it.
  - It starts with the speaker change `>>` or a dialogue dash. A line within a cue that starts so also starts a new sentence. A dash before a digit, as in `-20`, is a minus sign.
- **Ellipsis**: the ellipsis U+2026 ends a sentence within a cue, as `.`, `!` and `?` do. `WAIT… WHAT` becomes `Wait… What`. 2.x gave `Wait… what`.
- **English I**: with the language `en`, `en-*` or null, sentence case writes `I`, `I'm`, `I'll`, `I've` and `I'd` in upper case. Pass another language, such as `changeCase(CaseMode::Sentence, 'de')`, to keep a lone `i` in lower case.
- **Unwrap**: each dialogue turn stays on a line of its own. A turn starts at the first line and at each line with a dialogue dash. A cue without dash lines still becomes 1 line.
- **Duplicates**: `removeDuplicateCues()` joins adjacent cues with the same text that are identical, overlap or touch. 2.x joined only a cue that ends at the start of the next. 3.0 keeps same-text cues apart when their alignment, forced flag or format data differ, such as an ASS `Glow` event on layer 0 and a `Default` event on layer 1. 2.x joined such cues when they touched. The new argument `maxGap`, in seconds, also joins same-text cues up to that gap apart.
- **Unbalanced tags**: `noUnbalancedTags` also checks `<c>`, `<lang>`, `<ruby>` and `<rt>`. An open `<v>` still needs no `</v>`.
