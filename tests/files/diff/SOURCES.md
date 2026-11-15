# Sources

| File | Source | License |
|:--- |:--- |:--- |
| `own_original.srt` | Written for this repository in the shape of a SubRip file from a subtitle editor: BOM, CR LF, 13 cues, two-line cues. It is the old version. | MIT |
| `own_edited.srt` | Written for this repository. An edited copy of `own_original.srt` without BOM and with LF: cue 2 later, typo fixes in cues 4 and 9, cue 6 split in two, cue 8 removed, italics removed from cue 10, a line break moved in cue 11, and one added cue. | MIT |
| `own_report.txt` | Written for this repository. Expected `SubtitleDiff::toText()` output for `own_original.srt` against `own_edited.srt`. | MIT |
