# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `bakery.stl` | Written for this repository in the shape of https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/files/stl.stl: LF line ends, no final line end, pipe characters as line breaks, an hour of 01 in the second cue | MIT |
| `bakery_with_header.stl` | Written for this repository in the shape of https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/tests/files/stl_with_comments.stl: a `//` comment, `$` formatting lines and a blank line before the cues | MIT |
| `dvdsp_full_header_ntsc.stl` | Written for this repository in the shape of the STL files of DVD Studio Pro 4, as its user manual describes them on pages 456 to 460: CR LF line ends, the full `$` command header with `//` section comments, `^B` and `^I` toggles, and `$Italic` and `$VertAlign` commands between the subtitles. Frame numbers up to 29 mark an NTSC file | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.out.stl` | Generated for this repository. Expected `SpruceStlFormatter` output of the file with the same name: a final line end | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
