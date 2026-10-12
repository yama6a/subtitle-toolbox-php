# Chapters

A chapter list names the parts of a video or a podcast episode. Each chapter is a cue with the title as its only line.

| Format | Format name | Parser and formatter | Example |
|:--- |:--- |:--- |:--- |
| YouTube description text | `youtube-chapters` | `YouTubeChaptersParser`, `YouTubeChaptersFormatter` | `2:48 Hearing aids` |
| Podcasting 2.0 JSON chapters | `podcast-chapters` | `PodcastChaptersParser`, `PodcastChaptersFormatter` | `{"version": "1.2.0", "chapters": [{"startTime": 168, "title": "Hearing aids"}]}` |
| FFmpeg metadata | `ffmeta-chapters` | `FfMetadataChaptersParser`, `FfMetadataChaptersFormatter` | `;FFMETADATA1`, then `[CHAPTER]`, `TIMEBASE=1/1000`, `START=168000`, `END=260000`, `title=Hearing aids` |
| OGM chapters, the mkvmerge simple format | `ogm-chapters` | `OgmChaptersParser`, `OgmChaptersFormatter` | `CHAPTER02=00:02:48.000`, then `CHAPTER02NAME=Hearing aids` |
| WebVTT chapters | `vtt` | `WebVttParser`, `WebVttFormatter` | a WebVTT file with one cue per chapter |

```php
use SubtitleToolbox\Chapters\YouTubeChapters;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$chapters = Subtitle::fromString(file_get_contents('chapters.json'), Format::PodcastChapters,
    new ReadOptions(formats: [new ChapterReadOptions(mediaDuration: 4980)]));
$text     = $chapters->toString(Format::YouTubeChapters);       // for the video description
$meta     = $chapters->toString(Format::FfMetadataChapters);    // ffmpeg -i in.mp4 -i meta.ffmeta -map_metadata 1 out.mp4
$broken   = YouTubeChapters::check($chapters);                  // [] when YouTube shows the chapters
$chapters = Subtitle::fromString($videoDescription, Format::YouTubeChapters);
```

- **End times**: a chapter ends where the next chapter starts. The last chapter ends at `ChapterReadOptions::$mediaDuration` in seconds. Without it, the last chapter ends at its own start. An `endTime` in the JSON and an `END` line in FFmpeg metadata win.
- **YouTube text**: the parser reads only the lines that start or end with a time. Examples are `2:48`, `02:48`, `1:02:48` and `(2:48)`. So you can pass a whole video description. A line such as `Doors open at 18:30` also becomes a chapter. The formatter writes `m:ss` below one hour and `h:mm:ss` from one hour, in whole seconds.
- **YouTube rules**: `YouTubeChapters::check()` returns one `ValidationViolation` per broken rule and chapter. Its `cueIndex` is the chapter index. An example has `cueIndex` 2, `rule` `ValidationRule::MinDuration`, `value` 4.5 and `limit` 10. The rules are `ValidationRule::FirstChapterAtZero`, `MinChapters` (3) and `MinDuration` (10 s), from [YouTube Help](https://support.google.com/youtube/answer/9884579). The check skips a last chapter that ends at its own start, because its length is unknown.
- **Podcasting 2.0**: the `title` and `author` of the file become metadata. The `podcast-chapters` format data of the subtitle keeps `version` and the other fields. Each cue keeps `img`, `url`, `toc`, `location` and other fields in its `podcast-chapters` format data. The formatter writes `endTime` only where the end is not the start of the next chapter. For the last chapter, it writes `endTime` when the end is not its own start. It writes version `1.2.0` for chapters from another format.
- **FFmpeg metadata**: the global tags `title`, `author`, `artist`, `album` and `language` become metadata. The `ffmeta-chapters` format data keeps all global tags and the `[STREAM]` sections. Each cue keeps its time base and its other tags. The formatter writes the same time base, or `1/1000` for chapters from another format. Without a `TIMEBASE` line, the parser reads `START` and `END` in nanoseconds, as FFmpeg does.
- **OGM chapters**: a `CHAPTERxx=` line needs 1 to 9 digits after `.` or `,`. A `CHAPTERxxNAME=` line must follow it. Otherwise the parser throws `ParsingException` with the line number.
- **Detection**: format detection does not find chapter lists, because they look like other formats. Pass the format, for example `Subtitle::fromString($json, Format::PodcastChapters)`. `Format::tryFromPath()` finds FFmpeg metadata by its `.ffmeta` extension.
- **Command line tool**: a chapter input needs `--from`, also a `.ffmeta` file. `ogm-chapters`, `podcast-chapters` and `youtube-chapters` share `.txt` and `.json` with plain text and the library JSON. So an output in these formats needs `--to`. An output file that ends in `.ffmeta` gets `ffmeta-chapters`.
- **Lenient mode**: the chapter parsers ignore `ReadOptions::$lenient`.
