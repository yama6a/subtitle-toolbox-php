# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_whisper_long_segments.json` | Written for this repository in the shape of openai-whisper `--output_format json --word_timestamps True`, as described in `tests/files/whisper/real/SOURCES.md`. A first segment of 11.8 s with three sentences, a sentence across two segments, and a pause of 2 s. | MIT |
| `own_whisper_long_segments_split.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_whisper_long_segments.json` after `Resegmenter::apply()` with `ResegmentMode::SplitLong` with the default options. | MIT |
| `own_whisper_long_segments_resegmented.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_whisper_long_segments.json` after `Resegmenter::apply()` with `ResegmentMode::ByWords` with the default options. | MIT |
