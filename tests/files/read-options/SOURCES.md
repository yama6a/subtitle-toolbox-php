# Sources

The JSON files hold the cues that 1.x read from a real file, as `[start, end, lines]` rows. `ReadOptionsTest` checks that 2.0 reads the same cues with the matching `ReadOptions`.

| File | Source | License |
|:--- |:--- |:--- |
| `last_caption_without_erase.scc` | Written for this repository. The first two captions of `../scc/real/popon_broadcast_df.scc`, without the erase command after the second | MIT |
| `microdvd_subsrt_sample.json` | Cues of `../microdvd/real/subsrt_sample.sub`, read by `new MicroDvdParser(23.976)` | MIT |
| `french_windows_1252.json` | Cues of `../encoding/french-windows-1252.srt`, read with the source encoding `Windows-1252` | MIT |
| `whisper_word_timestamps.json` | Cues of `../whisper/real/openai_whisper_word_timestamps.json`, read with `TranscriptReadOptions::$wordTimestamps` | MIT |
| `whisperx_speaker_voices.json` | Cues of `../whisper/real/whisperx_diarize.json`, read with `TranscriptReadOptions::$speakerVoices` | MIT |
| `sami_multi_language_frcc.json` | Cues of `../sami/real/multi_language.smi`, read by `new SamiParser("FRCC")` | MIT |
| `csv_excel_de_semicolon.json` | Cues of `../csv/real/excel_de_semicolon.csv`, read by `new CsvParser(delimiter: ";")` | MIT |
| `scc_rollup_news_ndf.json` | Cues of `../scc/real/rollup_news_ndf.scc`, read by `new SccParser(1)` | MIT |
