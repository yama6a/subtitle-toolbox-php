# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `astisub_carriage_return.vtt` | Written for this repository in the shape of https://github.com/asticode/go-astisub/blob/721d3fc8258bcc5912423c551da15ad39ebcc553/testdata/example-in-carriage-return.vtt | MIT |
| `astisub_html_entities.vtt` | Written for this repository in the shape of https://github.com/asticode/go-astisub/blob/f285923a0c8d5b2ecce67d8eaf9ed26cce16e5b0/testdata/example-in-html-entities.vtt | MIT |
| `mantas_styles.vtt` | https://github.com/mantas-done/subtitles/blob/e41f318224dc9c38339c2bfcb0199e5bb788abce/tests/files/vtt_with_styles.vtt | mantas-done/subtitles, MIT |
| `own_note_before_earlier_cue.vtt` | Written for this repository. Cues out of time order, and a `NOTE` before a cue that starts earlier than the cue before it | MIT |
| `own_arrow_in_text.vtt` | Written for this repository. The output for a cue with the lines `a --> b`, an empty line and `c`, set through the API | MIT |
| `w3c_chapters.vtt` | https://www.w3.org/TR/webvtt1/, section 1.6, chapters example | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_comments.vtt` | https://www.w3.org/TR/webvtt1/, section 1.5, comments in WebVTT | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_cue_settings.vtt` | https://www.w3.org/TR/webvtt1/, section 1.4, cue settings example | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_identifiers.vtt` | https://www.w3.org/TR/webvtt1/, section 1.4, cue identifier example | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_regions.vtt` | https://www.w3.org/TR/webvtt1/, section 1.4, region example | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_styles.vtt` | https://www.w3.org/TR/webvtt1/, section 1.3, styling captions | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_timestamps.vtt` | https://www.w3.org/TR/webvtt1/, section 8.1, `:past` and `:future` example | W3C Software and Document License, https://www.w3.org/copyright/software-license-2023/ |
| `w3c_voices.vtt` | Written for this repository in the shape of https://www.w3.org/TR/webvtt1/, section 1.1, a simple caption file | MIT |
| `webvttpy_comments.vtt` | Written for this repository in the shape of https://github.com/glut23/webvtt-py/blob/6a92fd3fb428dd2367c9d06e7a406d0aa9c0655b/tests/samples/comments.vtt | MIT |
| `webvttpy_netflix.vtt` | Written for this repository in the shape of https://github.com/glut23/webvtt-py/blob/fdbf129c514328ab1344565174ffb46162c8535d/tests/samples/netflix_chicas_del_cable.vtt, first 30 cues | MIT |
| `webvttpy_youtube.vtt` | Written for this repository in the shape of https://github.com/glut23/webvtt-py/blob/fdbf129c514328ab1344565174ffb46162c8535d/tests/samples/youtube_dl.vtt | MIT |
| `own_empty_cues.vtt` | Written for this repository. Cue 2 has an identifier and no text, as in a user report of a naver.com file. Cue 4 has `align:middle line:90%` settings, no text and two empty lines after it, as in user reports of vendor files. | MIT |
| `own_ytdlp_auto_captions.vtt` | Written for this repository in the shape of YouTube automatic captions that `yt-dlp --write-auto-subs` saves. Each cue holds a line with one space. | MIT |
| `own_ytdlp_rolling.vtt` | Written for this repository in the shape of yt-dlp `--write-auto-subs` output. Rolling cues with `<c>` word timestamps, a 10 ms repeat cue after each, and a line of one word | MIT |
| `own_hour_digits.vtt` | Written for this repository. One cue with 1 hour digit, then the times of https://github.com/captioning/captioning/blob/27d0e86693f4d9bc2046102f74aa54febcd902dc/tests/Fixtures/Webvtt/long-hours.vtt with hours of 2 to 4 digits and leading zeros | MIT |
| `own_settings_without_space.vtt` | Written for this repository in the shape of the vtt.js test file `file-layout/no-space-cue-times-cue-settings.vtt`, https://github.com/hartman/vtt-vivid/tree/1b9a9c1c1072cea68dec1c7e136e32c90578592a/tests/integration/data/file-layout. Cue settings directly after the end time | MIT |
| `own_angle_bracket_text_from_srt.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `srt/real/own_angle_bracket_text.srt`. | MIT |
| `own_max_hours.vtt` | Written for this repository. The last cue ends at 99999:59:59.999, the latest time that the parsers accept | MIT |
| `own_max_word_timestamp.vtt` | Written for this repository. The last cue has a word timestamp at 99999:59:59.000 and ends at 99999:59:59.999 | MIT |
| `own_srt_cue_settings_converted.vtt` | Written for this repository. Expected `WebVttFormatter` output for `srt/real/own_vtt_cue_settings.srt`. | MIT |
| `own_color_classes.vtt` | Written for this repository. The 8 color classes of https://www.w3.org/TR/webvtt1/#default-text-color, a background class and a class without a color | MIT |
| `own_font_colors_from_srt.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `srt/real/own_font_colors.srt`. | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/astisub_carriage_return.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `astisub_carriage_return.vtt`: LF for the CR line ends | MIT |
| `expected/astisub_html_entities.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `astisub_html_entities.vtt`: the no-break space as a character for `&nbsp;` | MIT |
| `expected/mantas_styles.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `mantas_styles.vtt`: the comment after the `STYLE` blocks | MIT |
| `expected/own_empty_cues.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_empty_cues.vtt`: numbers for the cues without an identifier | MIT |
| `expected/own_hour_digits.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_hour_digits.vtt`: cue numbers, hours with at least 2 digits | MIT |
| `expected/own_max_hours.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_max_hours.vtt`: cue numbers | MIT |
| `expected/own_max_word_timestamp.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_max_word_timestamp.vtt`: cue numbers | MIT |
| `expected/own_note_before_earlier_cue.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_note_before_earlier_cue.vtt`: cue numbers, cues in time order with each comment before its cue | MIT |
| `expected/own_settings_without_space.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_settings_without_space.vtt`: cue numbers, hours, a space before the cue settings | MIT |
| `expected/own_ytdlp_auto_captions.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_ytdlp_auto_captions.vtt`: cue numbers, one cue for each rolling line without the 10 ms repeats | MIT |
| `expected/own_ytdlp_rolling.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `own_ytdlp_rolling.vtt`: cue numbers, one cue for each rolling line without the 10 ms repeats | MIT |
| `expected/w3c_chapters.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_chapters.vtt`: the comment on the `NOTE` line | MIT |
| `expected/w3c_comments.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_comments.vtt`: cue numbers, hours | MIT |
| `expected/w3c_cue_settings.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_cue_settings.vtt`: cue numbers | MIT |
| `expected/w3c_identifiers.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_identifiers.vtt`: hours | MIT |
| `expected/w3c_regions.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_regions.vtt`: cue numbers | MIT |
| `expected/w3c_styles.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_styles.vtt`: the comments after the `STYLE` blocks | MIT |
| `expected/w3c_timestamps.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_timestamps.vtt`: cue numbers | MIT |
| `expected/w3c_voices.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `w3c_voices.vtt`: cue numbers, hours | MIT |
| `expected/webvttpy_comments.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `webvttpy_comments.vtt`: no spaces at the line ends, a final line end | MIT |
| `expected/webvttpy_netflix.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `webvttpy_netflix.vtt`: comments of several lines start after `NOTE`, single spaces between the cue settings, no lines of only spaces | MIT |
| `expected/webvttpy_youtube.vtt.out.vtt` | Generated for this repository. Expected `WebVttFormatter` output for `webvttpy_youtube.vtt`: cue numbers, no space before the closing tag on its own line | MIT |
