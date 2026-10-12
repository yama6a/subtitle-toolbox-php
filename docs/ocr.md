# Image subtitles and OCR

Blu-ray discs store subtitles as PGS bitmaps, DVDs as VobSub bitmaps. The library reads both as image cues. OCR (optical character recognition) turns the bitmaps into text, so you can write a text format.

## Image cues
An **image cue** is a cue with a PNG image in the format data key `image`. It has no text lines until an OCR engine reads it.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\OcrLanguage;
use SubtitleToolbox\WriteOptions;

$image = CueImage::fromCue($cue);                    // $image->png, x, y, width, height, screenWidth, screenHeight, forced
file_put_contents('cue.png', $image->png);

$subtitle->recognizeText(new GlyphOcrEngine());      // sets the lines of each image cue without text
$subtitle->recognizeText($engine, OcrLanguage::German);   // the engine receives 'deu'
$subtitle->toString(Format::SubRip);

$subtitle->toString(Format::SubRip, new WriteOptions(skipImageCues: true));   // drops image cues without text
```

- **Text formats**: `toString()` throws `ImageCueWithoutTextException` for an image cue without text. So a file without OCR fails at once, and does not become a valid file with missing cues.
- **After OCR**: the cue keeps its image, so `PgsFormatter` can still write it.
- **Language**: pass an `OcrLanguage` case, for example `OcrLanguage::German`. The engine receives its value, the Tesseract model name `deu`. A string such as `'deu+eng'` or the name of a custom trained model reaches the engine as it is.
- **Confidence**: `(new OcrRunner($engine))->run($subtitle, 'eng')` does the same as `recognizeText()`. It returns an `OcrReport` whose `texts` hold the `RecognizedText` of each cue by cue index.
- **Forced flag**: `CueImage::toCue()` sets the forced flag of the cue from the `forced` field of the image. OCR keeps the flag.
- **PNG**: `PngEncoder::encode($width, $height, $pixels)` makes a PNG from a list of `0xRRGGBBAA` integers. It needs no ext-gd. It compresses with ext-zlib when it is loaded. Otherwise it writes larger, uncompressed PNG files. `PngDecoder::decode($png)` returns the width, the height and the pixels of a PNG without interlacing. It needs ext-zlib.
- **Size limit**: an image is at most 7,680 pixels wide or high. It has at most 8,294,400 pixels, the pixels of a 3840x2160 frame. A larger PGS object or VobSub bitmap throws `ParsingException`. `new CueImage()` and `PngDecoder::decode()` throw `InvalidArgumentException`. The limits are `CueImage::MAX_SIDE` and `CueImage::MAX_PIXELS`. `PgsParser` and `PgsFormatter` read and write a full 3840x2160 object in about 35 MB of PHP memory. A VobSub bitmap or an RGBA PNG of that size needs about 330 MB, so raise `memory_limit` for such files.
- **Text errors**: [common error fixes](text.md#fixing-common-errors) correct OCR errors such as `lt's` for `It's`.

## PGS
Blu-ray discs and many MKV files store subtitles as PGS bitmaps in `.sup` files. A **display set** is the group of PGS segments that changes the screen at one time.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.sup'), Format::Pgs);
$subtitle = Subtitle::fromString(file_get_contents('movie.sup'), Format::Pgs, new ReadOptions(lastCueDuration: 3));   // the last cue lasts 3 s
$subtitle->shift(-1.5)->convertFrameRate(25, 23.976);
file_put_contents('movie.synced.sup', $subtitle->toString(Format::Pgs));
```

- **Cues**: each display set that shows objects gives one cue. It ends at the next display set. A display set that repeats the same image does not start a new cue. A last cue that no later display set ends lasts [`ReadOptions::$lastCueDuration`](read-options.md).
- **Image**: one PNG covers all objects of the display set on a transparent background. It is an 8-bit indexed PNG with the colors that its pixels use. A display set with 2 objects gives an RGBA PNG. The parser applies cropping, windows and palette updates. Where objects overlap, the parser draws a later object over the earlier ones with alpha blending. A transparent pixel keeps the pixel below.
- **Forced**: `forced` in the image data is true when at least one object of the display set has the forced flag.
- **Alignment**: an image whose center is in the top third of the screen gets `Alignment::TopCenter`.
- **Errors**: the parser skips segments of unknown types. It throws `ParsingException` for a segment without the `PG` bytes, a cut-off segment, and a bitmap with too few pixels. In [lenient mode](lenient-parsing.md), it keeps the cues before a cut-off segment or a segment without the `PG` bytes.
- **Formatter**: `PgsFormatter` writes image cues back to a `.sup` file. So you can retime, cut or filter a PGS file without OCR. It also converts VobSub to PGS. It does not render text, and throws `UnwritableContentException` for a cue without an image.
- **Overlaps**: while 2 image cues overlap, the formatter shows both images in the same display sets, as 2 objects. PGS shows at most 2 objects at one time, so a third overlapping cue replaces the cue that started first. A cue that starts at the end of the previous cue replaces it. `PgsParser` reads the overlap back as one cue with one image of both objects.
- **Colors**: the formatter reduces an image with more than 255 colors. A color channel can change by 1.
- **Round trip**: a PGS file that `PgsParser` reads and `PgsFormatter` writes gives the same pixels, positions and times to 1 ms. The colors outside RGB below are the one exception. Two cues with the same image, where the second starts at the end of the first, come back as one cue.
- **Colors outside RGB**: a palette entry can hold a Y, Cr, Cb code that decodes to a channel below 0 or above 255. The parser clamps that channel. The formatter can then write a code that reads back with one channel 1 off. For example, Y 37, Cr 127, Cb 64 in a 1920x1080 file reads as RGB 23, 39, 0 and comes back as 24, 39, 0. Palettes that an authoring tool converts from RGB do not hold such codes.
- **Speed**: parsing or writing a file of 2,000 cues of 640x90 pixels takes about 3 s on PHP 8.5.

## VobSub
VobSub is the subtitle format of DVD rips. It is a pair of files. The `.idx` text file holds the palette, the screen size, the tracks and their timestamps. The `.sub` file holds the bitmaps.

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

$sub      = file_get_contents('movie.sub');
$idx      = file_get_contents('movie.idx');
$subtitle = Subtitle::fromString($sub, Format::VobSub, new ReadOptions(formats: [new VobSubReadOptions(idx: $idx)]));                   // first track
$subtitle = Subtitle::fromString($sub, Format::VobSub, new ReadOptions(formats: [new VobSubReadOptions(idx: $idx, language: 'de')]));   // first track with "id: de"
$subtitle = Subtitle::fromString($sub, Format::VobSub, new ReadOptions(formats: [new VobSubReadOptions(idx: $idx, track: 1)]));         // track with "index: 1"

$subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);  // "de", from the id line
```

- **Parse**: pass the `.sub` content and a `VobSubReadOptions` with the `.idx` content. Without it, the parser throws `InvalidArgumentException`. Format detection does not know VobSub, because it sees only one file. The command line tool takes the `.idx` file as input and reads the `.sub` file next to it.
- **Cues**: the image has the size and the position of the display area, on a screen of the `.idx` size. A subpicture with the forced start command sets `forced`.
- **Times**: a cue starts at its `timestamp`, plus the `delay` lines of its track. It ends at the stop command of the subpicture. A subpicture without a stop command ends at the next one, at most [`ReadOptions::$lastCueDuration`](read-options.md) later.
- **Colors**: the `.idx` palette and a `custom colors: ON` line apply.
- **MKV**: `Subtitle::loadTrack()` reads an `S_VOBSUB` track of an MKV file the same way, see [mkv.md](mkv.md).
- **Limits**: the parser reads one image per subpicture. Color and contrast changes after the start command do not apply. The parser ignores the `org`, `scale`, `align`, `fadein/out` and `time offset` player settings.
- **No formatter**: convert VobSub to PGS with `PgsFormatter`, or to text after OCR.

## Choosing an engine
`OcrEngineChooser` picks the engine in one place. It uses Tesseract when the `tesseract` program runs. Otherwise it uses php-glyph-ocr when the package is installed. When neither engine is installed, it throws `InvalidArgumentException` that names both engines.

```php
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\OcrEngineName;
use SubtitleToolbox\Ocr\OcrLanguage;

$subtitle->recognizeText(OcrEngineChooser::create());                            // Tesseract in English, or php-glyph-ocr
$subtitle->recognizeText(OcrEngineChooser::create(null, OcrLanguage::German));   // Tesseract in German
$subtitle->recognizeText(OcrEngineChooser::create(null, 'deu+eng'));             // Tesseract in German and English
$subtitle->recognizeText(OcrEngineChooser::create(OcrEngineName::Glyph));        // always php-glyph-ocr
OcrEngineChooser::choose();                                                      // OcrEngineName::Tesseract or OcrEngineName::Glyph
```

- **Missing engines**: `choose()` and `create()` throw `InvalidArgumentException` when neither engine is installed, or when the engine that the first argument names is missing. The message holds the install commands. For a missing Tesseract language, see [Tesseract](#tesseract).
- **Program path**: the third argument of `create()` and the second argument of `choose()` set the path of the `tesseract` program. The default is `tesseract` on the `PATH`.

| | Tesseract | php-glyph-ocr |
|:--- |:--- |:--- |
| Install | system package manager | Composer only |
| Disk | about 22 MB with English on Ubuntu 26.04, plus 1 to 4 MB per language | about 1 MB |
| Languages | more than 100, with Cyrillic, Greek, Arabic, CJK and Indic scripts | Latin-script fonts only |
| PGS 1080p, English | 100% characters, 176 ms per cue | 100%, 138 ms per cue |
| VobSub 576p, English | 100%, 168 ms per cue | 98.5%, 109 ms per cue |
| PGS 1080p, Russian | 100%, 106 ms per cue | 20% |
| Italic | not detected | detected, as `<i>` |
| Memory | about 35 MB in the `tesseract` process | about 76 MB for the database |

The test files are the generated fixtures in `tests/files/pgs` and `tests/files/vobsub`. Tesseract 5.5.0 reads them with the `tessdata_fast` models, the fast and small model set, on an x86-64 machine. The times include the start of the engine. Real files with other fonts give lower numbers for both engines.

## Tesseract
`TesseractOcrEngine` runs the [Tesseract](https://github.com/tesseract-ocr/tesseract) program, an open-source OCR engine under Apache-2.0. PHP starts it as a separate process with `proc_open`, so it needs no PHP extension.

```sh
apt install tesseract-ocr tesseract-ocr-deu      # Debian, Ubuntu
apk add tesseract-ocr tesseract-ocr-data-eng tesseract-ocr-data-deu   # Alpine
dnf install tesseract tesseract-langpack-deu     # Fedora
brew install tesseract tesseract-lang            # macOS, tesseract-lang holds all languages
winget install UB-Mannheim.TesseractOCR          # Windows, then add its folder to the PATH
```

```php
use SubtitleToolbox\Ocr\OcrLanguage;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Ocr\TesseractOcrOptions;

$subtitle->recognizeText(new TesseractOcrEngine());                                          // English
$subtitle->recognizeText(new TesseractOcrEngine(new TesseractOcrOptions(language: OcrLanguage::German)));  // German
$subtitle->recognizeText(new TesseractOcrEngine(new TesseractOcrOptions(language: 'deu+eng')));  // German and English
$subtitle->recognizeText(new TesseractOcrEngine(), OcrLanguage::Russian);                    // the language of recognizeText() wins
$subtitle->recognizeText(new TesseractOcrEngine(new TesseractOcrOptions(program: 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe')));
```

- **Languages**: `OcrLanguage` has a case for each language model of the official [tessdata set](https://tesseract-ocr.github.io/tessdoc/Data-Files-in-different-versions.html), for example `OcrLanguage::SerbianLatin` for `srp_latn`. For two or more languages, pass the model names as a string joined with `+`, for example `'deu+eng'`. The English data comes with the program, except on Alpine. On Debian and Ubuntu, each other language is a package, for example `tesseract-ocr-rus`. On macOS, `tesseract-lang` holds all languages.
- **Missing program or language**: every `recognize()` call checks its language against the installed languages. The engine checks the program and reads the installed languages once per program path. A missing program or language throws `InvalidArgumentException` with the install commands, or with the list of installed languages. A failed run of `tesseract` throws `OcrException`.
- **Images**: the engine turns each cue image into dark text on white and passes it to Tesseract in a temporary file.
- **Options**: `TesseractOcrOptions` holds the settings. `language` is the language for cues where `recognizeText()` passes none, default `eng`. The constructor takes an `OcrLanguage` case or a string, and the property holds the string. `program` is the path of `tesseract`. `pageSegmentationMode` is the `--psm` value, default 6, one block of text. `scale` from 1 to 8 scales the image up, default 2 on screens below 720 lines and 1 above. `invert` and `threshold` change the image before Tesseract reads it. The defaults give the fewest errors on the test files.
- **Lines and confidence**: each Tesseract text line becomes one line. The confidence is the mean word confidence of the cue, from 0 to 1, or null for a cue without text.
- **Italic**: Tesseract 4 and later do not report italic text, so the lines have no `<i>` tags.
- **Speed**: each cue starts one `tesseract` process. Loading the language model takes about 110 ms of each call.

## php-glyph-ocr
`GlyphOcrEngine` reads the bitmaps of PGS and VobSub cues in pure PHP. It uses the optional package [yama6a/php-glyph-ocr](https://github.com/yama6a/php-glyph-ocr), a port of the nOCR engine of Subtitle Edit.

```sh
composer require yama6a/php-glyph-ocr:^0.3
```

```php
use SubtitleToolbox\Format;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Subtitle;

$subtitle = Subtitle::fromString(file_get_contents('movie.sup'), Format::Pgs);   // image cues
$subtitle->recognizeText(new GlyphOcrEngine());                                  // subtitle fonts database by default
file_put_contents('movie.srt', $subtitle->toString(Format::SubRip));
```

- **Package**: without php-glyph-ocr, `new GlyphOcrEngine()` throws `InvalidArgumentException` with the `composer require` command.
- **Version**: the library works with php-glyph-ocr 0.3. The `conflict` entry of `composer.json` stops Composer from installing an older or a 0.4 or later version next to it.
- **Database**: `GlyphOcrOptions::$database` is a `GlyphOcr\GlyphDatabase`. `GlyphDatabase::subtitleFonts()` holds upright and italic glyphs of DejaVu Sans, Liberation Sans and Noto Sans. For other fonts, it adds the Latin database of Subtitle Edit. Liberation Sans has the metrics of Arial. The database takes about 76 MB of memory, so engines that exist at the same time share one copy.
- **Subtitle Edit output**: `new GlyphOcrEngine(new GlyphOcrOptions(database: GlyphDatabase::latin(), lineContext: false))` reads the text as the nOCR engine of Subtitle Edit does.
- **Options**: the constructor takes a `GlyphOcrOptions`. Each field except `database` sets the parameter of the same name of `GlyphOcr\Recognizer` in php-glyph-ocr 0.3, with the same default. An invalid value throws `InvalidArgumentException` in the `GlyphOcrOptions` constructor. A recognizer error on an image throws `OcrException`.

| Field | Default | Allowed values | Effect |
|:--- |:--- |:--- |:--- |
| `database` | null | a `GlyphDatabase` or null | the glyphs to match. Null is `GlyphDatabase::subtitleFonts()` |
| `inkThreshold` | 200 | 1 to 765 | a pixel is ink when the sum of its red, green and blue, each multiplied by alpha, is at least this. A dark outline is no ink |
| `spaceWidth` | null | 1 or more, or null | the empty columns between two glyphs that make a space. Null derives it from the glyph height of each line |
| `maxWrongPixels` | 25 | 0 or more | the pixels that a loose match may get wrong |
| `fixLatinCase` | true | true or false | picks upper or lower case for letters such as o and O from their height |
| `unknownText` | `'*'` | any string | the text of a glyph that matches nothing |
| `italicSlant` | 0.0 | 0 to 1 | above 0, a glyph that matches nothing is slanted back by this factor and tried again. 0.2 fits most italic fonts |
| `rightToLeft` | false | true or false | puts the glyphs of each line in right to left order |
| `minLineHeight` | 12 | 1 or more | the minimum line height in pixels until the engine has learned the glyph heights |
| `lineContext` | true | true or false | compares each glyph with the other glyphs of its line, see **I and l** below. False keeps the database text |

```php
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;

$subtitle->recognizeText(new GlyphOcrEngine(new GlyphOcrOptions(italicSlant: 0.2, spaceWidth: 6)));
```

- **One engine per stream**: the engine learns the glyph heights from the cues it reads. So use a new engine for each subtitle stream.
- **Italic**: a word becomes italic when most of its characters match italic glyphs.
- **Language**: the engine ignores the language argument. The database sets the characters it knows.
- **Confidence**: the `RecognizedText` confidence is the mean confidence of the glyphs of the cue. A glyph that matches nothing reads as `unknownText` with confidence 0.
- **I and l**: most sans-serif fonts draw capital I and lower case l as the same bar. The engine compares each bar with the capitals and the ascenders of its line. So it reads both letters correctly in the test files. The option `GlyphOcrOptions::$lineContext` controls this and is on by default. Fix remaining errors with [`CommonErrorFixer`](text.md#fixing-common-errors).
- **Limits**: the text must have one color on a transparent or dark background.
- **Accuracy**: the default database reads the 1080p Blu-ray test file in Liberation Sans with 100% correct characters. It reads the 576p DVD test file with 98.5%. Other fonts give more errors. Training a database for the font of your file fixes most of them.
- **Speed and memory**: OCR of a 1,500-cue 1080p PGS file takes about 2 minutes on one core with PHP 8.5. It needs up to 170 MB of memory. Raise `memory_limit` above the default 128 MB for such a file.

### Training a database
Train a glyph from a sample that a person confirmed. Then save the database and pass it to the engine:

```php
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;
use GlyphOcr\Trainer;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;

$database   = GlyphDatabase::subtitleFonts();
$recognizer = new Recognizer($database);
$trainer    = new Trainer();
foreach ($subtitle->getCues() as $cue) {
    $result = $recognizer->recognize(Image::fromPng(CueImage::fromCue($cue)->png));
    foreach ($result->unknownChars() as $char) {
        $database->add($trainer->train($char->sample, askAPerson($char->sample->toAscii())));
    }
}
$database->save('my-font.nocr');

$subtitle->recognizeText(new GlyphOcrEngine(new GlyphOcrOptions(database: GlyphDatabase::fromFile('my-font.nocr'))));
```

- `askAPerson()` is your own code. It shows the glyph and returns the text that a person types.
- A new glyph wins over older glyphs that match equally well. Train a misread character the same way, from `$result->lines[$line]->chars[$index]->sample`.
- The [php-glyph-ocr README](https://github.com/yama6a/php-glyph-ocr#databases-and-training) describes the `.nocr` files, `Recognizer::split()` and `GlyphSample::merge()`.
- The command line tool takes a trained database with `--ocr-database`, see [cli.md](cli.md#ocr).

## Other OCR engines
An engine is a class that implements `OcrEngine`. This example sends each image to an OCR web service:

```php
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\RecognizedText;

final class WebServiceEngine implements OcrEngine
{
    public function recognize(CueImage $image, ?string $language): RecognizedText
    {
        $text = $this->client->post('/ocr', ['image' => base64_encode($image->png), 'language' => $language]);

        return new RecognizedText(explode("\n", trim($text)));
    }
}

$subtitle->recognizeText(new WebServiceEngine(), 'eng');
```

- **Language**: the engine receives a model name string or null. `recognizeText()` and `OcrRunner` turn an `OcrLanguage` case into its value first.
- **Lines**: the engine returns plain text or core markup, for example `<i>` for italic text. The library drops empty lines.
- **Confidence**: pass a value from 0 to 1 as the second argument of `RecognizedText`, or leave it null.
