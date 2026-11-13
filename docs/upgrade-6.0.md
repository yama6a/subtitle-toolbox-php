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
