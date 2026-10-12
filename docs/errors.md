# Errors

Every exception of the library implements `SubtitleToolbox\Exceptions\SubtitleToolboxException`. One `catch` block handles all of them, and lets errors from other code pass.

```php
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

try {
    $vtt = Subtitle::fromString($upload, Format::SubRip)->toString(Format::WebVtt);
} catch (SubtitleToolboxException $e) {
    return response($e->getMessage(), 422);
}
```

| Exception | Extends | `getCode()` | Thrown for |
|:--- |:--- |:--- |:--- |
| `ParsingException` | `\RuntimeException` | 100 | content that a parser or `fromArray()` cannot read, or a byte that is not valid in the source encoding |
| `UnsupportedFormatException` | `\RuntimeException` | 102 | a format that the library cannot read or write, see [Unsupported formats](#unsupported-formats) |
| `InvalidArgumentException` | `\InvalidArgumentException` | 104 | an invalid argument or option, see [Invalid arguments](#invalid-arguments) |
| `CueNotFoundException` | `\RuntimeException` | 105 | `removeCue()`, `splitCue()` or `joinCues()` with an index that has no cue |
| `UnknownFormatException` | `UnsupportedFormatException` | 106 | `load()` or `fromString()` without a format when detection finds no format |
| `OcrException` | `\RuntimeException` | 107 | an OCR engine that fails on one image. Tesseract exits with an error, or php-glyph-ocr cannot read the image |
| `UnwritableContentException` | `\RuntimeException` | 108 | `toString()` or `save()` with a subtitle that the output format cannot hold, see [Unwritable content](#unwritable-content) |
| `TranslationException` | `\RuntimeException` | 109 | a translation service that fails, see [Translation errors](#translation-errors) |

- **SPL classes**: each class extends an SPL class, so `catch (\InvalidArgumentException $e)` and `catch (\RuntimeException $e)` also work.
- **Messages**: `InvalidArgumentException`, `UnwritableContentException` and `CueNotFoundException` keep the plain message. The other classes start it with the class name and the code, for example `ParsingException (Error #100): `. See [compatibility.md](compatibility.md) for the parts of an exception that stay stable.
- **Previous exception**: every constructor takes the message, then an optional `$previous` exception. `ParsingException` takes the line number before it. `getPrevious()` returns it.
- **Final classes**: every exception class is `final`, except `UnsupportedFormatException`. `UnknownFormatException` extends it. Do not extend it, see [compatibility.md](compatibility.md#not-covered).
- **Line number**: `ParsingException::getLineNumber()` returns the 1-based input line when the parser knows it, and null otherwise. The message then ends with the line, for example ` (line 12)`. Read the line from `getLineNumber()`, not from the message.

[Lenient mode](lenient-parsing.md) skips a broken block in place of throwing.

## Unsupported formats
`UnsupportedFormatException` covers these cases:

- `fromString()` or `load()` with a format that the library cannot read, such as `Format::PlainText`.
- `toString()` or `save()` with a format that the library cannot write, such as `Format::VobSub`.
- `save()` without a format and with an unknown extension, such as `out.unknown`.
- An MKV, WebM or MP4 file in `load()` or `fromString()` with a format. Call `loadTrack()` instead.
- An MKV, WebM or MP4 file without exactly 1 subtitle track in `load()` or `fromString()` without a format.
- Detection that finds no format. This throws the subclass `UnknownFormatException`.

## Invalid arguments
`InvalidArgumentException` covers these cases:

- An invalid value, for example frame rate 0 or NAN in a number option.
- A retiming or `append()` that moves a cue time to 100000 hours or more, for example `scale(1e300)`.
- A missing MicroDVD output frame rate.
- 2 objects of one class in `ReadOptions::$formats` or `WriteOptions::$formats`, or a write class in `ReadOptions` and a read class in `WriteOptions`.
- A missing OCR engine, a missing `tesseract` program, a missing Tesseract language or a missing php-glyph-ocr package.
- A missing `ext-curl` for the translation engines.

## Unwritable content
`UnwritableContentException` covers these cases:

- More than 4 lines or 32 characters per line in SCC.
- A character that CEA-608 cannot show in SCC, for example `日`.
- A cue without an image in PGS.
- An image cue without text in `toString()` or `save()` with a text format, unless `WriteOptions::$skipImageCues` is set. Run OCR first or write a bitmap format.
- An image position or size above 65535 in PGS.
- A PNG in PGS output whose size differs from the size in the image data of its cue, for example a 2x2 PNG for a 720x80 image.
- A cue time after 13:15:21.858 in PGS, the limit of its 32-bit time stamps of 90 kHz.
- A subtitle number over 65535 in EBU STL.
- Cue text that needs more than 241 TTI blocks of 112 bytes in EBU STL.
- Text that is not UTF-8 in a JSON format.
- Text that is not UTF-8 in TTML, iTT or SAMI output.
- A stored TTML head that is not valid XML.
- An `AssWriteOptions::$style` field that the `Format:` line of the styles lacks.
- A cue without words in Google Speech-to-Text output with speaker diarization.

## Translation errors
`TranslationException` covers these cases:

- An HTTP error, such as 403 for a wrong key or 456 for a used-up DeepL quota.
- A request without a response.
- An answer that the engine cannot read.

## Line numbers
These readers set the line number:

| Reader | Line |
|:--- |:--- |
| ASS and SSA, MicroDVD, MPSub, SubViewer, MPL2, TMPlayer, SCC | the line of the error |
| SubRip, SBV and WebVTT, also the stream readers | the line of the error. A message about a missing cue number or timing line quotes the line, cut to 60 characters |
| SAMI | the line of the SYNC tag |
| CSV and TSV | the line where the row starts, or where an unclosed quote opens |
| YouTube XML formats, such as srv3 and transcript XML | the line of the XML element |
| HTML transcripts | the line of the paragraph or time |
| FFmpeg metadata and OGM chapters | the line of the error |
| `OcrReplaceList::fromSubtitleEditXml()` | the line of the XML error |
| `ShotChanges::fromText()`, `SpeechReference::fromFfmpegSilencedetect()` | the line of the error |

