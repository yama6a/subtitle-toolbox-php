# Transcripts

Speech-to-text tools, YouTube and podcast apps use their own transcript formats. The parsers on this page turn them into cues, so you can write SubRip or WebVTT. The library writes Whisper JSON, the cloud speech-to-text JSON formats, the podcast transcript formats and plain text. YouTube timed text is read only.

## Edit and save a transcript
Read a transcript, fix the cues, and write it in its own format. The writer keeps the fields of the source, so other tools still read the file.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::load('lecture.json', Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
$subtitle->replaceText('Hamburg', 'Hannover');
$subtitle->save('lecture.fixed.json', Format::Whisper);
```

- **Round trip**: a file read and written without edits gives the same JSON data. The output is compact JSON with UTF-8 characters, so white space and `\u` escapes can differ from the source.
- **Unedited values**: a time, a text or a word that the cue did not change keeps its source form, for example the time `2.8399999141693115`.
- **Edited cues**: the writer takes the times, the text and the speaker from the cue. It builds the words of the cue again, so the file never contradicts itself.
- **Words**: with `TranscriptReadOptions::$wordTimestamps`, the words come from the word timestamps of the cue. A word with the text and the start of a source word keeps all its fields, such as its confidence. A new or changed word gets confidence 1.0, because a person checked it. Without word timestamps, a cue with unchanged times keeps its source words. When its text keeps the number of words, a changed word keeps the times of its source word and gets confidence 1.0. Else the cue has no words. So read with word timestamps before you change times or the number of words.
- **Other sources**: cues from another format, for example SubRip, give the smallest document of the format. A cue with word timestamps gets words. A cue with a `<v>` speaker gets the speaker field of the format.

## Whisper JSON
A speech-to-text tool based on OpenAI Whisper writes a JSON transcript.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($openAiResponseBody, Format::Whisper);
$subtitle = Subtitle::fromString(file_get_contents('lecture.json'), Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
$subtitle->getCues()[0]->getText();                                          // '<00:00:00.000>The <00:00:00.240>beach <00:00:00.710>was <00:00:00.950>quiet.'
$subtitle->getCues()[0]->findFormatData('whisper')['avg_logprob'];           // -0.25
```

| Tool | Shape | Source |
|:--- |:--- |:--- |
| OpenAI API | `response_format=verbose_json`. `segments`, and `words` at the top level with `timestamp_granularities[]=word` | [API reference](https://platform.openai.com/docs/api-reference/audio/createTranscription) |
| openai-whisper | `--output_format json`. `segments`, with `words` per segment with `--word_timestamps True` | [`transcribe.py`](https://github.com/openai/whisper/blob/86098128c0b4f24f0e2aa2994de830614b474227/whisper/transcribe.py) |
| faster-whisper, whisper-ctranslate2 | `segments` with the fields of the `Segment` class | [`transcribe.py`](https://github.com/SYSTRAN/faster-whisper/blob/7b99be5376b41cd481dfc52e8caa00a497c3294e/faster_whisper/transcribe.py) |
| WhisperX | `segments` with `words` that have `score` and, after diarization, `speaker` | [`alignment.py`](https://github.com/m-bain/whisperX/blob/771b4a14a9486f8fd5aef18ef49e35d639523dd3/whisperx/alignment.py) |
| whisper.cpp | `-oj`: `transcription` with `offsets` in milliseconds. `-ojf` adds `tokens` | [`cli.cpp`](https://github.com/ggml-org/whisper.cpp/blob/60c0be6ac8fa71b1a2ae2dd938a31a34a508e774/examples/cli/cli.cpp) |

- **Cues**: one cue per segment. The parser trims the text and skips segments without text. A long segment stays one cue. [`Resegmenter`](editing.md#long-cues) breaks it up.
- **Word timestamps**: off by default. With `TranscriptReadOptions::$wordTimestamps`, each word that has a start time and occurs in the segment text gets a word timestamp before it. The parser skips the other words. The OpenAI API lists the words at the top level. A word then goes to the segment that holds the middle of the word.
- **Speakers**: off by default. `TranscriptReadOptions::$speakerVoices` writes the segment `speaker` as a `<v>` tag. See [text.md](text.md#speakers).
- **Language**: the `language` metadata. A name such as `english` becomes `en`. A code such as `en` stays.
- **Format data**: the subtitle keeps the top-level fields except the segments, words and text, for example `duration`. Its `format` field names the shape of the file: `openai-whisper` (also faster-whisper), `openai-api`, `whisperx` or `whisper.cpp`. Each cue keeps all fields of its segment, for example `start`, `avg_logprob`, `no_speech_prob`, `words` and `speaker`. The words of the OpenAI API go to the `words` of their segment.
- **Writing**: `Format::Whisper` writes the shape that `format` names, `openai-whisper` when there is none. See [Edit and save a transcript](#edit-and-save-a-transcript). A new word gets `probability` 1.0, `score` 1.0 for WhisperX, and no confidence for the OpenAI API. A new speaker on a WhisperX cue goes to its words too.
- **Not kept**: a segment without text gives no cue, so the writer drops it. A segment with an edited text has no `tokens`, because the writer cannot make token ids. A whisper.cpp segment also loses them with edited times or word timestamps, as its tokens hold times. JSON cannot hold invalid UTF-8, so a character that whisper.cpp splits across 2 tokens becomes U+FFFD.
- **Errors**: JSON without a `segments` or `transcription` list throws `ParsingException`. A response with only `words` or `text` has no cue times, so it throws too.

## Cloud speech-to-text JSON
Amazon Transcribe, Deepgram, AssemblyAI and Google Cloud Speech-to-Text return a JSON transcript. One parser per service turns it into cues.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString($transcribeJson, Format::AwsTranscribe);    // format detection does not find cloud speech-to-text JSON
$subtitle = Subtitle::fromString($deepgramResponseBody, Format::Deepgram, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true, speakerVoices: true)]));
$subtitle->getCues()[2]->getText();                                          // '<v 0><00:00:06.500>Thank <00:00:06.800>you.'
$subtitle->getCues()[2]->findFormatData('deepgram')['confidence'];           // 0.9637655
```

| Service | Parser, format data key | Cues |
|:--- |:--- |:--- |
| [Amazon Transcribe](https://docs.aws.amazon.com/transcribe/latest/dg/how-input.html#how-output) | `AwsTranscribeParser`, `aws-transcribe` | one per `results.audio_segments` entry. Without entries, grouped from `results.items` |
| [Deepgram](https://developers.deepgram.com/docs/pre-recorded-audio) | `DeepgramParser`, `deepgram` | one per `results.utterances` entry. Without utterances, one per paragraph sentence. Without paragraphs, grouped from the words |
| [AssemblyAI](https://www.assemblyai.com/docs/api-reference/transcripts/get) | `AssemblyAiParser`, `assemblyai` | one per `utterances` entry. Without utterances, grouped from `words` |
| [Google Cloud Speech-to-Text](https://cloud.google.com/speech-to-text/docs/async-time-offsets) | `GoogleSpeechParser`, `google-speech` | one per result. With diarization, grouped from the words of the last result |

- **Word grouping**: a cue ends after a word that ends a sentence with `.`, `?`, `!` or their CJK forms. It also ends before a pause of 1 s or more and where the speaker changes. A cue also ends before a word that makes it longer than 84 characters.
- **Long cues**: an audio segment, utterance or result stays one cue. [`Resegmenter`](editing.md#long-cues) breaks it up. With `TranscriptReadOptions::$wordTimestamps`, `ResegmentMode::ByWords` regroups the words with other limits.
- **Word timestamps**: off by default. With `TranscriptReadOptions::$wordTimestamps`, each word gets a word timestamp before it.
- **Speakers**: off by default. `TranscriptReadOptions::$speakerVoices` writes the speaker label of the service as a `<v>` tag, for example `<v spk_0>`, `<v 0>`, `<v A>` or `<v 1>`. The `rename` option of [`SpeakerLabels::apply()`](text.md#speakers) gives them names.
- **Amazon Transcribe**: the language comes from `results.language_code`.
- **Amazon Transcribe output**: `Format::AwsTranscribe` writes `results.transcripts`, one `pronunciation` item per word and one `punctuation` item per punctuation mark at the end of a word, with times as strings such as `"2.0"`. It numbers the item IDs again from 0. `speaker_labels` holds one segment per run of items with the same speaker. The writer writes one `audio_segments` entry per cue when the source had them, or when a cue has no words, because the items alone cannot hold such a cue. A new word gets confidence `"1.0"`, a new punctuation mark `"0.0"` as Amazon Transcribe writes it. See [Edit and save a transcript](#edit-and-save-a-transcript). The parser ignores `channel_labels`, so the writer does not write them.
- **Deepgram**: the parser reads every channel and sorts the cues by time. The language comes from `detected_language` of the first channel.
- **Deepgram output**: `Format::Deepgram` writes one channel per `channel` of the cues. Each channel gets the transcript and the words of its cues, and `paragraphs` when the source had them. A cue joins the paragraph of the cue before it when both come from one source paragraph. A new cue joins it when it has the same speaker. The paragraph transcript starts each paragraph with `Speaker 0: ` when it has a speaker, as Deepgram writes it. The writer writes one `utterances` entry per cue when the source had them, or when a cue outside paragraphs has no words. A new word gets confidence 1.0, `word` in lower case without punctuation and `punctuated_word` as in the cue. The paragraphs of a file with utterances are lost, because the parser reads only the utterances.
- **AssemblyAI**: the language `en_us` becomes `en-US`.
- **AssemblyAI output**: `Format::AssemblyAi` writes `text` and `words` with times in milliseconds. It writes one `utterances` entry per cue when the source had them, when a cue has a speaker, or when a cue has no words. Else `utterances` is `null`, as AssemblyAI writes it. A new word or utterance gets confidence 1.0, and a word without a speaker gets `"speaker": null`.
- **Google**: the parser reads the V1 and V2 field names, and a long-running operation from `operations.get` through its `response`.
- **Google output**: `Format::GoogleSpeech` writes one result per cue with the V1 or V2 field names of the source, and V1 without a source. Times are durations such as `"1.500s"` and `"6s"`. A result has no start, so a cue without words starts at the end of the result before it on the next read. Results follow each other, so the result of a cue that ends before the cue before it ends with that cue. A source with speaker diarization gives one result with all words and their `speakerLabel` or `speakerTag`. Such a result holds the cues only through their words, so a cue without words there throws `UnwritableContentException`. Cues without a diarized source get no speakers. A long-running operation from the source stays around the `response`.
- **Format data**: the subtitle keeps the top-level fields except the transcript text and the lists of words and segments. Each cue keeps all fields of its audio segment, utterance, sentence or result, with the first alternative of a Google result. Its words with their confidence are in `items` for Amazon Transcribe and in `words` for the other services. These parts stay in the subtitle format data without their lists: the Amazon Transcribe `results.speaker_labels`, the Deepgram `results.channels`, and the Google result with speaker diarization in `results`. A Deepgram sentence also keeps its paragraph without the sentences in `paragraph`.
- **Errors**: each parser throws `ParsingException` for JSON without the list it needs. Amazon Transcribe needs `results.items`, Deepgram `results.channels`, AssemblyAI `words` or `utterances`, and Google `results`.

## YouTube timed text
yt-dlp and youtube-transcript-api download YouTube captions. The formats are json3, srv3 or the older transcript XML. json3 and srv3 keep the time of each word of automatic captions. The WebVTT download keeps it as `<c>` word timestamps, and the WebVTT parser [collapses its rolling cues](formats.md#webvtt).

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('video.en.json3'), Format::YouTubeTimedText);
$subtitle = Subtitle::fromString(file_get_contents('video.en.srv3'), Format::YouTubeTimedText, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
$subtitle->getCues()[0]->getText();                                          // '<00:00:01.200>Hello <00:00:01.600>world'
$subtitle->findFormatData('youtube')['format'];                              // 'srv3'
```

| Format | Shape |
|:--- |:--- |
| json3 | `{"events": [{"tStartMs": 1200, "dDurationMs": 2300, "segs": [{"utf8": "Hello"}, {"utf8": " world", "tOffsetMs": 400}]}]}` |
| srv3 | `<timedtext format="3"><body><p t="1200" d="2300">Hello<s t="400"> world</s></p></body></timedtext>` |
| srv2 | `<timedtext><text t="1200" d="2300">Hello world</text></timedtext>` |
| srv1 and transcript XML | `<transcript><text start="1.2" dur="2.3">Hello world</text></transcript>` |

- **Automatic captions**: the parser skips the events that only add a line break. A cue in a window ends where the next cue of the same window starts, so the rolling cues do not stack.
- **No duration**: a srv1 or srv2 `<text>` without `dur` or `d`, or a srv3 `<p>` without `d`, ends at the next later start. The last one lasts [`ReadOptions::$lastCueDuration`](read-options.md). A `dur` or `d` of 0 stays 0 s long.
- **Word timestamps**: off by default. With `TranscriptReadOptions::$wordTimestamps`, each segment of a cue gets a word timestamp. This needs at least one segment of the cue with a time. srv1 and srv2 have no word times.
- **Alignment**: from the anchor point of the window position of a cue. Anchor point 0 is top left and becomes `Alignment::TopLeft`. A cue without its own window position, such as an automatic caption, gets `Alignment::BottomCenter`.
- **Pens**: the pen color becomes `<font color>`. Bold, italic and underline become `<b>`, `<i>` and `<u>`.
- **Format data**: the subtitle keeps the `format` name, and the head elements and windows of the file. Each cue keeps the other fields of its event or `<p>`, and the other fields of its segments in `segments`.

## Podcast transcripts
A podcast feed links a transcript per episode with the `<podcast:transcript>` tag. Apple Podcasts, Podverse and Fountain read it. The [Podcasting 2.0 transcript spec](https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/examples/transcripts/transcripts.md) allows SubRip, WebVTT, a JSON format and an HTML format. The library reads and writes all four.

```json
{"version": "1.0.0", "segments": [
  {"speaker": "Anna", "startTime": 0.5, "endTime": 0.75, "body": "The"},
  {"speaker": "Anna", "startTime": 1, "endTime": 1.25, "body": "bakery"}
]}
```

```html
<cite>Anna:</cite>
<time>0:00</time>
<p>The bakery opens at seven.</p>
```

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\PodcastTranscriptWriteOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

$subtitle = Subtitle::fromString($whisperJson, Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
$json     = $subtitle->toString(Format::PodcastTranscript, new WriteOptions(formats: [new PodcastTranscriptWriteOptions(wordSegments: true)]));
$html     = $subtitle->toString(Format::HtmlTranscript);
$subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents('episode.json'));   // detects a Podcasting 2.0 transcript
$subtitle = Subtitle::fromString($json, Format::PodcastTranscript, new ReadOptions(formats: [new TranscriptReadOptions(keepSegments: true)]));
```

| Class | Option | Effect |
|:--- |:--- |:--- |
| `TranscriptReadOptions` | `keepSegments` | one cue per segment. By default, segments of one word join into a cue |
| `TranscriptReadOptions` | `wordTimestamps` | a word timestamp before each word of a joined cue |
| `PodcastTranscriptWriteOptions` | `wordSegments` | one segment per word timestamp, for the word highlight of the apps. By default, one segment per cue |
| `PodcastTranscriptWriteOptions` | `prettyPrint` | indents with 4 spaces and ends with a newline |
| `HtmlTranscriptWriteOptions` | `paragraphGap` | the gap in seconds that starts a new paragraph, 2.0 by default |

- **Speakers**: the `speaker` of a segment and the name in `<cite>` become `<v Name>`, and back. A cue with two `<v>` speakers gives one segment per speaker, both with the times of the cue.
- **Joined words**: the parser joins a segment of one word with the next segment of the same speaker. It stops after a word that ends with `.`, `?`, `!` or the ellipsis U+2026. It also stops before a pause of 1 s or more. A segment with a space in its body stays one cue.
- **Word segments**: a word ends where the next word of its cue starts. The last word ends with the cue. So a round trip keeps the start of each word, not its end.
- **No end time**: a segment without `endTime` and an HTML paragraph end at the next later start. The last one lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **HTML input**: each `<time>` starts a cue. The cue holds the `<p>` elements up to the next `<time>` or `<cite>`, one line per `<p>` and `<br>`. A `<cite>` names only the next cue. The parser strips other tags and reads times such as `0:09`, `12:05` and `1:02:03.5`.
- **HTML output**: a new paragraph starts at a speaker change or a gap. The formatter writes `<cite>` only for a paragraph with a speaker, times such as `0:09` and `1:02:03`, and the text without tags.
- **Format data**: the JSON parser keeps the top-level fields except `segments` in `findFormatData('podcast-transcript')`, for example `version`. A cue of one segment keeps the other fields of the segment. The formatter writes them back, and `"version": "1.0.0"` when there is none.
- **Errors**: a segment without a numeric `startTime` throws `ParsingException`. So does a `speaker`, `endTime` or `body` of the wrong type, a `<p>` without a `<time>` before it, and a bad time. A segment without `body` gives no cue.
- **Command line tool**: the format names are `podcast-transcript` and `html`. `.json` stays the library JSON, so pass `--to podcast-transcript`.

## Plain text
`PlainTextFormatter` writes a transcript. It strips all tags and decodes the entities. A gap of 2 s or more between two cues starts a new paragraph:

```
Hello world. Where are you going?

Home.
```

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\PlainTextWriteOptions;
use SubtitleToolbox\WriteOptions;

$text = $subtitle->toString(Format::PlainText, new WriteOptions(formats: [new PlainTextWriteOptions(withTimes: true)]));
```

| `PlainTextWriteOptions` field | Default | Effect |
|:--- |:--- |:--- |
| `joinLines` | `true` | joins the lines of a cue with a space. `false` writes each line on its own line |
| `joinCues` | `true` | joins the cues of a paragraph with a space. `false` writes each cue on its own line |
| `paragraphGap` | `2.0` | the gap in seconds that starts a new paragraph. `INF` writes one paragraph |
| `withTimes` | `false` | writes the start of the paragraph as `[00:01:23] ` before it |

- **Gap**: see [Statistics](subtitle.md#statistics).
- **Cues without text**: the formatter skips them. Image cues without text need `WriteOptions(skipImageCues: true)`, as in all text formatters.
- **No parser**: the library cannot read plain text.
