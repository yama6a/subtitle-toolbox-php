# Sources

Google Cloud Speech-to-Text responses for real recordings hold the words of their speakers. So every file here is written for this repository in the exact shape of the REST response. The text is new neutral sample text.

| File | Source | License |
|:--- |:--- |:--- |
| `bus_v1_long_running_operation.json` | Written for this repository in the shape of the V1 operation that `operations.get` returns for `longrunningrecognize` with `enableWordTimeOffsets`: [word time offsets](https://cloud.google.com/speech-to-text/docs/async-time-offsets). Two results, the second transcript with a leading space, times such as `"0.200s"` | MIT |
| `restaurant_v2_diarization.json` | Written for this repository in the shape of the V2 `recognize` response with `diarizationConfig`: [speaker diarization](https://cloud.google.com/speech-to-text/docs/multiple-voices). One result, words with `startOffset`, `endOffset` and `speakerLabel`, no punctuation | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.out.json` | Generated for this repository. Expected output of the file with the same name, read and written as Google Speech-to-Text JSON by `tests/files/generate-write-goldens.php` | MIT |
