# Upgrade from 6.x to 7.0

```sh
composer require yama6a/subtitle-toolbox-php:^7.0
```

7.0 removes the command line tool `stbox`. The library API does not change. 7.0 needs PHP 8.3 or later and the same PHP extensions as 6.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 7.x. 7.0 has no deprecation period. Each section shows the 6.x form and the 7.0 one side by side.

## Command line tool

The `stbox` command moved to [subtitle-toolbox-go](https://github.com/yama6a/subtitle-toolbox-go).

| 6.x | 7.0 |
|:--- |:--- |
| `vendor/bin/stbox convert movie.srt --to vtt -o movie.vtt` | `stbox convert movie.srt --to vtt -o movie.vtt` of subtitle-toolbox-go |
| `stbox.phar` from the GitHub release | the `stbox` archive from the [subtitle-toolbox-go releases](https://github.com/yama6a/subtitle-toolbox-go/releases) |
| image `ghcr.io/yama6a/subtitle-toolbox-php:6.11.5` | image `ghcr.io/yama6a/subtitle-toolbox-go:<version>` |
| image `ghcr.io/yama6a/subtitle-toolbox-php:6.11.5-tesseract` | image `ghcr.io/yama6a/subtitle-toolbox-go:<version>-tesseract` |

- subtitle-toolbox-go has the same commands, the same options and the same 39 formats.
- Its [command line docs](https://github.com/yama6a/subtitle-toolbox-go/blob/main/docs/cli.md#install) show the install commands for the archive, `go install` and the images.
- The PHARs of the 6.x releases and the 6.x images on ghcr.io stay available. They get no more updates.
- To keep the PHP command line tool, stay on `yama6a/subtitle-toolbox-php:^6.0`.

## PHP code that ran the CLI

Run the `stbox` binary of subtitle-toolbox-go as a process, or call the library directly.

| 6.x command | 7.0 library call |
|:--- |:--- |
| `stbox convert in.srt --to vtt -o out.vtt` | `Subtitle::load('in.srt')->save('out.vtt')` |
| `stbox info in.srt --json`, the field `statistics` | `SubtitleStatistics::of($subtitle)->toArray()` |
| `stbox validate in.srt --preset netflix-en` | `$subtitle->validate(ValidationRules::netflixEnglish(23.976))` |

```php
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleStatistics;
use SubtitleToolbox\Validation\ValidationRules;

$subtitle = Subtitle::load('in.srt');
$subtitle->save('out.vtt');
$statistics = SubtitleStatistics::of($subtitle)->toArray();
$violations = $subtitle->validate(ValidationRules::netflixEnglish(23.976));
```

- `save()` overwrites an existing file. `stbox` never does.
- `save()` takes the format from the extension. Pass a `Format` for an extension that several formats share, such as `.txt` or `.json`.

## Removed internals

| 6.x | 7.0 |
|:--- |:--- |
| `SubtitleToolbox\Cli\*` | removed |
| `Containers::detectFile()` | removed |
| `GlyphOcrEngine::database()` | removed |
| `SubtitleStatistics::MOST_USED_WORDS` | private |
| `TesseractOcrEngine::requireLanguages()` | private |

- Each of them was `@internal`. 7.0 has no replacement for them.
- To tell a container file from a subtitle file, call `Subtitle::tracks()`. It throws `UnsupportedFormatException` for a file that is not MKV, WebM, MP4 or MPEG-TS.

## Composer

| 6.x | 7.0 |
|:--- |:--- |
| `composer.json` has a `bin` entry, so Composer installs `vendor/bin/stbox` | no `bin` entry |
| the CLI uses `ext-pcntl` when it is loaded | no code uses `ext-pcntl` |

No other requirement changes.
