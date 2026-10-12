# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `ffmpeg_mp4_export.ffmeta` | Written for this repository in the shape of `ffmpeg -i in.mp4 -f ffmetadata` output: MP4 brand tags, `TIMEBASE=1/1000`, an escaped `;` | MIT |
| `m4b_audiobook.ffmeta` | Written for this repository in the shape of an M4B audiobook export: a `[STREAM]` section, `TIMEBASE=1/44100`, a value continued on the next line, escaped `=` | MIT |
| `hand_written_crlf.ffmeta` | Written for this repository in the shape of a hand-made file: CR LF, comments, empty lines, no `TIMEBASE`, missing `END` lines | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
