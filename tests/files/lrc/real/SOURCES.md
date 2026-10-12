# Sources

| File | Source | License |
|:--- |:--- |:--- |
| justan-1.lrc | Written for this repository in the shape of https://github.com/justan/lrc/blob/6ff0d026206a8cdb69bae537203075b3c452d816/example/lrcs/1.lrc | MIT |
| justan-4.lrc | Written for this repository in the shape of https://github.com/justan/lrc/blob/6ff0d026206a8cdb69bae537203075b3c452d816/example/lrcs/4.lrc | MIT |
| lrc-maker-nami.lrc | Written for this repository in the shape of https://github.com/lrc-maker/lrc-parser/blob/7ceaca1692cd2a03793b22133fed7a696f2a510a/test/sample-nami.lrc | MIT |
| mantas-done-lrc.lrc | Written for this repository in the shape of https://github.com/mantas-done/subtitles/blob/959913d556ef525f68d1dab4e4c918aa07d961dc/tests/files/lrc.lrc | MIT |
| subsrt-sample.lrc | Written for this repository in the shape of https://github.com/papnkukn/subsrt/blob/46deebd95703be9b6a76eb3c1aaf915713f30394/test/fixtures/sample.lrc | MIT |
| handwritten-core.lrc | Written for this repository from the core format and ID tag description at https://en.wikipedia.org/wiki/LRC_(file_format) | MIT |
| handwritten-enhanced.lrc | Written for this repository from the enhanced LRC (A2 extension) description at https://en.wikipedia.org/wiki/LRC_(file_format) | MIT |
| own-comment-before-earlier-line.lrc | Written for this repository. Lines out of time order, and a `[#:...]` comment before a line that starts earlier than the line before it | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/handwritten-core.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `handwritten-core.lrc`: a space after each time tag, one line per time tag in time order | MIT |
| `expected/handwritten-enhanced.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `handwritten-enhanced.lrc`: no space in the ID tags, times shifted by the `[offset:+200]` tag and no `[offset:]` tag | MIT |
| `expected/justan-1.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `justan-1.lrc`: a space after each time tag, no `[offset:0]` tag | MIT |
| `expected/justan-4.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `justan-4.lrc`: 2-digit fractions, one line per time tag in time order | MIT |
| `expected/lrc-maker-nami.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `lrc-maker-nami.lrc`: no space in the ID tags, 2-digit fractions | MIT |
| `expected/mantas-done-lrc.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `mantas-done-lrc.lrc`: a space after each time tag, a final line end | MIT |
| `expected/own-comment-before-earlier-line.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `own-comment-before-earlier-line.lrc`: lines in time order with each comment before its line | MIT |
| `expected/subsrt-sample.lrc.out.lrc` | Generated for this repository. Expected `LyricsFormatter` output for `subsrt-sample.lrc`: `[ti:]` first, a space after each time tag, no empty lines | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
