# JSON and arrays

A web app stores the cues in a database and sends them to the browser as JSON. `toArray()`, `fromArray()`, `JsonFormatter` and `JsonParser` convert a subtitle without loss.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\JsonWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$array = $subtitle->toArray();                     // toArray(false) leaves out the format data
$copy  = Subtitle::fromArray($array);              // equal to $subtitle
$json  = $subtitle->toString(Format::Json, new WriteOptions(formats: [new JsonWriteOptions(prettyPrint: true)]));
$copy  = Subtitle::fromString($json, Format::Json);
```

`JsonFormatter` writes this shape. `toArray()` returns the same shape as a PHP array, with binary strings as they are.

```json
{
    "version": 1,
    "metadata": {"title": "Big Buck Bunny", "language": "en"},
    "comments": [{"text": "Translated by Jane Doe", "beforeCueIndex": 0}],
    "formatData": {"ass": {"scriptInfo": {"PlayResX": "1920"}}},
    "cues": [
        {"start": 1.5, "end": 4.0, "lines": ["Hello", "<i>world</i>"], "identifier": "intro", "alignment": 8, "forced": true, "formatData": {}},
        {"start": 5.0, "end": 6.5, "lines": [], "identifier": null, "alignment": null,
         "formatData": {"image": {"png": {"base64": "iVBORw0KGgo..."}, "x": 640, "y": 940, "width": 2, "height": 1,
                                  "screenWidth": 1920, "screenHeight": 1080, "forced": false}}}
    ]
}
```

| Field | Type | Required | Content |
|:--- |:--- |:--- |:--- |
| `version` | integer | yes | 1. A later version of the shape gets a new number. `fromArray()` rejects all other numbers |
| `metadata` | object of strings | no | the keys of `getAllMetadata()` |
| `comments` | list of objects | no | `text` and `beforeCueIndex`, the fields of the `Comment` objects that `getComments()` returns |
| `formatData` | object of objects | no | the format data of the subtitle by format key |
| `cues` | list of objects | yes | the cues in any order. `fromArray()` and `JsonParser` sort them by start time. Cues with the same start keep their order, and each comment moves with its cue |
| `cues[].start`, `cues[].end` | number | yes | seconds, rounded to milliseconds |
| `cues[].lines` | list of strings | yes | the lines with core markup and escaped `&lt;`, `&gt;` and `&amp;` |
| `cues[].identifier` | string or null | no | the cue identifier |
| `cues[].alignment` | integer or null | no | the value of the `Alignment` case, 1 to 9. `null` and 2 read as `Alignment::BottomCenter`, which is written as `null` |
| `cues[].forced` | boolean | no | written only for a forced cue |
| `cues[].formatData` | object of objects | no | the format data of the cue by format key |

- **Binary data**: `JsonFormatter` writes each format data string that is not valid UTF-8 as `{"base64": "..."}`. The PNG of an image cue is such a string. `JsonParser` decodes every object in the format data that has `base64` as its only key.
- **Errors**: `JsonParser` and `fromArray()` throw `ParsingException` with the path of the bad field. An example is `The field cues[3].start must be a number.` A time of `1e400` or more is not a number. The format data fields that a formatter reads get the same check. An example is `The field formatData.scc.dropFrame must be a boolean.` Other format data fields pass as they are. `JsonParser` also rejects a cue that ends before it starts, see [lenient-parsing.md](lenient-parsing.md#cue-that-ends-before-it-starts). `fromArray()` keeps such a cue as it is.
- **Lenient mode**: `new ReadOptions(lenient: true)` skips a cue with a bad field, as [lenient-parsing.md](lenient-parsing.md) says. It also drops a bad metadata field, a bad comment and the bad format data of one format. Each gives a `ParseWarning` with `blockIndex` null. `fromArray()` has no lenient mode.
- **Text**: cue lines and metadata must be UTF-8. Otherwise `JsonFormatter` throws `UnwritableContentException` with the `JsonException` as its previous exception. Parse a file in another encoding with its [source encoding](encodings.md).
- **Options**: `JsonWriteOptions(prettyPrint: true)` indents with 4 spaces and ends with a newline. `JsonWriteOptions(withFormatData: false)` leaves out the format data. `lineEnding` and `bom` of `WriteOptions` work as in the other formatters.
- **Detection**: an object with a numeric top-level `version` key and a `cues` list detects as `Format::Json`.
