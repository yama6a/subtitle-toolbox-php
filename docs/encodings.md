# Encodings and line endings

The library works in UTF-8. The `load` and `fromString` functions of `Subtitle` convert other encodings on input.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\TextEncoding;

$subtitle = Subtitle::fromString(file_get_contents('movie.srt'), Format::SubRip, new ReadOptions(encoding: TextEncoding::Windows1252));
$subtitle = Subtitle::fromString(file_get_contents('movie.smi'), options: new ReadOptions(encoding: TextEncoding::Cp949));
$subtitle = Subtitle::fromString(file_get_contents('ukrainian.srt'), Format::SubRip, new ReadOptions(encoding: 'CP1125'));
StringHelpers::isValidUtf8(file_get_contents('movie.srt'));   // false for a Windows-1252 file with letters such as é
```

## Order of the checks

`load()` and `fromString()` take the first rule that applies:

| Step | Content | Read as |
|:--- |:--- |:--- |
| 1 | Starts with a BOM | The encoding of the BOM: UTF-8, UTF-16 or UTF-32. It wins over `ReadOptions::$encoding` |
| 2 | UTF-16 without a BOM | UTF-16LE or UTF-16BE, unless `ReadOptions::$encoding` names UTF-16 or UTF-32 |
| 3 | Valid UTF-8 without a zero byte | UTF-8 |
| 4 | `ReadOptions::$encoding` is set | That encoding. Detection does not run |
| 5 | A code page fits | The code page that detection picks, for example `Windows-1252` |
| 6 | Anything else | UTF-8. The parsers keep the invalid bytes |

- **Repeated BOMs**: joined files can start with 2 or more UTF-8 BOMs. Detection and the parsers drop all of them. The SubRip, WebVTT and SBV parsers and the stream readers also drop a UTF-8 BOM at the start of a line, where a joined file starts.
- **Mixed folders**: valid UTF-8 wins over `ReadOptions::$encoding`. So `stbox convert season1/ --encoding Windows-1256` reads both the UTF-8 and the Windows-1256 files of the folder correctly.
- **UTF-16 without a BOM**: in ASCII text, every second byte is zero. The check reads the first 1024 bytes. At least 40 % of the byte pairs must have a zero byte on one side, and at most 5 % on the other side. A UTF-8 or Windows-1252 file with a stray zero byte does not pass.
- **UTF-32 without a BOM**: such content holds zero bytes on both sides of each byte pair. It is converted only from `ReadOptions::$encoding`, for example `UTF-32LE`.
- **Code page detection**: the detector tries Windows-1250 to Windows-1258, Windows-874, ISO-8859-1, -2, -5, -6, -7 and -9, KOI8-R and KOI8-U, and the CJK code pages GBK, CP950, CP932, EUC-JP and CP949. It decodes the lines with bytes from 0x80 in each code page. A code page that cannot decode a byte drops out. Letters of the languages that use the code page score. Other letters, symbols inside words, mixed scripts, odd letter case and Thai vowel or tone marks without a consonant cost. The code page with the best score per character wins. On a tie, the Windows code page wins, so ISO-8859-1 text reads as Windows-1252, which decodes it the same. Thai TIS-620 text reads as Windows-874, which decodes it the same.
- **CJK detection**: the 2,350 common Korean syllables of EUC-KR score for CP949, which also decodes EUC-KR. Other Korean syllables neither score nor cost. Hiragana and Katakana score for CP932 and EUC-JP. About 700 common Han characters score for GBK (Simplified Chinese), CP950 (Traditional Chinese) and the Japanese code pages. Other Han characters neither score nor cost. For example, the GBK bytes of `谢谢，我得回家了。` read as CP950 give `郅郅ㄛ扂腕隙模賸﹝`, whose characters are not on the list.
- **Shift_JIS and Big5**: detection names CP932 and CP950, the Windows forms of these encodings. They decode every Shift_JIS and Big5 character.
- **Shift_JIS as CP932**: `TextEncoding::ShiftJis` and the names `Shift_JIS`, `SJIS`, `MS_KANJI` and `csShiftJIS` convert as CP932. So the bytes 0x5C and 0x7E stay `\` and `~`, and the ASS tag `\N` stays intact. iconv itself reads them as `¥` and `‾` in `Shift_JIS`. `findSourceEncoding()` still returns the name that `ReadOptions::$encoding` holds, for example `Shift_JIS`.
- **Limits of detection**: content with a zero byte is never detected. Content with more valid UTF-8 sequences of 2 or more bytes than invalid bytes stays UTF-8, because it is UTF-8 with a few broken bytes. A CJK file with a byte that its code page cannot decode, such as the first byte of a cut character, gets no code page when that code page fits its other lines best. It stays UTF-8 with its bytes, as in step 6, so it does not read as Thai or Arabic garbage. Short text can get a code page that decodes it differently, for example Latvian Windows-1257 text as Windows-1252. Pass `ReadOptions::$encoding` or `--encoding` then.
- **Warnings**: in lenient mode, step 2 adds a `ParseWarning` with the action `Repaired`, such as "The content is UTF-16LE without a BOM." Step 5 adds "The content is not UTF-8. Detection picked Windows-1252. Pass --encoding if that is wrong."
- **Encoding of a subtitle**: `Subtitle::findSourceEncoding()` returns the encoding of the steps above, for example `UTF-8`, `UTF-16LE`, `Windows-1252` or the value of `ReadOptions::$encoding`. It returns null for binary formats, MKV, WebM, MP4 and MPEG-TS tracks and a subtitle that no parser read. The CLI `info` prints it as `Encoding`.
- **Binary formats**: Cavena 890, EBU STL, PAC, PGS and VobSub keep their bytes and skip all steps. The PAC parser reads `ReadOptions::$encoding` as the code page of the file, see [formats.md](formats.md#pac).
- **XML declaration**: the TTML, iTT and YouTube parsers ignore `encoding="utf-16"` or `"utf-32"` in the XML declaration of UTF-8 content. This covers converted UTF-16 files and UTF-8 files that declare UTF-16.
- **Legacy text that looks like UTF-8**: a few legacy files are valid UTF-8 by chance. For example, the Windows-1252 text `Ã©` is the bytes `C3 A9`, which are `é` in UTF-8. The library reads such a file as UTF-8.
- **Invalid UTF-8**: after step 6, a parser that gets content with a byte that is not valid UTF-8 acts as below. In lenient mode, a `ParseWarning` with the action `Repaired` names the first bad byte, for example "The content is not valid UTF-8. The first bad byte is at offset 42. Set ReadOptions::$encoding to the source encoding." `SubRipStreamReader` and `WebVttStreamReader` give the same warning with the same offset. The CLI names `--encoding` in place of `ReadOptions::$encoding`.

| Format | Strict mode | Lenient mode |
|:--- |:--- |:--- |
| SubRip, WebVTT, SBV, ASS, SubViewer, CSV and the other text formats | keeps the bytes in the text | keeps the bytes and warns |
| SAMI, TTML, iTT and YouTube srv1, srv2 and srv3 | throws `ParsingException` with the message of the warning | reads each bad byte as U+FFFD and warns |

- **Output of invalid UTF-8**: the JSON, TTML, iTT and SAMI formatters throw `UnwritableContentException` for text that is not valid UTF-8.
- **JSON formats**: the JSON parsers, YouTube json3 among them, read each invalid UTF-8 byte as U+FFFD, the replacement character. For example, the bytes `42 FF 64` in a text field give `B`, U+FFFD and `d`.
- **`StringHelpers::convertToUtf8()`**: it takes steps 1, 3, 4 and 6 only. It does not detect UTF-16 without a BOM or a code page. For example, `convertToUtf8("Caf\xE9")` returns the bytes unchanged.
- **Parsers called directly**: only the `Subtitle` functions convert. Before `(new SamiParser())->parse($content, new ReadOptions())`, call `StringHelpers::convertToUtf8($content, TextEncoding::Cp949)`.
- **Source encodings**: `ReadOptions::$encoding` and `StringHelpers::convertToUtf8()` take a `TextEncoding` case or a string. The conversion uses the PHP extension iconv. A string can be any name that the iconv of the system knows, for example `CP1125`. `new ReadOptions()` throws `InvalidArgumentException` for an unknown name. A byte that is invalid in the encoding throws `ParsingException`.
- **Stored value**: `ReadOptions::$encoding` holds the iconv name as a string. `new ReadOptions(encoding: TextEncoding::Windows1252)` stores `Windows-1252`.
- **EBU STL**: the binary file holds its own character tables. The parser ignores `ReadOptions::$encoding`.
- **Output**: line endings and the UTF-8 BOM of the output are formatter options, see [formats.md](formats.md#write-options).

## TextEncoding cases

Each case value is the iconv name.

| Cases | Values |
|:--- |:--- |
| `Utf8`, `Utf16Le`, `Utf16Be` | `UTF-8`, `UTF-16LE`, `UTF-16BE` |
| `Windows1250` to `Windows1258` | `Windows-1250` to `Windows-1258` |
| `Iso8859_1` to `Iso8859_16`, without `Iso8859_12` | `ISO-8859-1` to `ISO-8859-16`, without `ISO-8859-12` |
| `Koi8R`, `Koi8U` | `KOI8-R`, `KOI8-U` |
| `ShiftJis`, `EucJp` | `Shift_JIS`, `EUC-JP` |
| `Gbk`, `Gb18030`, `Big5` | `GBK`, `GB18030`, `Big5` |
| `EucKr`, `Cp949` | `EUC-KR`, `CP949` |
| `Cp437`, `Cp850`, `Cp866` | `CP437`, `CP850`, `CP866` |

ISO-8859-12 has no case because no such standard exists and iconv rejects the name.
