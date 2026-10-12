# Sources

AssemblyAI transcripts of real recordings hold the words of their speakers. So every file here is written for this repository in the exact shape of the AssemblyAI transcript resource. The text is new neutral sample text.

| File | Source | License |
|:--- |:--- |:--- |
| `hike_utterances_speakers.json` | Written for this repository in the shape of the [Get transcript](https://www.assemblyai.com/docs/api-reference/transcripts/get) response for `speaker_labels=true`: the fields of the example in the order of the API, `words` and `utterances` with speakers `A` and `B`, times in milliseconds, language `en_us` | MIT |
| `market_words_french.json` | Written for this repository in the shape of the same response without speaker labels, so `"utterances": null`, with language detection. French text, a pause of 1.3 s and a sentence of 100 characters | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.out.json` | Generated for this repository. Expected output of the file with the same name, read and written as AssemblyAI JSON by `tests/files/generate-write-goldens.php` | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
