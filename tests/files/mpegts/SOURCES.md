# Sources

All files here are written for this repository. A PHP script writes them. `generator/TsFixtures.php` builds the files with `generator/TsFixtureWriter.php`, a small packet writer that follows ISO/IEC 13818-1, ETSI EN 300 472 and ETS 300 706. Run `php tests/files/mpegts/generator/generate.php` to write them again. `MpegTsReaderTest` checks that the files match the generator output.

ffmpeg 7.0.2 with `libzvbi_teletextdec` reads the same text from each page, with `-txt_format ass -txt_page N`. The one difference is page 802: libzvbi takes the Latin set of Western Europe, because the file sends no X/28 or M/29 packet, so it shows `$` and `]` where the reader shows `ń` and `Ł` from the Polish language of the PMT.

| File | Source | License |
|:--- |:--- |:--- |
| `teletext.ts` | Written for this repository. A PAT, a PMT, a video stream that starts 0.5 s before the teletext stream, and a teletext stream with the pages 888 and 777 in German, 801, 802 and 301. Double height rows, boxes, colors, a corrected Hamming error, a page update without the erase flag, a repeated page, parallel magazines, X/28 with the Russian Cyrillic set, M/29 with the Greek set, and the initial page 100 | MIT |
| `one_page.ts` | Written for this repository. A video stream and the English teletext page 888 | MIT |
| `expected/*` | Written by the library from `teletext.ts` | MIT |
