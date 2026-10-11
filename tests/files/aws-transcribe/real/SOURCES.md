# Sources

Amazon Transcribe output of real recordings holds the words of their speakers. So every file here is written for this repository in the exact shape of an Amazon Transcribe batch transcript. The text is new neutral sample text.

| File | Source | License |
|:--- |:--- |:--- |
| `library_speakers_language_id.json` | Written for this repository in the shape of a batch transcript with speaker partitioning and language identification: [example output](https://docs.aws.amazon.com/transcribe/latest/dg/how-input.html#how-it-works-output), [diarization output](https://docs.aws.amazon.com/transcribe/latest/dg/diarization-output-batch.html) and [language identification](https://docs.aws.amazon.com/transcribe/latest/dg/lang-id-batch.html). `audio_segments`, `speaker_labels`, punctuation items with `speaker_label`, times and confidences as strings | MIT |
| `weather_items_only.json` | Written for this repository in the shape of the [example output](https://docs.aws.amazon.com/transcribe/latest/dg/how-input.html#how-it-works-output) without `audio_segments`, as older transcripts have it. A pause of 1.24 s inside a sentence, and a sentence of 103 characters | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.out.json` | Generated for this repository. Expected output of the file with the same name, read and written as Amazon Transcribe JSON by `tests/files/generate-write-goldens.php` | MIT |
