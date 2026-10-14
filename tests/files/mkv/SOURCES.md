# Sources

All files here are written for this repository. No permissively licensed MKV file with neutral subtitle text was found.

`muxed/make.sh` muxes the subtitle files in `muxed/` with mkvmerge v99.0 and ffmpeg 7.0.2 (static build of johnvansickle.com) into `mkvmerge.mkv`, `ffmpeg_srt.mkv`, `ffmpeg_webvtt.webm` and `../mp4/ffmpeg_mov_text.mp4`. The video is a gray 64x36 picture at 4 frames per second for 20 s. ffmpeg writes a new Segment UID and Track UIDs on each run, so a new run changes some bytes. The read goldens in `expected/` hold each extracted track in its own format, `<file>.<track>.<ext>`. The CLI goldens hold the output of `convert <file> --track <track> --to <ext> -o -`, `<file>.<track>.to.<ext>`.

The other files come from a PHP script. `generator/MkvFixtures.php` builds the files with `generator/MkvFixtureWriter.php`, a small EBML writer. Run `php tests/files/mkv/generator/generate.php` to write them again. `MatroskaReaderTest` checks that the files match the generator output. ffmpeg 7.0 opens all files and lists their tracks.

| File | Source | License |
|:--- |:--- |:--- |
| `text_tracks.mkv` | Written for this repository. A video track, a laced audio track and six subtitle tracks: S_TEXT/UTF8 with `LanguageBCP47` and the forced flag, S_TEXT/ASS with the default flag and ReadOrder out of time order, S_TEXT/WEBVTT with cue settings, an identifier and a comment in `BlockAdditions`, S_TEXT/SSA without an `[Events]` section in `CodecPrivate`, S_DVBSUB, and S_TEXT/UTF8 in SimpleBlocks without duration and language. A block with a negative relative timestamp, a Void element, Cues and Tags | MIT |
| `compressed.mkv` | Written for this repository. S_TEXT/UTF8 and S_TEXT/ASS tracks with zlib compression of the frames and of `CodecPrivate`, header stripping, zlib after header stripping, and bzlib | MIT |
| `unknown_sizes.mkv` | Written for this repository. A live recording: the Segment and the Clusters have an unknown size. `TimestampScale` is 100000, so a tick is 0.1 ms | MIT |
| `seek_head.mkv` | Written for this repository. WebM doc type. `Info` and `Tracks` follow the clusters, and the `SeekHead` points to them. S_TEXT/WEBVTT without `CodecPrivate` | MIT |
| `pgs.mkv` | Written for this repository. Two S_HDMV/PGS tracks with the segments of [../pgs/shapes_1080p.sup](../pgs/SOURCES.md): one segment per SimpleBlock, and one display set per BlockGroup with the forced flag | MIT |
| `vobsub.mkv` | Written for this repository. Two S_VOBSUB tracks with the `en` units of [../vobsub/two-tracks-pal.sub](../vobsub/SOURCES.md). Track 3 has the whole `.idx` header in `CodecPrivate` and SimpleBlocks. Track 4 has only the `size` and `palette` lines, zlib compression and BlockGroups of 1500 ms | MIT |
| `mkvmerge.mkv` | Muxed with `mkvmerge --deterministic 756`. An H.264 track, `muxed/harbour.srt` as S_TEXT/UTF8 with the name `English`, and `muxed/harbour.ass` as S_TEXT/ASS without the default flag. mkvmerge writes a SeekHead, a Void, Info, Tracks, clusters of 2 s, Cues for every subtitle block and statistics Tags at the end | MIT |
| `ffmpeg_srt.mkv` | Muxed with `ffmpeg -c:s srt`. An H.264 track and `muxed/harbour.srt` as S_TEXT/UTF8 in BlockGroups with BlockDuration. ffmpeg writes clusters of about 5 s and Cues for every subtitle block | MIT |
| `ffmpeg_webvtt.webm` | Muxed with `ffmpeg -c copy`. WebM doc type, a VP9 track and `muxed/harbour.vtt` as D_WEBVTT/SUBTITLES. Each block holds the cue identifier, the cue settings and the cue text. One cue has an identifier and settings, one has neither, and one has settings only | MIT |
| `muxed/harbour.srt`, `muxed/harbour.ass`, `muxed/harbour.vtt` | Written for this repository. The input of `muxed/make.sh` | MIT |
| `expected/*` | Written by the library from the files above, and checked against the `muxed/` input | MIT |
| `dvbsub.mkv` | Remuxed with `mkvmerge --deterministic 1029` from [../mpegts/dvb_subtitles.ts](../mpegts/SOURCES.md) by `../mpegts/muxed/make.sh`. The H.264 track and two S_DVBSUB tracks with zlib compression and the composition and ancillary page in `CodecPrivate`. `expected/dvbsub.mkv.*` holds the pixel goldens | MIT |
| `huge_timestamp.mkv` | Written for this repository. A copy of `text_tracks.mkv` with byte 1560 changed from `0xA3` (SimpleBlock) to `0xE7` (Timestamp). The cluster `Timestamp` then has 68 bytes of data, more than an unsigned integer may have | MIT |
