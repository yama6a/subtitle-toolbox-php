# Sources

All files here are written for this repository. They hold no third-party text.

`generate.php` draws the cues of `OCR_CUES` with the PGS fixture writer of `tests/files/pgs/generator/` and the font of `tests/files/ocr/`. It then reads them, and the text fixtures of `../pgs/` and `../vobsub/`, with `GlyphOcrEngine`, the Latin database of php-glyph-ocr and `lineContext` off. This reads as php-glyph-ocr 0.1 did, so the text keeps the I and l errors that `CommonErrorFixer` fixes. Run it from the repository root to rebuild the `.sup` and `.ocr.srt` files:

```sh
php tests/files/fixing/generate.php
```

| File | Source | License |
|:--- |:--- |:--- |
| `ocr-en.sup`, `ocr-de.sup`, `ocr-fr.sup`, `ocr-es.sup` | Written for this repository by `generate.php`. 1920x1080, white Liberation Sans at 52 px, 2 to 6 cues in English, German, French and Spanish. 3 to 12 words per language start with a capital I | MIT |
| `ocr-en.ocr.srt`, `ocr-de.ocr.srt`, `ocr-fr.ocr.srt`, `ocr-es.ocr.srt` | The text that `generate.php` reads from the `.sup` file of the same name, OCR errors included | MIT |
| `text_1080p.ocr.srt`, `text-pal.ocr.srt` | The text that `generate.php` reads from `../pgs/text_1080p.sup` and `../vobsub/text-pal.sub`, OCR errors included | MIT |
| `web-errors.srt` | Written for this repository in the shape of a downloaded SubRip file: UTF-8 BOM, CR LF, `<i>`, `<b>` and `<font color>` tags. 12 cues with spacing, punctuation, dash and tag errors | MIT |
| `user_OCRFixReplaceList.xml` | Written for this repository in the shape of a Subtitle Edit user replace list, with entries for the OCR errors of `text-pal.ocr.srt` | MIT |
| `web-errors.fixed.srt`, `ocr-*.fixed.srt` | `CommonErrorFixer` output for the file of the same name, with the default options and its language | MIT |
| `text_1080p.fixed.srt` | `CommonErrorFixer` output for `text_1080p.ocr.srt`, language `en` | MIT |
| `text-pal.fixed.srt` | `CommonErrorFixer` output for `text-pal.ocr.srt`, language `en`, with `user_OCRFixReplaceList.xml` | MIT |
| `optional-rules.srt` | Written for this repository: 4 cues with the errors of the `CommonErrorFixer` rules that are off by default | MIT |
| `optional-rules.fixed.srt` | `CommonErrorFixer` output for `optional-rules.srt`, language `en`, with every rule that is off by default turned on | MIT |
| `ad-cues.srt` | Written for this repository in the shape of a downloaded SubRip file: 9 cues with site ads in English, Spanish and German, and credits, web and e-mail addresses in dialogue | MIT |
| `ad-cues.fixed.srt` | `stbox convert ad-cues.srt --to srt --errors-fix --language en --errors-enable adCues` output | MIT |
| `ad-cues-test-set/*.srt` | Written for this repository: 50 SubRip files, 5 each in English, Spanish, German, French, Portuguese, Arabic, Hebrew, Chinese, Russian and Turkish. 1,760 cues. Ad and credit cues follow the shape of real download site cues in our own words. Dialogue holds web and e-mail addresses, credit phrases, repeated lines and dots that look like domains | MIT |
| `ad-cues-test-set/labels.json` | The kind of each cue of the files in `ad-cues-test-set/`, for example `ad-url` or `dlg-url-edge` | MIT |
| `latin1_unclosed_italic.srt` | Written for this repository in Windows-1252: one cue with an `<i>` tag without its end tag | MIT |
