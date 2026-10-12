# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `fixture-formats.json` | Generated for this repository with version 1.x of the library. For each fixture under `tests/files/`, it holds the format name of `FormatRegistry::forPath()` and of `FormatDetector::detect()`. `FormatTest` checks `Format::tryFromPath()` and `Format::detect()` against it | MIT |
| `own_styled.1x.srt` | Generated for this repository. It is the output of version 1.x of the library for `srt/real/own_styled.srt`, read with `SubRipParser` and written with `SubRipFormatter` | MIT |
| `formats.txt` | Generated for this repository. Expected output of `subtitle-toolbox formats`. `FormatTest` compares the command output with it | MIT |
