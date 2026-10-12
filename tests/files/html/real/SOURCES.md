# Sources

The example files of the spec and of podcast-transcript-convert hold real podcast dialogue. So every file here is written for this repository in the shape of a real file. The text is new neutral sample text.

| File | Source | License |
|:--- |:--- |:--- |
| `spec_example_shape.html` | Written for this repository in the shape of the spec example [`example.html`](https://github.com/Podcastindex-org/podcast-namespace/blob/c0ff5caa3729610362ee93f8034454fa41f3c493/docs/examples/transcripts/example.html): `<cite>`, `<time>` and `<p>` per paragraph, times such as `1:02`, and `&#39;`. One paragraph spans several indented lines and one name has a space before the colon, as in the snippet of [`transcripts.md`](https://github.com/Podcastindex-org/podcast-namespace/blob/c0ff5caa3729610362ee93f8034454fa41f3c493/docs/examples/transcripts/transcripts.md) | MIT |
| `hhmmss_empty_paragraphs.html` | Written for this repository in the shape of the HTML test fixtures of [podcast-transcript-convert](https://github.com/hbmartin/podcast-transcript-convert/tree/8271304fe248b3b15902adfc953542edf93aa874/tests/fixtures): times as `00:00:00`, a `Speaker 1:` name on each paragraph, and an empty `<p></p>` after each paragraph | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/hhmmss_empty_paragraphs.html.out.html` | Generated for this repository. Expected `HtmlTranscriptFormatter` output for `hhmmss_empty_paragraphs.html`: short times such as `0:08`, one paragraph for cues of the same speaker without a gap, no empty paragraphs | MIT |
| `expected/spec_example_shape.html.out.html` | Generated for this repository. Expected `HtmlTranscriptFormatter` output for `spec_example_shape.html`: apostrophes as characters, each paragraph on one line, no space before the colon in `<cite>` | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |
