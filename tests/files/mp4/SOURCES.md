# Sources

All files here are written for this repository. `ffmpeg_mov_text.mp4` comes from ffmpeg 7.0.2, see [../mkv/SOURCES.md](../mkv/SOURCES.md). The read golden `expected/ffmpeg_mov_text.mp4.2.srt` holds the extracted track, and the CLI golden `expected/ffmpeg_mov_text.mp4.2.to.srt` the output of `convert ffmpeg_mov_text.mp4 --track 2 --to srt -o -`.

A PHP script writes the other files. `generator/Mp4Fixtures.php` builds the files with `generator/Mp4FixtureWriter.php`, a small box writer that follows ISO/IEC 14496-12 and 3GPP TS 26.245. Run `php tests/files/mp4/generator/generate.php` to write them again. `Mp4ReaderTest` checks that the files match the generator output.

| File | Source | License |
|:--- |:--- |:--- |
| `text_tracks.mp4` | Written for this repository. The moov box follows the mdat box. A video track and four subtitle tracks: `tx3g` in English with a name, gap samples, a `styl` box, UTF-16 text and CR LF, 2 samples per chunk. `tx3g` with `elng` `fr-CA`, the forced display flag, version 1 `tkhd` and `mdhd`, `co64` and `stz2`. A `c608` track, and an `enct` track with a `sinf` box | MIT |
| `one_track.mp4` | Written for this repository. The moov box comes first. A video track and one `tx3g` track | MIT |
| `ffmpeg_mov_text.mp4` | Muxed with `ffmpeg -c:s mov_text` from `../mkv/muxed/harbour.srt`. The moov box follows the mdat box. An H.264 track and a `tx3g` track with the handler `sbtl`, a font table with Arial, a `styl` box for italics and an empty sample for each gap | MIT |
| `expected/*` | Written by the library from `ffmpeg_mov_text.mp4` | MIT |
| `huge_sample_count.mp4` | Written for this repository. A copy of `one_track.mp4` with 3 changed fields in track 2: the `stsz` sample size is 1 and its sample count is 4294967295, and the first `stts` and `stsc` entries count 4294967295 samples. `Mp4Reader` must reject it at once | MIT |
