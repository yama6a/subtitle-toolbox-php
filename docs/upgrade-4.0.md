# Upgrade from 3.x to 4.0

```sh
composer require yama6a/subtitle-toolbox-php:^4.0
```

4.0 needs the same PHP version and extensions as 3.x. [compatibility.md](compatibility.md) says what semantic versioning covers in 4.x. 4.0 has no deprecation period. Each section shows the 3.x call and the 4.0 call side by side.

## Alignment

TODO #675 #771

## Read and write options

TODO #709

## Exceptions

| 3.x | 4.0 |
|:--- |:--- |
| `catch (InvalidParserException $e)` | `catch (UnsupportedFormatException $e)` |
| `catch (InvalidFormatterException $e)` | `catch (UnsupportedFormatException $e)` |
| `$e->getCode() === 101` for `toString(Format::Whisper)` or `save('out.unknown')` | `$e->getCode() === 102` |
| `catch (\InvalidArgumentException $e)` around `toString(Format::Scc)` for a cue with 5 lines | `catch (UnwritableContentException $e)` or `catch (\RuntimeException $e)` |
| `catch (InvalidArgumentException $e)` around `splitCue(9, 1.5, 1)` or `joinCues(0, 9)` on 3 cues | `catch (CueNotFoundException $e)` |

- **One class for formats**: `UnsupportedFormatException` replaces `InvalidParserException` and `InvalidFormatterException`. It keeps code 102. No class has code 101.
- **Unknown format**: `UnknownFormatException` keeps code 106 and extends `UnsupportedFormatException`.
- **Unwritable content**: `UnwritableContentException` keeps code 108 and extends `\RuntimeException`. `catch (\InvalidArgumentException $e)` and `catch (InvalidArgumentException $e)` no longer catch it.
- **Missing cue**: `removeCue()`, `splitCue()` and `joinCues()` throw `CueNotFoundException` for an index without a cue. `joinCues()` checks both indexes before their order, so `joinCues(9, 2)` on 3 cues throws `CueNotFoundException`.
- **Final class**: `InvalidArgumentException` is `final`. `UnsupportedFormatException` is the only exception class that is not `final`.

## Sync

`Subtitle::syncByTwoPoints()` is removed. `syncByPoints()` with 2 `SyncPoint` objects gives the same result.

```php
// 3.x
$subtitle->syncByTwoPoints(10, 12, 6260, 6005);
// 4.0
$subtitle->syncByPoints([new SyncPoint(oldSeconds: 10, newSeconds: 12), new SyncPoint(oldSeconds: 6260, newSeconds: 6005)]);
```

`syncByTwoPoints()` took its 2 points in either order. `syncByPoints()` needs the old and new times to increase from point to point.

## CLI diff

`diff` applies `--from`, `--track` and `--encoding` to both files. `--new-from` and `--new-track` replace `--from` and `--track` for the new file. `--from2` and `--track2` are removed.

```sh
# 3.x
subtitle-toolbox diff a.json b.json --from deepgram --from2 deepgram
subtitle-toolbox diff old.mkv new.mkv --track 3 --track2 8
# 4.0
subtitle-toolbox diff a.json b.json --from deepgram
subtitle-toolbox diff old.mkv new.mkv --track 3 --new-track 8
```

`--track` applies to the new file only when the new file is an MKV, WebM or MP4 file. For `diff old.mkv new.srt --track 3`, `diff` reads track 3 of `old.mkv` and ignores `--track` for `new.srt`. 
