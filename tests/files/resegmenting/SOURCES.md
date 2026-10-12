# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_whisper_long_segments.json` | Written for this repository in the shape of openai-whisper `--output_format json --word_timestamps True`, as described in `tests/files/whisper/real/SOURCES.md`. A first segment of 11.8 s with three sentences, a sentence across two segments, and a pause of 2 s. | MIT |
| `own_whisper_long_segments_split.vtt` | Written for this repository. Expected `WebVttFormatter` output for `own_whisper_long_segments.json` after `Resegmenter::apply()` with `ResegmentMode::SplitLong` with the default options. | MIT |
| `own_whisper_long_segments_resegmented.srt` | Written for this repository. Expected `SubRipFormatter` output for `own_whisper_long_segments.json` after `Resegmenter::apply()` with `ResegmentMode::ByWords` with the default options. | MIT |
| `own_whisper_long_segments_resegmented_30x1.srt` | Generated for this repository. Output of `subtitle-toolbox convert own_whisper_long_segments.json --structure-resegment --structure-max-cpl 30 --structure-max-lines 1 --structure-max-word-gap 00:00:00.3 --to srt`, checked by hand | MIT |
| `own_whisper_long_segments_words.vtt` | Generated for this repository. Output of `subtitle-toolbox convert own_whisper_long_segments.json --to vtt --word-timestamps`, checked by hand | MIT |
