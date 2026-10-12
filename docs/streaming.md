# Streaming large SRT and WebVTT files

`Subtitle::fromString()` keeps the whole file and one object per cue in memory. An 80 MB SRT file with 400,000 cues does not fit into the default `memory_limit` of 128 MB. The stream readers and writers handle one cue at a time and use less than 1 MB of memory.

```php
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamWriter;

$writer = new WebVttStreamWriter(fopen('show.vtt', 'wb'));          // or a file path
foreach ((new SubRipStreamReader())->read('show.srt') as $cue) {     // or a stream resource
    $writer->write($cue->setStart($cue->getStart() + 2)->setEnd($cue->getEnd() + 2));
}
$writer->close();
```

- **Formats**: only SubRip and WebVTT, because their cue blocks do not depend on each other. Other formats need the whole file, for example for LRC end times or ASS headers.
- **Same result**: with the same options, the readers and writers give the same cues and output bytes as the parsers and formatters. These are `SubRipParser`, `WebVttParser`, `SubRipFormatter` and `WebVttFormatter`.
- **Order**: the readers yield cues in file order. They do not sort the cues by start time, as `Subtitle` does.
- **Errors**: a reader throws `ParsingException` at the first block that the parser rejects. It has yielded the cues before that block. A path that does not open, a write to a closed writer, and a stream that rejects writes throw `InvalidArgumentException`.
- **Lenient mode**: `new SubRipStreamReader(new ReadOptions(lenient: true))` skips or repairs a broken block, see [lenient-parsing.md](lenient-parsing.md). The readers use only `lenient` of `ReadOptions`. During the read, `getWarnings()` of `CueStreamReader` holds the warnings of the blocks read so far.
- **Line endings**: a line ends at LF, CR LF or CR CR LF. A file with only CR line endings is one line for `fgets()`, so it takes memory for the whole file.
- **WebVTT header**: `WebVttStreamReader::getHeader()` returns the header text, the header lines, and the `STYLE` and `REGION` blocks. The array is complete once the reader yields the first cue. Pass this array as the third argument of the `WebVttStreamWriter` constructor, for example `new WebVttStreamWriter($stream, header: $reader->getHeader())`.
- **Dropped colors**: `WebVttStreamWriter` drops text colors that no WebVTT color class has, as `WebVttFormatter` does. `findDroppedColors()` returns a `WebVttDroppedColor` for each color it dropped so far. Call it after the last `write()`, see [formats.md](formats.md).
- **Comments**: `WebVttStreamReader` skips `NOTE` blocks. `WebVttStreamWriter` writes no comments.
- **Encoding**: the readers accept UTF-8, with or without a BOM, and keep the bytes of other 8-bit encodings. For UTF-16, add a filter: `stream_filter_append($in, 'convert.iconv.UTF-16/UTF-8')`.
- **Closing**: `close()` flushes the stream. It closes the stream only when the writer opened it from a file path. A reader also closes only a file that it opened from a path. It never closes a stream resource that you pass, also after an error.
