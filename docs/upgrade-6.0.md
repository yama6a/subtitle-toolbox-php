# Upgrade from 5.x to 6.0

```sh
composer require yama6a/subtitle-toolbox-php:^6.0
```

6.0 needs PHP 8.3 or later. [compatibility.md](compatibility.md) says what semantic versioning covers in 6.x. 6.0 has no deprecation period. Each section shows the 5.x call or output and the 6.0 one side by side.

## PHP 8.3

| 5.x | 6.0 |
|:--- |:--- |
| PHP 8.2 or later | PHP 8.3 or later |
| `stbox.phar` runs on PHP 8.2 | `stbox.phar` runs on PHP 8.3 |

- Composer does not install 6.0 on PHP 8.2. Stay on 5.17.6 there.
- The PHP extensions stay the same.
- Greek lower case: `changeCase(CaseMode::Lower)` and the other case changes now follow the Unicode Final_Sigma rule. A sigma after a letter without case, as in U+4E2D U+03A3, becomes U+03C3. 5.x wrote U+03C2. The same holds for a sigma before an apostrophe and a letter.

## SubViewer version

The SubViewer formatter now writes the version that the parser stored. `SubViewerWriteOptions::$version` is `?SubViewerVersion` with the default `null`. null writes the stored version, else SubViewer 2.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString("[TITLE]\nBakery\n******** START SCRIPT ********\n[00:00:01]\nHello\n[00:00:02]\n", Format::SubViewer);
$subtitle->toString(Format::SubViewer);
$subtitle->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(version: SubViewerVersion::V2)]));
```

| Call | 5.x | 6.0 |
|:--- |:--- |:--- |
| `toString(Format::SubViewer)` of a SubViewer 1 file | SubViewer 2: `[INFORMATION]`, `[TITLE]Bakery`, `00:00:01.00,00:00:02.00` | SubViewer 1: `[TITLE]`, `Bakery`, `[00:00:01]` |
| `toString(Format::SubViewer)` of a SubViewer 2 file or of another format | SubViewer 2 | SubViewer 2 |
| `version: SubViewerVersion::V2` | SubViewer 2 | SubViewer 2 |

- To keep the 5.x output, pass `version: SubViewerVersion::V2`.
- `stbox convert movie.sub --from subviewer --to subviewer` keeps a SubViewer 1 file as SubViewer 1. The CLI has no option for the version.
- Code that reads `$options->version` gets null for `new SubViewerWriteOptions()`.

## Cue positions in WebVTT and TTML

A cue now keeps the exact point that a WebVTT or TTML file gives it. The new class `CuePosition` holds the point in percent of the video. `SubtitleCue::findPosition()` returns it, and `setPosition()` sets it. The WebVTT and TTML formatters write it for a cue from the other format. All other formatters ignore it.

Example: a TTML cue in the region `tts:origin="10% 10%" tts:extent="80% 85%" tts:displayAlign="after" tts:textAlign="center"` sits at 95 % of the height.

| Conversion | 5.x | 6.0 |
|:--- |:--- |:--- |
| that TTML cue to WebVTT | `00:00:01.000 --> 00:00:02.000` | `00:00:01.000 --> 00:00:02.000 line:95%,end` |
| WebVTT `line:95%,end position:45%,center` to TTML | `region="bottomCenter"`, with `tts:origin="10% 10%" tts:extent="80% 80%"` | `region="bottomCenter-45-95"`, with `tts:origin="0% 0%" tts:extent="90% 95%"` |
| a cue at the default place of its alignment, such as `line:90%,end` or the region `bottomCenter` | unchanged | unchanged |
| WebVTT to WebVTT, TTML to TTML | the stored settings or region | the stored settings or region |

To keep the 5.x output, call `setAlignment()` with the alignment that the cue has. It clears the position:

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.ttml'), Format::Ttml);
foreach ($subtitle->getCues() as $cue) {
    $cue->setAlignment($cue->getAlignment());
}
$subtitle->toString(Format::WebVtt);
```

- `setAlignment()` now clears the position, also with the same alignment. Code that calls `setAlignment()` on a cue from a WebVTT or TTML file drops its point.
- Library JSON stays version 1. A cue with a position gets the key `"position": {"x": 50.0, "y": 95.0}`. A 5.x reader ignores the key. `fromArray()` and `Format::Json` throw `ParsingException` for a bad position, for example `The field cues[0].position.x must be a number from 0 to 100.`
- The iTT parser reads the file as TTML, so its cues get a position too. The iTT formatter ignores it.
- The CLI has no option for the position. `stbox convert movie.ttml --to vtt` writes it.
- [subtitle.md](subtitle.md#alignment-and-format-data) and [formats.md](formats.md#webvtt) describe the rules.
