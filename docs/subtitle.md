# Subtitles and cues

A `Subtitle` holds a sorted list of `SubtitleCue` objects plus the data of the file: metadata, comments and format data.

## Call shapes
Every edit has one of 3 shapes:

| Shape | Example | Returns |
|:--- |:--- |:--- |
| method on `Subtitle` | `$subtitle->shift(2)->wrapLines(42)` | `$this`, so calls chain |
| method that starts with `with` or `to` | `$subtitle->withForcedCuesOnly()`, `$subtitle->withSlice(10, 20)`, `$subtitle->toArray()` | a new value. `with` gives a new `Subtitle`. `to` gives a conversion, such as an array. The original stays unchanged |
| service with `apply()` | `HearingImpairedRemover::apply($subtitle)` | a report. The service changes `$subtitle` |

- **Names**: a method that returns a new `Subtitle` starts with `with`. A method that converts the subtitle to another type starts with `to`, such as `toArray()` or `toString()`. A method that changes the subtitle is a verb, such as `shift()` or `removeCuesWhere()`.
- **Service**: a service is a class with one static `apply(Subtitle $subtitle, ?XOptions $options = null): XReport`. The services are `Resegmenter`, `HearingImpairedRemover`, `ReferenceSync`, `ShotChangeTiming`, `CommonErrorFixer`, `WordHighlight`, `ProfanityFilter` and `SpeakerLabels`. `OcrRunner::run()` and `TranslationRunner::translate()` also change the subtitle and return a report. `DualSubtitle::fromPair()` builds a new subtitle from two.
- **Options**: a method or constructor that takes an options object accepts `null` or no argument, and then uses `new XOptions()`. `Resegmenter`, `ReferenceSync`, `ShotChangeTiming` and `ProfanityFilter` require the options, because their options class has a required argument. `SpeakerLabels::apply()` and `validate()` require the options, because the default options change and check nothing.
- **Keep the original**: `clone` copies the cues too. Pass `clone $subtitle` to a service or to a method that changes the subtitle, and the original stays unchanged.

## Metadata, comments and cue identifiers
Parsers fill these fields where their format has them, and formatters write them back. [formats.md](formats.md) lists what each format keeps.

```php
use SubtitleToolbox\Subtitle;

$subtitle->setMetadata(Subtitle::METADATA_TITLE, 'Yesterday');
$subtitle->findMetadata('title');             // 'Yesterday'
$subtitle->setMetadata('title', null);        // removes the key
$subtitle->getAllMetadata();                  // []

$subtitle->addComment('Translated by Jane Doe', 0);
$subtitle->getComments()[0]->text;            // 'Translated by Jane Doe'
$subtitle->getComments()[0]->beforeCueIndex;  // 0

$cue->setIdentifier('intro');
```

- **Metadata keys**: `Subtitle` has constants for the shared keys `title`, `author`, `artist`, `album` and `language`.
- **Comments**: `getComments()` returns `Comment` objects with the readonly fields `text` and `beforeCueIndex`. A comment comes before the cue at `beforeCueIndex`. An index equal to the cue count puts it after the last cue.
- **Re-index**: `reIndexCues()` sorts the cues by start time and numbers them from 0. It moves each comment together with its cue. A comment before a removed cue moves to the next cue.

## Alignment and format data
```php
use SubtitleToolbox\Alignment;

$cue->setAlignment(Alignment::TopCenter);
$cue->setFormatData('ass', ['fields' => ['Style' => 'Sign']]);
$subtitle->findFormatData('ass');                       // [] when not set
```

- **Alignment**: the `Alignment` enum has 9 cases, from `BottomLeft` to `TopRight`. A cue without a position returns `Alignment::BottomCenter`, the default of every format. The value of a case is its key on a numeric keypad, for example 8 for `TopCenter`. JSON and format data hold this value.
- **Format data**: the data of a format that has no shared field, for example ASS styles. Only the formatter of the same format reads it. The key is the value of the `Format` case, for example `ass` for `Format::Ass` and `microdvd` for `Format::MicroDvd`. CSV and TSV share the key `csv`. Image cues use the key `image`. [formats.md](formats.md) lists the fields of each format.
- **Checks**: `setFormatData()` checks the fields that a formatter reads, as `fromArray()` does. A field of the wrong type, or a value outside its allowed set, throws `InvalidArgumentException` with its path, for example `The field formatData.scc.dropFrame must be a boolean.` Other fields pass as they are.

## Cues from timecode strings
`SubtitleCue` takes seconds. `Timecode::parse()` turns a timecode string into seconds.

```php
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

Timecode::parse('00:01:02,500');                       // 62.5
Timecode::parse('01:02.5');                            // 62.5
Timecode::parse('00:01:02:12', new FrameRate(25));     // 62.48, frame 12 of second 62

$subtitle->addCue(new SubtitleCue(Timecode::parse('00:01:02,500'), Timecode::parse('00:01:05,000'), 'Good morning.'));
```

| Shape | Examples | Notes |
|:--- |:--- |:--- |
| `h:mm:ss` with an optional fraction | `00:01:02,500`, `0:01:02.5`, `100:00:00` | 1 or more hour digits |
| `m:ss` with an optional fraction | `01:02.500`, `75:00` | the minutes can be 60 or more |
| `h:mm:ss:ff` | `00:01:02:12` | `ff` counts frames after the last whole second. It needs a frame rate |

- **Fraction**: a period or a comma, then 1 or more digits. `02.5` and `02,500` are both 2.5 s.
- **Strict digits**: minutes and seconds after a colon have 2 digits from 00 to 59. So `0:1:02` is invalid.
- **Frames**: the frame number must be below the frame rate rounded to a whole number, so below 24 at 23.976 fps. Drop-frame timecodes with `;` are invalid.
- **Invalid input**: a sign, a space, bare seconds such as `62.5`, or a time from 100000 hours throws `InvalidArgumentException`. The message quotes the input.

## Finding cues
```php
use SubtitleToolbox\SubtitleCue;

count($subtitle);                               // 612
foreach ($subtitle as $index => $cue) { }       // in index order

$subtitle->findCuesAt(83.2);                    // [41 => $cue], the cues on screen at 83.2 s
$subtitle->findCueIndexAt(83.2);                // 41, or null when no cue is on screen
$subtitle->findCuesBetween(600, 660);           // the cues that overlap 600 s to 660 s, not cut
$subtitle->findCues(fn (SubtitleCue $cue) => str_contains($cue->getText(), 'Paris'));
$subtitle->removeCuesWhere(fn (SubtitleCue $cue) => $cue->getEnd() - $cue->getStart() < 0.5);
```

- **On screen**: a cue is on screen at time `t` when `start <= t < end`. A cue from 4.0 s to 6.0 s is on screen at 4.0 s, but not at 6.0 s. A cue with the same start and end is never on screen.
- **Overlaps**: cues can overlap, so `findCuesAt()` returns an array. `findCueIndexAt()` returns the lowest index of these cues.
- **Keys**: `findCuesAt()`, `findCuesBetween()` and `findCues()` keep the cue index as the array key.
- **Remove**: `removeCuesWhere()` moves a comment before a removed cue to the next kept cue, and then calls `reIndexCues()`.
- **Add and remove**: `addCue()` and `addCues()` keep the cues sorted by start time. A cue that starts at or after the last cue goes to the end without a sort, so `addCue()` in a loop over cues in start order is as fast as `addCues()`. A comment after the last cue stays after the last cue, also when the added cue sorts last. `removeCue()` numbers the remaining cues from 0 again.
- **No array access**: `$subtitle[3]` does not work. Use `getCues()`, `addCue()` and `removeCue()`, so the cue indexes and comments stay correct.
- **Structure check**: `validate(ValidationRules::structure())` returns one violation per problem. It reports a subtitle without cues. It also reports a cue that starts before the previous cue starts or ends, and a cue that ends before it starts. See [validation](validation.md).

## Forced cues
A **forced cue** shows also when the viewer has turned subtitles off, for example the translation of a sign. Apple and Netflix take a full subtitle file and a separate file with only the forced cues.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.itt'), Format::Itt);   // <p itts:forcedDisplay="true">Sector 7 ahead</p>
$subtitle->getCues()[3]->isForced();                                             // true
$subtitle->getCues()[4]->setForced(true);
$forced = $subtitle->withForcedCuesOnly();                                       // a new Subtitle with copies of the forced cues
file_put_contents('movie.forced.itt', $forced->toString(Format::Itt));
```

| Format | Read | Write |
|:--- |:--- |:--- |
| TTML, IMSC, DFXP | `itts:forcedDisplay="true"` on `p`, `span`, `div`, `body`, the region or a referenced style | the same attribute on `p` |
| iTT | as TTML | the same attribute on `p` |
| PGS | the forced flag of the object | the forced flag of the object |
| VobSub | the forced flag of the unit | no formatter |
| MKV | the forced flag of the track, see [mkv.md](mkv.md) | no formatter |
| JSON | `forced` | `forced` |
| other formats | no flag | the flag is lost |

- **Default**: a cue is not forced.
- **TTML spans**: one forced `span` makes the whole cue forced. The formatter then writes the flag on the `p`, so the whole paragraph becomes forced.
- **`withForcedCuesOnly()`**: works as `withSlice()`. The copy keeps the metadata, the format data and the comments before the forced cues. The original stays unchanged.
- **OCR**: OCR keeps the flag of an image cue.

## Statistics
```php
use SubtitleToolbox\SubtitleStatistics;

$stats = SubtitleStatistics::of($subtitle);
$stats->cueCount;                          // 612
$stats->wordCount;                         // 4870
$stats->characterCount;                    // 25310
$stats->totalDisplayTime;                  // 1742.5, the sum of the cue durations in seconds
$stats->span;                              // 2688.0, the seconds from the first start to the last end
$stats->charactersPerSecond;               // ['min' => 3.1, 'average' => 14.5, 'max' => 31.2]
$stats->wordsPerMinute;                    // ['min' => 40.0, 'average' => 168.0, 'max' => 390.0]
$stats->charactersPerLine;                 // ['min' => 2.0, 'average' => 31.0, 'max' => 47.0]
$stats->gaps;                              // ['min' => 0.0, 'average' => 2.9, 'max' => 41.0]
array_slice($stats->mostUsedWords, 0, 10); // [['word' => 'you', 'count' => 211], ['word' => 'the', 'count' => 160], ...]
json_encode($stats->toArray());            // all numbers and the 10 most used words
```

- **Characters**: the count leaves out tags and leading and trailing spaces. An entity such as `&amp;` and a UTF-8 letter of several bytes count as one character.
- **Words**: the text without tags, split at whitespace. A dialogue dash counts as a word. `mostUsedWords` lists every word, the most used first. It removes punctuation at the start and end of each word and compares in lower case.
- **Cues without text**: an image cue counts in `cueCount`, the display time, the span and the gaps. The text numbers leave it out.
- **Reading speed**: a cue with a duration of 0 has no characters per second and no words per minute.
- **Gap**: the start of a cue minus the latest end of the earlier cues. An overlap gives a negative gap.
- **No data**: a range with no value to measure is null. Examples are `gaps` with fewer than 2 cues, and `charactersPerSecond` without a cue that has text and a duration. Without cues, `span` is null too, and the counts and `totalDisplayTime` are 0. `toArray()` writes the same nulls.
