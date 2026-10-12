# Contributing

Run the tests with `composer test`. Each pull request carries one label that sets the version bump: `major`, `minor`, `patch` or `skip-release`. Every merge to `master` publishes a release.

## Credit for ported work

- Copy a file from another project: add a section to [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md). It holds the project URL, the licence, the pinned commit, the file list and the upstream notice copied unchanged. Add an Apache-2.0 `NOTICE` file too when the project has one.
- Port or adapt code: add a docblock line to the class or method, even when the new code looks different. Pin the link to a commit.

  ```php
  /**
   * Adapted from mantas-done/subtitles, src/Code/Converters/StlConverter.php
   * (https://github.com/mantas-done/subtitles/blob/959d1705b1ed12cbb9ddba517f8bcb814e1919a8/src/Code/Converters/StlConverter.php), MIT.
   */
  ```

- Write "Adapted from" for a re-implementation of the same logic. Write "Copied from" for verbatim code.
- For Apache-2.0 sources, add a line "Changed: ..." that states what you changed.
- A test with data from another project names the project in its data provider key. A ported test case cites the upstream test method in a comment.
- A fixture folder lists every file in its `SOURCES.md` with its source and licence.
- A project that re-hosts another project's data gets a credit for the original source too.

## Licence rules

- Permissive sources may be adapted, always with credit.
- Copyleft, proprietary and unlicensed sources are behaviour references only. Read the behaviour or the spec, write your own code and tests, and copy nothing.
- A project not in the table: check its licence at the pinned commit before you use anything. An unknown or missing licence means ideas only.

| Project | Licence | Use allowed |
|:--- |:--- |:--- |
| mantas-done/subtitles | MIT | adapt with credit |
| captioning/captioning | MIT | adapt with credit |
| podlove/webvtt-parser | MIT | adapt with credit |
| hartman/vtt-vivid | Apache-2.0 OR MIT (test data from mozilla/vtt.js: Apache-2.0) | adapt with credit; vtt.js data needs its Apache-2.0 NOTICE |
| delphiki/SubRip-File-Parser | BSD | adapt with credit |
| Saeven/subtitles, ismailceylan/php-subtitle-toolkit, medienbaecker/kirby-vtt, Thijs-Riezebeek/subtitles | MIT | adapt with credit |
| benlipp/srt-parser | DBAD (permissive, attribution) | adapt with credit |
| johnnoel/php-ass | CC-BY-SA-3.0 | behaviour only |
| iceman1010/srt-translation-validator | proprietary, no redistribution | ideas only, nothing copied |
| subtitle.js, subsrt, media-captions (vidstack), osk/node-webvtt | MIT | adapt with credit |
| shaka-project/shaka-player | Apache-2.0 | adapt with credit, keep the licence text, state changes |
| pysubs2, srt (cdown) | MIT | adapt with credit |
| pycaption (PBS) | Apache-2.0 | adapt with credit + keep NOTICE |
| sandflow/ttconv | BSD-2-Clause (checked @ 1fed6ab) | adapt with credit |
| go-astisub, martinlindhe/subtitles, libgosubs | MIT | adapt with credit |
| androidx/media (Media3) | Apache-2.0 | adapt with credit + keep NOTICE, state changes |
| JDaren/subtitleConverter | MIT (per README) | adapt with credit |
| noophq/subtitle | LGPL-3.0 | behaviour only |
| Subtitle Edit (libse/seconv) | MIT only at commit 58fad713d9c2b322784bc2fa52148e078dbc8759 and later (older releases GPL-3.0) | adapt with credit, only from MIT-licensed commits, pin the commit |
| AlexPoint/SubtitlesParser | MIT | adapt with credit |
| kitsumed/SubtitlesParserV2 | LGPL-3.0 | behaviour only |
| Aegisub | BSD-3/ISC per file header (some files differ) | adapt with credit only if the file header is BSD/ISC; otherwise behaviour only |
| KDE Subtitle Composer | GPL-2.0-or-later | behaviour only |
| MKVToolNix | GPL-2.0 | behaviour only |
| FFmpeg | LGPL-2.1+ (parts GPL) | behaviour only |
| libass | ISC | adapt with credit |
| CCExtractor | GPL-2.0 | behaviour only |
| subparse (Rust) | MPL-2.0 (file-level copyleft) | behaviour only |
| rsubs-lib | MIT | adapt with credit |
| aspasia | 0BSD | adapt (credit appreciated) |
| chardetng (used by aspasia) | Apache-2.0 OR MIT, (c) Mozilla Foundation (checked @ 4a53a99) | adapt with credit under MIT |
| alass | GPL-3.0 | behaviour only |
| webvtt-ruby, srt (ruby), subtitle_it | MIT | adapt with credit |
| SubTypo, submerger, subtitle_app_utils (Kotlin) | GPL-3.0 | behaviour only |
| SwiftSubtitles, SwiftWebVTT | MIT | adapt with credit |
| swift-subtitle-kit | MIT, (c) 2026 Dionysis Karatzas (checked @ 728d62d) | adapt with credit; its `sample.*` fixtures are copies of subsrt's (credit subsrt); `embedded-bom-srt-fixture.srt` is its own |
| LibreTranslate (server) | AGPL-3.0 (checked @ 54a66e8) | behaviour only: a client for its HTTP API is our own code; never copy server code |
| KBlixt/subcleaner | no licence found | ideas only |
| Liberation Fonts | SIL OFL 1.1 | redistribute with the licence text (already in `tests/files/ocr/fonts/`) |
| Podcastindex-org/podcast-namespace examples | CC0-1.0 | free to use; credit is a courtesy |
| W3C specs and examples | W3C Software and Document License | examples may be used with the W3C notice |
| ISO/IEC, 3GPP, SMPTE, EBU, CEA specs, Adobe docs | specifications | implement from the spec; no code exists to copy |
