# Core markup

Cue lines hold HTML-like inline tags, the **core markup**. Parsers convert the styling of their format to this tag set. Formatters convert it back and strip the tags that their format cannot show. [formats.md](formats.md) lists the tags that each format keeps.

| Tag | Meaning |
|:--- |:--- |
| `<b>` | bold |
| `<i>` | italic |
| `<u>` | underline |
| `<s>` | strikethrough |
| `<font color="#ff0000">` | text color |
| `<v Fred>` | speaker, see [text.md](text.md#speakers) |
| `<00:01:02.500>` | word timestamp, the time a word is spoken |

- **Escaping**: text that is not markup keeps `<`, `>` and `&` escaped as `&lt;`, `&gt;` and `&amp;`.
- **Speaker names**: a quote in a name stays a raw character, for example `<v O'Neil>`.
- **Font colors**: `<font color='#FF0000'>`, `<font color=#ff0000>` and `<FONT COLOR="#FF0000">` give the same color. The formatters read the `color` attribute with double quotes, single quotes or no quotes, in any case and next to other attributes.
- **Color values**: formatters that convert a color, such as the ASS, SCC and WebVTT formatters, read the value with one rule. They accept `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb(r, g, b)`, `rgba(r, g, b, a)` and the 18 TTML color names, such as `yellow`, `aqua` or `navy`, in any case. So `#FF0`, `yellow` and `Yellow` all give `#ffff00`. Each formatter then writes the colors that its format has, see [formats.md](formats.md). The formatters drop an alpha channel. TTML output keeps a valid TTML color as it is.
- **WebVTT color classes**: a WebVTT `<c>` tag with one of the 8 [color classes](https://www.w3.org/TR/webvtt1/#default-text-color) is a text color too. Formatters that write `<font color>` read `<c.yellow>` as `<font color="#ffff00">`. The colors are `white` `#ffffff`, `lime` `#00ff00`, `cyan` `#00ffff`, `red` `#ff0000`, `yellow` `#ffff00`, `magenta` `#ff00ff`, `blue` `#0000ff` and `black` `#000000`. When a tag has 2 color classes, the later one in this list wins, as in a browser.

## Helpers
The `Markup` class has the helpers that the parsers and formatters use. They help when you write your own OCR engine or text change. They also help in code that reads or writes a format that the library does not have.

```php
use SubtitleToolbox\Markup;

Markup::stripAllTags('<b>Hi</b> &amp; bye');                  // 'Hi &amp; bye'
Markup::plainText('<b>Hi</b> &amp; bye');                     // 'Hi & bye'
Markup::keepTags('<b>Hi</b> <c.red>you</c>', ['b']);          // '<b>Hi</b> you'
Markup::decodeEntities('Hi &amp; bye');                       // 'Hi & bye'
Markup::escapeText('Fish & Chips');                           // 'Fish &amp; Chips'
Markup::visibleLength('<i>Café</i> &amp; tea ');              // 10, without tags, entities decoded, trimmed
Markup::voiceTag("O'Neil");                                   // "<v O'Neil>"
Markup::insertWordTimestamps('Hi there', [['Hi', 1.0], ['there', 1.4]]);   // '<00:00:01.000>Hi <00:00:01.400>there'
Markup::wordTimestampSeconds('<00:01:02.500>');               // 62.5
Markup::mapWordTimestamps('<00:00:01.000>Hi', fn (float $t): float => $t + 2);   // '<00:00:03.000>Hi'
Markup::mapTextRuns(['<i>Hi</i> you'], fn (string $text): string => strtoupper($text));   // ['<i>HI</i> YOU']
Markup::hasVisibleText(['<i></i>', ' ']);                     // false
```

- **Tags**: a tag has no white space after its `<`. The helpers, the formatters and word highlighting read `< b>` as text, so `hasVisibleText(['< b>'])` returns true and `stripAllTags('a < b > c')` returns `'a < b > c'`.
- **Text runs**: `mapTextRuns()` calls the function for each [text run](text.md#text-runs) and escapes the result again.
- **Speaker tags**: `voiceTag()` escapes `&`, `<` and `>` in the name and keeps quotes.
- **Word timestamps**: `insertWordTimestamps()` escapes the text. It skips a word without a start time or a word that it does not find in the text. `mapWordTimestamps()` and `SubtitleCue::mapWordTimestamps()` change each time and make a negative time 0.
