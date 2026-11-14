# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_sdh.srt` | Written for this repository in the shape of SubRip files with hearing-impaired annotations: UTF-8 BOM, CR LF line endings, sound descriptions in `[ ]`, `( )` and `{ }`, upper and mixed case speaker labels, two-speaker dialogue dashes, music notes and `#` lyrics, italic tags and `&amp;`. | MIT |
| `own_sdh_removed.srt` | Written for this repository. Expected `SubRipFormatter` output with BOM and CR LF for `own_sdh.srt` after `HearingImpairedRemover::apply()`. | MIT |
| `own_sdh_removed_all_options.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_sdh.srt` after `HearingImpairedRemover::apply()` with mixed case labels, `{ }` brackets and lyrics on. | MIT |
| `own_sdh.vtt` | Written for this repository in the shape of WebVTT captions with hearing-impaired annotations: CR LF line endings, `Kind` and `Language` headers, `NOTE` comments, cue settings, `<v>`, `<c.yellow>` and `<i>` tags, dialogue dashes, music notes and an unescaped `&`. | MIT |
| `own_sdh_removed.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_sdh.vtt` after `HearingImpairedRemover::apply()`. | MIT |
