# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `ferry_danish.890` | Written for this repository by `../generate-fixtures.php`. The Danish language code with the Scandinavian letters | MIT |
| `harbour_english.890` | Written for this repository by `../generate-fixtures.php`. Accent codes, italics, the 3 alignments and a cue of 3 lines in 2 blocks | MIT |
| `library_hebrew.890` | Written for this repository by `../generate-fixtures.php`. The Hebrew table, with digits and punctuation in display order | MIT |
| `market_arabic.890` | Written for this repository by `../generate-fixtures.php`. The Arabic table | MIT |
| `noodle_chinese.890` | Written for this repository by `../generate-fixtures.php`. UTF-16BE text with italics | MIT |
| `train_russian.890` | Written for this repository by `../generate-fixtures.php`. The Russian table | MIT |
| `weather_greek.890` | Written for this repository by `../generate-fixtures.php`. The Greek table | MIT |
| `expected/*.json` | Generated for this repository. Expected parse result of the file with the same name, written by `tests/files/generate-read-goldens.php` | MIT |
| `expected/*.to.*` | Generated for this repository. Expected output of the file with the same name in another format, written by `tests/files/generate-write-goldens.php` | MIT |

No permissive real Cavena 890 files are known. The script writes each file in the shape that the Cavena 890 code of Subtitle Edit reads and writes, with the bytes of its text tables, see THIRD_PARTY_NOTICES.md.

Regenerate the self-written files with `php tests/files/cavena890/generate-fixtures.php`. The script writes every byte itself and does not use the library.
