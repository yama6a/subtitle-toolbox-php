# Sources

[`../generate.php`](../generate.php) writes the synthetic files from the byte pairs of the SCC fixtures in [`../../scc/real/`](../../scc/real/). Run `php tests/files/mcc/generate.php` after a change to it. The ffmpeg files come from the ffmpeg 8.1.3 static build of BtbN/FFmpeg-Builds, whose MCC muxer writes one line per SCC line. The text is written for this repository.

| File | Source | License |
|:--- |:--- |:--- |
| `captionmaker_popon_df.mcc` | Written for this repository in the shape of a CaptionMaker or MacCaption export: version 2.0, `Time Code Rate=30DF`, one line per frame, 20 cc_data triplets per packet with the field 1 pair, a null pair for field 2 and 18 empty DTVCC triplets, and a service info section for CC1 and CEA-708 service 1. The byte pairs come from `captionmaker_popon_df.scc` | MIT |
| `v1_23976_timecode.mcc` | Written for this repository from the MCC header text: version 1.0, `Time Code Rate=24` with the packet frame rate code of 23.976 fps, 25 triplets per packet, 1 or 2 field 1 pairs per frame, a time code section in each packet, CR LF line endings. The byte pairs come from `premiere_popon_df.scc` | MIT |
| `v2_5994df_fields.mcc` | Written for this repository from the MCC header text: version 2.0, `Time Code Rate=60DF`, field 1 pairs on even frames and field 2 pairs on odd frames, and a second packet with the time code suffix `.1` at the end of each second. The byte pairs come from `rollup_news_ndf.scc` | MIT |
| `ffmpeg_rollup.mcc` | Muxed with `ffmpeg -i live_rollup_ru2.scc -c:s copy -override_time_code_rate 30000/1001 -fflags +bitexact -creation_time 2026-10-13T12:00:00`: version 2.0, one packet per SCC line with all its pairs | MIT |
| `ffmpeg_painton_v1.mcc` | Muxed with `ffmpeg -i painton_corrections.scc -c:s copy -override_time_code_rate 30 -mcc_version 1 -fflags +bitexact -creation_time 2026-10-13T12:00:00`: version 1.0 at 30 fps | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.out.*` | Generated for this repository. Expected `MccFormatter` output for the file with the same name, written by `tests/files/generate-write-goldens.php`. The formatter writes pop-on captions as `SccFormatter` does, so the load of each caption moves | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
