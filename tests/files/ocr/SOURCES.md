# Sources

The PGS and VobSub generators use `generator/TextBitmap.php` to draw the text cues of `pgs/text_1080p.sup` and `vobsub/text-pal.sub`. The text is written for this repository.

`TrueTypeFont.php` reads the font outlines, and `Rasterizer.php` fills them with float arithmetic only. So every PHP version gives the same bytes, without FreeType, GD or zlib. Both files come from the fixture generator of [php-glyph-ocr](https://github.com/yama6a/php-glyph-ocr/tree/5348524/tests/fixtures/generator) at commit `5348524`, MIT. The rasterizer uses the accumulation method of [font-rs](https://github.com/raphlinus/font-rs), Apache 2.0.

| File | Source | License |
|:--- |:--- |:--- |
| `generator/TrueTypeFont.php`, `generator/Rasterizer.php` | [php-glyph-ocr](https://github.com/yama6a/php-glyph-ocr/tree/5348524/tests/fixtures/generator), namespace and quotes changed | MIT |
| `generator/TextBitmap.php` | Written for this repository, after `TextRenderer.php` of php-glyph-ocr | MIT |
| `fonts/LiberationSans-Regular.ttf`, `fonts/LiberationSans-Italic.ttf` | [Liberation Fonts 2.1.5](https://github.com/liberationfonts/liberation-fonts/releases/tag/2.1.5), unchanged | SIL Open Font License 1.1, see `fonts/Liberation-LICENSE.txt` |
| `fonts/Liberation-LICENSE.txt` | The licence text of [Liberation Fonts 2.1.5](https://github.com/liberationfonts/liberation-fonts/releases/tag/2.1.5), unchanged | SIL Open Font License 1.1 |
| `fake-tesseract/tesseract` | Written for this repository. A shell script that stands in for the `tesseract` program in tests | MIT |
