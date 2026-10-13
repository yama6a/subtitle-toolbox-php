# Validation

`validate()` checks the cues against reading and timing rules. It returns one `ValidationViolation` per broken rule. The command line tool runs the same check, see [cli.md](cli.md#validate).

```php
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationRules;

$violations = $subtitle->validate(ValidationRules::netflixEnglish(23.976));
$violations = $subtitle->validate(ValidationRules::bbc());
$violations = $subtitle->validate(new ValidationRules(noUnbalancedTags: true, maxSpeakersPerCue: 2));
$violations = $subtitle->validate(new ValidationRules(maxCharactersPerLine: 37, noEmptyCues: true));

$violations[0]->cueIndex;          // 1
$violations[0]->rule;              // ValidationRule::MaxCharactersPerLine, with the value 'maxCharactersPerLine'
$violations[0]->value;             // 45
$violations[0]->limit;             // 37
```

`validate()` requires the rules, because `new ValidationRules()` checks nothing.

The value of a `ValidationRule` case is the name of its field in `ValidationRules`. `MinChapters` and `FirstChapterAtZero` have no field. `YouTubeChapters::check()` uses them, see [chapters.md](chapters.md).

| Rule | Limit | Value |
|:--- |:--- |:--- |
| `maxCharactersPerSecond` | characters per second | characters of all lines divided by the duration. `INF` for a cue with text and no duration |
| `maxCharactersPerLine` | characters | one violation per line that is too long |
| `maxLinesPerCue` | lines | lines with visible text |
| `minDuration`, `maxDuration` | seconds | end minus start |
| `minGap` | seconds | the [gap](subtitle.md#statistics) to the earlier cues. Overlaps are not gaps |
| `noOverlap` | `true` to check, violation limit `null` | seconds of overlap with the earlier cues |
| `noEmptyCues` | `true` to check, violation limit `null` | 0 |
| `noDoubleSpaces` | `true` to check, violation limit `null` | runs of two or more spaces between words. A non-breaking space counts as a space |
| `noLeadingOrTrailingSpaces` | `true` to check, violation limit `null` | lines that start or end with a space or a non-breaking space |
| `noUnbalancedTags` | `true` to check, violation limit `null` | `<b>`, `<i>`, `<u>`, `<s>`, `<font>`, `<v>`, `<c>`, `<lang>`, `<ruby>` and `<rt>` tags without a partner tag, across all lines of the cue, plus each `<rt>` outside `<ruby>`. An open `<v>` needs no `</v>`. An open `<rt>` needs no `</rt>` before `</ruby>` or the next `<rt>` |
| `dialogueDashStyle` | a `DialogueDashStyle` case, such as `HyphenSpace` for `'- '` or `EnDash` for an en dash without a space. Violation limit `null` | lines with a dialogue dash in another style |
| `maxSpeakersPerCue` | speakers | the lines with a dialogue dash or the different `<v>` names, the larger count |
| `maxWordsPerMinute` | words per minute | words divided by the duration. `INF` for a cue with words and no duration |
| `minSecondsPerWord` | seconds per word | duration divided by the words |
| `allowedCharacters` | the allowed characters as a string, or a regular expression character class such as `'[A-Za-z0-9 .,!?]'`. Violation limit `null` | characters that are not allowed. A space is always allowed |
| `noAllCapsLines` | `true` to check, violation limit `null` | lines with two or more upper case letters and no lower case letter. `<v>` names and text in `[]` or `()` do not count |
| `requireCues` | `true` to check, violation limit `null` | 0. The violation has the cue index `null` |
| `noNegativeDuration` | `true` to check, violation limit `null` | end minus start, below 0 |

- **Off by default**: a rule with the limit `null` or `false` is off.
- **Number limits**: a limit must be 0 or more. NAN throws `InvalidArgumentException`. A maximum accepts `INF`, which checks nothing. A minimum must be finite.
- **Characters**: the rules count characters as `SubtitleStatistics` does, see [Statistics](subtitle.md#statistics).
- **Spaces**: the text rules check the text without tags, with entities decoded. A cue stores runs of spaces as one space. So two spaces come from tags, as in `you? <i> Home</i>`, or from non-breaking spaces.
- **Dialogue dash**: a hyphen, an en dash or an em dash at the start of a line. A digit or another dash must not follow it. So `-20 degrees` has no dialogue dash. Bidi controls before the dash, such as the U+202B that `fixRightToLeft()` adds, do not hide it.
- **Words**: text runs between white space, as `SubtitleStatistics` counts them. A lone dash is a word.
- **Milliseconds**: cue times have millisecond precision. So a cue of 0.833 s meets a minimum duration of 5/6 s.

## Presets
| Preset | Limits | Source |
|:--- |:--- |:--- |
| `ValidationRules::netflixEnglish($frameRate)` | 20 characters per second, 42 characters per line, 2 lines, 5/6 s to 7 s, a gap of 2 frames at the frame rate, no overlaps | [English (USA) Timed Text Style Guide](https://partnerhelp.netflixstudios.com/hc/en-us/articles/217350977-English-USA-Timed-Text-Style-Guide), [General Requirements](https://partnerhelp.netflixstudios.com/hc/en-us/articles/215758617), [Subtitle Timing Guidelines](https://partnerhelp.netflixstudios.com/hc/en-us/articles/360051554394) |
| `ValidationRules::bbc()` | 37 characters per line, 180 words per minute, 0.3 s per word | [BBC Subtitle Guidelines](https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/), sections 3.1 and 4 |
| `ValidationRules::structure()` | `requireCues`, `noNegativeDuration` and `noOverlap` | the cue list of `Subtitle` |

- **Netflix**: 20 characters per second is the limit for adult programs.
- **BBC**: 37 characters is the broadcast line length. 180 words per minute is the upper end of the 160 to 180 that the guide gives. 0.3 s per word allows 200 words per minute. So a cue can break the speed rule and still meet the time per word.
