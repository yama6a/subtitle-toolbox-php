<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class LenientParsingTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/lenient/";

    private const SKIPPED  = ParseWarningAction::Skipped;
    private const REPAIRED = ParseWarningAction::Repaired;


    /**
     * Each case holds the parser, the message of the strict ParsingException or the strict cue count, the cues and the warnings.
     */
    public static function damagedFiles(): array
    {
        return [
            "SubRip without cue numbers" => [
                "missing_cue_numbers.srt",
                SubRipParser::class,
                "Block #1 has no cue number on its first line.",
                [
                    [1, 3.5, "The train leaves at nine."],
                    [4, 6, "Platform two, next to the bakery."],
                    [6.5, 8, "Bring an umbrella."],
                    [9, 11, "It may rain in the afternoon."],
                ],
                [
                    [5, 1, self::REPAIRED, "Block #1 has no cue number on line 5. The parser read the cue without it."],
                    [8, 2, self::REPAIRED, "Block #2 has no cue number on line 8. The parser read the cue without it."],
                ],
            ],
            "SubRip with a bad timestamp and a broken arrow" => [
                "bad_timestamp.srt",
                SubRipParser::class,
                "The time \"00:00:0G,000\" is not valid.",
                [
                    [1, 3, "Good morning."],
                    [7, 9, "Fresh bread every day."],
                    [10, 12, "See you tomorrow."],
                ],
                [
                    [6, 1, self::SKIPPED, "The time \"00:00:0G,000\" is not valid."],
                    [10, 2, self::REPAIRED, "Block #2 has the arrow \"->\" in its timing line. The parser read it as \"-->\"."],
                ],
            ],
            "SubRip with arrows of other lengths and spacing" => [
                "arrow_variants.srt",
                SubRipParser::class,
                "Block #3 has no timing line on its second line.",
                [
                    [1, 2, "No spaces around the arrow."],
                    [3, 4, "Two spaces around the arrow."],
                    [5, 6, "A tab around the arrow."],
                    [7, 8, "A short arrow."],
                    [9, 10, "A long arrow."],
                ],
                [
                    [14, 3, self::REPAIRED, "Block #3 has the arrow \"->\" in its timing line. The parser read it as \"-->\"."],
                    [18, 4, self::REPAIRED, "Block #4 has the arrow \"--->\" in its timing line. The parser read it as \"-->\"."],
                ],
            ],
            "SubRip with full-width delimiters in timing lines" => [
                "full_width_delimiters.srt",
                SubRipParser::class,
                "The time \"00：00：03，000\" is not valid.",
                [
                    [1, 2, "火车三点出发。"],
                    [3, 4, "请在二号站台等候，谢谢。"],
                    [5, 6, "時刻：午後三時。"],
                ],
                [
                    [6, 1, self::REPAIRED, "Block #1 has full-width delimiters in its timing line. The parser read them as ASCII."],
                    [10, 2, self::REPAIRED, "Block #2 has full-width delimiters in its timing line. The parser read them as ASCII."],
                ],
            ],
            "SubRip without empty lines between cues" => [
                "missing_empty_line.srt",
                SubRipParser::class,
                "Block #0 has no cue number on its first line.",
                [
                    [1, 2.5, "The wind is cold today."],
                    [3, 5, "Snow falls in the hills."],
                    [5.5, 7, "The roads are closed."],
                    [8, 10, "Stay at home."],
                ],
                [
                    [4, 0, self::REPAIRED, "Block #0 has no empty line before line 4. The parser split the block there."],
                    [7, 0, self::REPAIRED, "Block #0 has no empty line before line 7. The parser split the block there."],
                    [7, 0, self::REPAIRED, "Block #0 has no cue number on line 7. The parser read the cue without it."],
                ],
            ],
            "SubRip with text before the first cue" => [
                "text_before_first_cue.srt",
                SubRipParser::class,
                "Block #0 has no cue number on its first line.",
                [
                    [1, 3, "Clouds move in from the west."],
                    [4, 6, "Sun again by Friday."],
                ],
                [
                    [1, 0, self::SKIPPED, "Block #0 has no cue number on its first line. The line is \"Downloaded from www.example.org\"."],
                ],
            ],
            "SubRip with a truncated last cue" => [
                "truncated_last_cue.srt",
                SubRipParser::class,
                "The time \"00:00:0\" is not valid.",
                [
                    [1, 3, "The next stop is the station."],
                    [4, 6, "Please mind the gap."],
                ],
                [
                    [10, 2, self::SKIPPED, "The time \"00:00:0\" is not valid."],
                ],
            ],
            "SubRip with empty lines inside cues" => [
                "empty_line_in_cue.srt",
                SubRipParser::class,
                3,
                [
                    [1, 3.5, "The museum opens at ten.\nTickets are sold at the door."],
                    [4, 6, "Guided tours start at noon."],
                    [7, 9, "Bags stay in the lockers."],
                ],
                [
                    [5, 0, self::REPAIRED, "Block #0 has an empty line before line 5 inside the cue. The parser kept the text after it in the cue."],
                    [10, 1, self::REPAIRED, "Block #1 has an empty line before line 10 inside the cue. The parser kept the text after it in the cue."],
                ],
            ],
            "SubRip with mixed line endings" => [
                "mixed_line_endings.srt",
                SubRipParser::class,
                3,
                [
                    [1, 3, "The oven is hot."],
                    [4, 6, "The loaves rise."],
                    [7, 9, "The shop is open."],
                ],
                [],
            ],
            "WebVTT with a comma in a timestamp" => [
                "bad_timestamp.vtt",
                WebVttParser::class,
                "The time \"00:00:06,000\" is not valid.",
                [
                    [1, 3, "Good morning."],
                    [4, 6, "The bakery opens at seven."],
                    [7, 9, "See you tomorrow."],
                ],
                [
                    [8, 2, self::REPAIRED, "Block #2 has the time \"00:00:06,000\", which is not in the form hh:mm:ss.mmm. The parser read it as 6 s."],
                ],
            ],
            "WebVTT without empty lines after the header and between cues" => [
                "missing_empty_line.vtt",
                WebVttParser::class,
                "The WEBVTT header has no empty line before the first cue.",
                [
                    [1, 2.5, "The wind is cold today."],
                    [3, 5, "Snow falls in the hills."],
                    [5.5, 7, "The roads are closed."],
                    [8, 10, "Stay at home."],
                ],
                [
                    [4, 0, self::REPAIRED, "The WEBVTT header has no empty line before the first cue. " .
                                           "The parser split the header block at line 4."],
                ],
            ],
            "WebVTT without the WEBVTT line" => [
                "missing_signature.vtt",
                WebVttParser::class,
                "The file does not start with WEBVTT.",
                [
                    [1, 3, "The kettle is on."],
                    [4, 6, "Tea in five minutes."],
                ],
                [
                    [1, 0, self::REPAIRED, "The file does not start with WEBVTT. The parser read the cues without it."],
                ],
            ],
            "WebVTT after two BOMs" => [
                "two_boms.vtt",
                WebVttParser::class,
                2,
                [
                    [1, 3, "The bus is late again."],
                    [4, 6, "We can walk instead."],
                ],
                [],
            ],
            "WebVTT with text before the WEBVTT line" => [
                "text_before_signature.vtt",
                WebVttParser::class,
                "The file does not start with WEBVTT.",
                [
                    [1, 3, "The garden needs water."],
                    [4, 6, "The hose is in the shed."],
                ],
                [
                    [1, 0, self::REPAIRED, "The file does not start with WEBVTT. The parser skipped the lines before line 4."],
                ],
            ],
            "WebVTT with a damaged WEBVTT line" => [
                "damaged_signature.vtt",
                WebVttParser::class,
                "The file does not start with WEBVTT.",
                [
                    [1, 3, "The library opens at ten."],
                    [4, 6, "It closes at six."],
                ],
                [
                    [1, 0, self::REPAIRED, "The file does not start with WEBVTT. The parser skipped the lines before line 4."],
                ],
            ],
            "WebVTT with text before the first cue" => [
                "text_before_first_cue.vtt",
                WebVttParser::class,
                "Block #1 is not a WebVTT cue, comment, style or region.",
                [
                    [1, 3, "Clouds move in from the west."],
                    [4, 6, "Sun again by Friday."],
                ],
                [
                    [3, 1, self::SKIPPED, "Block #1 is not a WebVTT cue, comment, style or region. The line is \"Downloaded from www.example.org\"."],
                ],
            ],
            "WebVTT with a truncated last cue" => [
                "truncated_last_cue.vtt",
                WebVttParser::class,
                "The time \"00:00:0\" is not valid.",
                [
                    [1, 3, "The next stop is the station."],
                    [4, 6, "Please mind the gap."],
                ],
                [
                    [9, 3, self::SKIPPED, "The time \"00:00:0\" is not valid."],
                ],
            ],
            "WebVTT with empty lines between the timing line and the text" => [
                "mantas_multiple_new_lines.vtt",
                WebVttParser::class,
                "Block #2 is not a WebVTT cue, comment, style or region.",
                [
                    [0, 1, "one\ntwo"],
                    [2, 3, "three"],
                ],
                [
                    [6, 1, self::REPAIRED, "Block #1 has an empty line before line 6 inside the cue. The parser kept the text after it in the cue."],
                    [8, 1, self::REPAIRED, "Block #1 has an empty line before line 8 inside the cue. The parser kept the text after it in the cue."],
                    [13, 2, self::REPAIRED, "Block #2 has an empty line before line 13 inside the cue. The parser kept the text after it in the cue."],
                ],
            ],
            "WebVTT with mixed line endings" => [
                "mixed_line_endings.vtt",
                WebVttParser::class,
                3,
                [
                    [1, 3, "The oven is hot."],
                    [4, 6, "The loaves rise."],
                    [7, 9, "The shop is open."],
                ],
                [],
            ],
            "SBV with empty lines inside cues" => [
                "empty_line_in_cue.sbv",
                SbvParser::class,
                3,
                [
                    [1, 3.5, "The museum opens at ten.\nTickets are sold at the door."],
                    [4, 6, "Guided tours start at noon."],
                    [7, 9, "Bags stay in the lockers."],
                ],
                [
                    [4, 0, self::REPAIRED, "Block #0 has an empty line before line 4 inside the cue. The parser kept the text after it in the cue."],
                    [8, 1, self::REPAIRED, "Block #1 has an empty line before line 8 inside the cue. The parser kept the text after it in the cue."],
                ],
            ],
            "SBV with a bad timestamp" => [
                "bad_timestamp.sbv",
                SbvParser::class,
                "The time \"0:00:0G.000\" is not valid.",
                [
                    [1, 3, "Good morning."],
                    [7, 9, "See you tomorrow."],
                ],
                [
                    [4, 1, self::SKIPPED, "The time \"0:00:0G.000\" is not valid."],
                ],
            ],
            "SBV with other separators and short fractions" => [
                "loose_timing.sbv",
                SbvParser::class,
                "Block #1 has no timing line on its first line.",
                [
                    [1, 3.5, "The ferry leaves at eight."],
                    [4, 6, "A dot between the times."],
                    [7, 9, "Commas before the fractions."],
                    [10.5, 12.25, "Short fractions."],
                ],
                [
                    [4, 1, self::REPAIRED, "Block #1 has a timing line with other separators or fraction digits than SBV uses. The parser read it."],
                    [7, 2, self::REPAIRED, "Block #2 has a timing line with other separators or fraction digits than SBV uses. The parser read it."],
                    [10, 3, self::REPAIRED, "Block #3 has a timing line with other separators or fraction digits than SBV uses. The parser read it."],
                ],
            ],
            "SBV without an empty line between cues" => [
                "missing_empty_line.sbv",
                SbvParser::class,
                3,
                [
                    [1, 2.5, "The wind is cold today."],
                    [3, 5, "Snow falls in the hills."],
                    [5.5, 7, "The roads are closed."],
                ],
                [
                    [3, 0, self::REPAIRED, "Block #0 has no empty line before line 3. The parser split the block there."],
                ],
            ],
            "SBV with text before the first cue" => [
                "text_before_first_cue.sbv",
                SbvParser::class,
                "The time \"Auto-generated\" is not valid.",
                [
                    [1, 3, "Clouds move in from the west."],
                    [4, 6, "Sun again by Friday."],
                ],
                [
                    [1, 0, self::SKIPPED, "The time \"Auto-generated\" is not valid."],
                ],
            ],
            "SBV with a truncated last cue" => [
                "truncated_last_cue.sbv",
                SbvParser::class,
                "The time \"0:00:0\" is not valid.",
                [
                    [1, 3, "The next stop is the station."],
                    [4, 6, "Please mind the gap."],
                ],
                [
                    [7, 2, self::SKIPPED, "The time \"0:00:0\" is not valid."],
                ],
            ],
            "SBV with mixed line endings" => [
                "mixed_line_endings.sbv",
                SbvParser::class,
                3,
                [
                    [1, 3, "The oven is hot."],
                    [4, 6, "The loaves rise."],
                    [7, 9, "The shop is open."],
                ],
                [],
            ],
            "MicroDVD with a release name and a line without frames" => [
                "release_name.sub",
                MicroDvdParser::class,
                "The frame rate is unknown. Set MicroDvdReadOptions::\$frameRate, start the file with {1}{1}<fps>, or read the file in lenient mode for 23.976 fps.",
                [
                    [1, 3, "The ferry leaves at noon."],
                    [5, 7, "<i>Tickets are sold on board.</i>"],
                ],
                [
                    [1, 0, self::SKIPPED, "The line \"Movie.Name.2003.DVDRip\" is not a MicroDVD cue."],
                    [4, 3, self::SKIPPED, "The line \"{x}{120}The deck is wet.\" is not a MicroDVD cue."],
                ],
            ],
            "ASS without a Format line, with a short event and a bad time" => [
                "broken_events.ass",
                AssParser::class,
                "The line \"Dialogue: 0,0:00:04.00,0:00:06.00,Default\" has fewer fields than the Format line of the [Events] section.",
                [
                    [1, 3, "The boats come in at dawn."],
                    [10, 12, "<i>Fish is sold at the pier.</i>"],
                ],
                [
                    [11, 1, self::SKIPPED, "The line \"Dialogue: 0,0:00:04.00,0:00:06.00,Default\" has fewer fields than the " .
                                           "Format line of the [Events] section."],
                    [12, 2, self::SKIPPED, "The time \"0:00:0x.00\" is not valid."],
                ],
            ],
            "SubViewer with text before the header and a bad time line" => [
                "bad_time_line.sub",
                SubViewerParser::class,
                "The line \"Downloaded from a subtitle site\" is neither a header tag nor a timing line.",
                [
                    [1, 3, "The market opens at eight."],
                    [7, 9, "The stalls close\nat noon."],
                ],
                [
                    [1, 0, self::SKIPPED, "The line \"Downloaded from a subtitle site\" is neither a header tag nor a timing line."],
                    [9, 1, self::SKIPPED, "The timing line \"00:00:04.00,00:00:0x.00\" has a time that is not valid."],
                ],
            ],
            "ASS with 4 fraction digits, no fraction, and a comma or colon before the fraction" => [
                "loose_times.ass",
                AssParser::class,
                "The time \"0:00:03.5004\" is not valid.",
                [
                    [1, 2, "The ovens warm up at four."],
                    [3.5, 4, "The first loaves come out at six."],
                    [5, 6, "Rye bread sells out first."],
                    [7.5, 8, "The shop opens at seven, not eight."],
                    [9.25, 10, "Close the door behind you."],
                ],
                [
                    [12, 1, self::REPAIRED, "The time \"0:00:03.5004\" is not in the form h:mm:ss.cc. The parser read it as 3.5 s."],
                    [12, 1, self::REPAIRED, "The time \"0:00:04.0000\" is not in the form h:mm:ss.cc. The parser read it as 4 s."],
                    [13, 2, self::REPAIRED, "The time \"0:00:05\" is not in the form h:mm:ss.cc. The parser read it as 5 s."],
                    [13, 2, self::REPAIRED, "The time \"0:00:06\" is not in the form h:mm:ss.cc. The parser read it as 6 s."],
                    [14, 3, self::REPAIRED, "The time \"0:00:07,50\" is not in the form h:mm:ss.cc. The parser read it as 7.5 s."],
                    [14, 3, self::REPAIRED, "The time \"0:00:08,00\" is not in the form h:mm:ss.cc. The parser read it as 8 s."],
                    [15, 4, self::REPAIRED, "The time \"0:00:09:25\" is not in the form h:mm:ss.cc. The parser read it as 9.25 s."],
                    [15, 4, self::REPAIRED, "The time \"0:00:10:00\" is not in the form h:mm:ss.cc. The parser read it as 10 s."],
                ],
            ],
            "SubViewer 2 with 4 fraction digits, no fraction and 1-digit fields" => [
                "loose_times.sub",
                SubViewerParser::class,
                1,
                [
                    [1, 2, "The ovens warm up at four."],
                    [3.5, 4, "The first loaves come out at six."],
                    [5, 6, "Rye bread sells out first."],
                    [7.5, 8, "The shop opens at seven."],
                ],
                [
                    [8, 1, self::REPAIRED, "The timing line \"00:00:03.5004,00:00:04.0000\" is not in the form hh:mm:ss.ff,hh:mm:ss.ff. " .
                                           "The parser read it as 3.5 s to 4 s."],
                    [11, 2, self::REPAIRED, "The timing line \"00:00:05,00:00:06\" is not in the form hh:mm:ss.ff,hh:mm:ss.ff. " .
                                            "The parser read it as 5 s to 6 s."],
                    [14, 3, self::REPAIRED, "The timing line \"0:0:7.50,0:0:8.00\" is not in the form hh:mm:ss.ff,hh:mm:ss.ff. " .
                                            "The parser read it as 7.5 s to 8 s."],
                ],
            ],
            "MPSub without a FORMAT line, with a bad timing line and a truncated last cue" => [
                "bad_timing_line.mpsub",
                MpSubParser::class,
                "The line \"1 x\" is not a header, a comment or a timing line.",
                [
                    [1, 3, "The bus leaves at ten."],
                    [5, 7, "Seats are free."],
                ],
                [
                    [4, 0, self::REPAIRED, "The file has no FORMAT line before line 4. The parser read the times as seconds."],
                    [7, 1, self::SKIPPED, "The line \"1 x\" is not a header, a comment or a timing line."],
                    [13, 3, self::SKIPPED, "The cue has no text lines."],
                ],
            ],
            "LRC with a broken time tag" => [
                "broken_time_tag.lrc",
                LyricsParser::class,
                3,
                [
                    [1, 4.5, "The sun comes up"],
                    [4.5, 9, "Birds sing in the trees"],
                    [9, 12, "We walk to the lake"],
                ],
                [
                    [6, 4, self::SKIPPED, "The line \"[01:2x.00]The path is long\" has a time tag that is not valid."],
                ],
            ],
            "SAMI with a SYNC tag without a Start time" => [
                "bad_sync_start.smi",
                SamiParser::class,
                "SYNC tag 3 has no valid Start attribute.",
                [
                    [1, 3, "Water the roses."],
                    [6, 8, "Pick the <i>beans</i>."],
                ],
                [
                    [14, 2, self::SKIPPED, "SYNC tag 3 has no valid Start attribute."],
                ],
            ],
            "TTML with a bad begin time and a paragraph without end" => [
                "bad_begin.ttml",
                TtmlParser::class,
                "The time expression \"00:00:0x.000\" is not valid.",
                [
                    [1, 3, "The library opens at nine."],
                    [7, 10, "Quiet, please."],
                    [10, 12, "<i>The reading room</i> is upstairs."],
                ],
                [
                    [6, 1, self::SKIPPED, "The time expression \"00:00:0x.000\" is not valid."],
                    [7, 2, self::REPAIRED, "The paragraph that begins at 7s has no end time. The parser ended it at the next paragraph at 10s."],
                ],
            ],
            "TTML with paragraphs without an end" => [
                "open_paragraphs.ttml",
                TtmlParser::class,
                "The paragraph that begins at 1s has no end time. (line 5)",
                [
                    [1, 3, "The ferry is late today."],
                    [3, 5, "It should arrive at noon."],
                    [5, 7, "Please wait inside."],
                    [7, 8, "Thank you."],
                ],
                [
                    [5, 0, self::REPAIRED, "The paragraph that begins at 1s has no end time. The parser ended it at the next paragraph at 3s."],
                    [6, 1, self::REPAIRED, "The paragraph that begins at 3s has no end time. The parser ended it at the next paragraph at 5s."],
                    [7, 2, self::REPAIRED, "The paragraph that begins at 5s has no end time. The parser ended it at the next paragraph at 7s."],
                    [9, 4, self::SKIPPED, "The paragraph that begins at 9s has no end time."],
                ],
            ],
            "TTML with a paragraph that begins after its timed div ends" => [
                "mantas_multiple_divs.ttml",
                TtmlParser::class,
                "The paragraph begins at 3.887s, but its parent ends at 2.423s. (line 12)",
                [
                    [1.464, 2.423, "The train to the coast\nleaves from platform four."],
                    [2.423, 5.432, "Please mind the gap."],
                    [10.886, 10.928, "BAKERY OPEN"],
                ],
                [
                    [12, 1, self::REPAIRED, "The paragraph begins at 3.887s, but its parent ends at 2.423s. The parser read its times as absolute: 2.423s to 5.432s."],
                ],
            ],
            "TTML with a credit line before the XML declaration" => [
                "credit_before_xml.ttml",
                TtmlParser::class,
                "The file has text before the XML: \"Subtitles by the harbour film club\".",
                [
                    [1, 3, "The boat leaves at noon."],
                    [7, 9, "Enjoy the trip."],
                ],
                [
                    [1, null, self::REPAIRED, "The file has text before the XML: \"Subtitles by the harbour film club\". The parser skipped it."],
                    [7, 1, self::SKIPPED, "The time expression \"00:00:0x.000\" is not valid."],
                ],
            ],
            "YouTube srv3 with a credit line before the XML declaration" => [
                "credit_before_xml.srv3",
                YouTubeTimedTextParser::class,
                "The file has text before the XML: \"Captions by the harbour film club\".",
                [
                    [1, 3, "The boat leaves at noon."],
                    [4, 6, "Enjoy the trip."],
                ],
                [
                    [1, null, self::REPAIRED, "The file has text before the XML: \"Captions by the harbour film club\". The parser skipped it."],
                ],
            ],
            "TTML with HTML entities" => [
                "html_entities.ttml",
                TtmlParser::class,
                "The file is not well-formed XML.",
                [
                    [1, 3, "Le caf\u{e9}\u{a0}ouvre \u{e0} huit heures."],
                    [4, 6, "Fish &amp; chips \u{2013} 5\u{a0}\u{20ac}"],
                    [7, 9, "\u{ab}\u{a0}Bon voyage\u{a0}\u{bb}"],
                ],
                [
                    [5, null, self::REPAIRED, "The file has HTML entities that XML does not define, such as \"&eacute;\". The parser read them as characters."],
                ],
            ],
            "EBU STL with a bad time code and a cut-off last block" => [
                "bad_time_code.stl",
                EbuStlParser::class,
                "The TTI blocks of an EBU STL file must have 128 bytes each.",
                [
                    [1, 3, "The bread is fresh."],
                    [7, 9, "We close at five."],
                ],
                [
                    [null, 3, self::SKIPPED, "The TTI blocks of an EBU STL file must have 128 bytes each."],
                    [null, 1, self::SKIPPED, "Subtitle number 2 has a time code that is not valid: 00000400 to 00000630."],
                ],
            ],
            "JSON with a cue without end and a line that is not a string" => [
                "missing_end.json",
                JsonParser::class,
                "The field cues[1].end must be a number.",
                [
                    [1, 3, "The train to the coast is late."],
                    [7, 9, "The buffet car is closed."],
                ],
                [
                    [null, 1, self::SKIPPED, "The field cues[1].end must be a number."],
                    [null, 3, self::SKIPPED, "The field cues[3].lines[1] must be a string."],
                ],
            ],
            "WebVTT with 20 hour digits" => [
                "absurd_hours.vtt",
                WebVttParser::class,
                "The time \"99999999999999999999:00:04.000\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [6, 2, self::SKIPPED, "The time \"99999999999999999999:00:04.000\" is not below 100000 hours."],
                ],
            ],
            "SBV with 20 hour digits" => [
                "absurd_hours.sbv",
                SbvParser::class,
                "The time \"99999999999999999999:00:04.000\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [4, 1, self::SKIPPED, "The time \"99999999999999999999:00:04.000\" is not below 100000 hours."],
                ],
            ],
            "ASS with 20 hour digits" => [
                "absurd_hours.ass",
                AssParser::class,
                "The time \"99999999999999999999:00:04.00\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [11, 1, self::SKIPPED, "The time \"99999999999999999999:00:04.00\" is not below 100000 hours."],
                ],
            ],
            "CSV with 20 hour digits" => [
                "absurd_hours.csv",
                CsvParser::class,
                "The time \"99999999999999999999:00:04.000\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [3, 1, self::SKIPPED, "The time \"99999999999999999999:00:04.000\" is not below 100000 hours."],
                ],
            ],
            "TTML with 20 hour digits and a huge offset time" => [
                "absurd_hours.ttml",
                TtmlParser::class,
                "The time \"99999999999999999999:00:04.000\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [10, 12, "The sauna is upstairs."],
                ],
                [
                    [6, 1, self::SKIPPED, "The time \"99999999999999999999:00:04.000\" is not below 100000 hours."],
                    [7, 2, self::SKIPPED, "The time \"99999999999999999999h\" is not below 100000 hours."],
                ],
            ],
            "SubViewer with 20 hour digits" => [
                "absurd_hours.sub",
                SubViewerParser::class,
                2,
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [8, 1, self::SKIPPED, "The timing line \"99999999999999999999:00:04.00,99999999999999999999:00:06.00\" has a time that is not valid."],
                ],
            ],
            "TMPlayer with 20 hour digits" => [
                "absurd_hours_tmplayer.txt",
                TmPlayerParser::class,
                "The time \"99999999999999999999:00:04\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 12, "No running, please."],
                ],
                [
                    [3, 2, self::SKIPPED, "The time \"99999999999999999999:00:04\" is not below 100000 hours."],
                ],
            ],
            "HTML transcript with 20 hour digits" => [
                "absurd_hours.html",
                HtmlTranscriptParser::class,
                "The time \"99999999999999999999:00:04\" is not below 100000 hours.",
                [
                    [1, 7, "The pool opens at seven."],
                    [7, 12, "No running, please."],
                ],
                [
                    [3, 1, self::SKIPPED, "The time \"99999999999999999999:00:04\" is not below 100000 hours."],
                ],
            ],
            "JSON with a start of 1e20 seconds" => [
                "absurd_seconds.json",
                JsonParser::class,
                "The field cues[1].start is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [null, 1, self::SKIPPED, "The field cues[1].start is not below 100000 hours."],
                ],
            ],
            "MicroDVD with a frame number of 13 digits" => [
                "absurd_frames_microdvd.sub",
                MicroDvdParser::class,
                "The time \"{9000000000000}\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [3, 2, self::SKIPPED, "The time \"{9000000000000}\" is not below 100000 hours."],
                ],
            ],
            "WebVTT with a word timestamp of 20 hour digits" => [
                "absurd_word_timestamp.vtt",
                WebVttParser::class,
                "The time \"99999999999999999999:00:05.000\" is not below 100000 hours.",
                [
                    [1, 3, "The pool opens <00:00:02.000>at seven."],
                    [7, 9, "No running, please."],
                ],
                [
                    [7, 2, self::SKIPPED, "The time \"99999999999999999999:00:05.000\" is not below 100000 hours."],
                ],
            ],
            "SubViewer 1 with a DELAY that moves a cue past the limit" => [
                "absurd_delay_subviewer.sub",
                SubViewerParser::class,
                "The time \"[00:00:12] with DELAY 359999990\" is not below 100000 hours.",
                [
                    [359999991, 359999994, "The pool opens at seven."],
                    [359999996, 359999999, "Towels are at the desk."],
                ],
                [
                    [14, 2, self::SKIPPED, "The time \"[00:00:12] with DELAY 359999990\" is not below 100000 hours."],
                ],
            ],
            "Whisper JSON with a segment without end" => [
                "missing_segment_end.whisper.json",
                WhisperJsonParser::class,
                "The field segments[1].end must be a number.",
                [
                    [0, 2.5, "The meeting starts at ten."],
                    [5, 7.5, "Coffee is in the kitchen."],
                ],
                [
                    [null, 1, self::SKIPPED, "The field segments[1].end must be a number."],
                ],
            ],
        ];
    }


    public static function streamedFiles(): array
    {
        $readers = [SubRipParser::class => SubRipStreamReader::class, WebVttParser::class => WebVttStreamReader::class];

        $cases = [];
        foreach (self::damagedFiles() as $name => [$file, $parserClass]) {
            if (isset($readers[$parserClass])) {
                $cases[$name] = [$file, $parserClass, $readers[$parserClass]];
            }
        }

        return $cases;
    }


    #[DataProvider("damagedFiles")]
    public function testLenientModeReturnsTheIntactCuesAndOneWarningPerProblem(
        string $file,
        string $parserClass,
        string|int $strictResult,
        array $expectedCues,
        array $expectedWarnings
    ): void {
        $parser   = new $parserClass();
        $subtitle = $parser->parse(file_get_contents(self::DIR . $file), new ReadOptions(lenient: true));

        $this->assertEquals($expectedCues, TestSubtitles::describe($subtitle));
        $this->assertSame($expectedWarnings, TestSubtitles::warnings($subtitle));
    }


    #[DataProvider("damagedFiles")]
    public function testStrictModeKeepsItsResult(
        string $file,
        string $parserClass,
        string|int $strictResult
    ): void {
        $content = file_get_contents(self::DIR . $file);
        if (is_int($strictResult)) {
            $subtitle = (new $parserClass())->parse($content, new ReadOptions());
            $this->assertCount($strictResult, $subtitle->getCues());
            $this->assertSame([], $subtitle->getParseWarnings());

            return;
        }

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($strictResult);
        (new $parserClass())->parse($content, new ReadOptions());
    }


    #[DataProvider("streamedFiles")]
    public function testStreamReaderReturnsTheCuesAndWarningsOfTheParser(string $file, string $parserClass, string $readerClass): void
    {
        $parser   = new $parserClass();
        $subtitle = $parser->parse(file_get_contents(self::DIR . $file), new ReadOptions(lenient: true));

        $reader = new $readerClass(new ReadOptions(lenient: true));
        $cues   = iterator_to_array($reader->read(self::DIR . $file));

        $this->assertSame(array_keys($cues), range(0, count($cues) - 1));
        $this->assertSame(TestSubtitles::describe($subtitle), TestSubtitles::describe($cues));
        $this->assertEquals($subtitle->getParseWarnings(), $reader->getWarnings());
    }


    public function testTheExampleOfTheIssueKeepsCuesOneAndThree(): void
    {
        $content = "1\n00:00:01,000 --> 00:00:04,000\nHello\n\n" .
                   "2\n00:00:05,000 => 00:00:07,000\nBroken arrow\n\n" .
                   "3\n00:00:08,000 --> 00:00:10,000\nStill fine\n";
        $subtitle = (new SubRipParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals([[1, 4, "Hello"], [8, 10, "Still fine"]], TestSubtitles::describe($subtitle));
        $this->assertEquals(
            [new ParseWarning(
                "Block #1 has no timing line on its second line. The line is \"00:00:05,000 => 00:00:07,000\".",
                6,
                1,
                ["2", "00:00:05,000 => 00:00:07,000", "Broken arrow"],
                ParseWarningAction::Skipped
            )],
            $subtitle->getParseWarnings()
        );
    }


    public function testFromStringStaysStrict(): void
    {
        $this->expectException(ParsingException::class);
        Subtitle::fromString(file_get_contents(self::DIR . "bad_timestamp.srt"), Format::SubRip);
    }


    public function testEachParseCallStartsWithoutWarnings(): void
    {
        $parser = new SubRipParser();
        $parser->parse(file_get_contents(self::DIR . "bad_timestamp.srt"), new ReadOptions(lenient: true));
        $subtitle = $parser->parse(file_get_contents(self::DIR . "mixed_line_endings.srt"), new ReadOptions(lenient: true));

        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testAStrictParseAfterALenientParseThrows(): void
    {
        $parser = new SbvParser();
        $parser->parse(file_get_contents(self::DIR . "bad_timestamp.sbv"), new ReadOptions(lenient: true));

        $this->expectException(ParsingException::class);
        $parser->parse(file_get_contents(self::DIR . "bad_timestamp.sbv"), new ReadOptions());
    }


    public function testAnEmptyFileGivesNoCuesAndNoWarning(): void
    {
        foreach ([new SubRipParser(), new SbvParser()] as $parser) {
            $subtitle = $parser->parse(" \n\n", new ReadOptions(lenient: true));

            $this->assertSame([], $subtitle->getCues());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    public function testWebVttWithoutTheSignatureAndWithASubRipTimingLineStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");
        (new WebVttParser())->parse("1\n00:00:01,000 --> 00:00:02,000\ntext\n", new ReadOptions(lenient: true));
    }


    public function testWebVttWithoutTheSignatureAndWithoutCuesStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");
        (new WebVttParser())->parse("Hello\n\nWorld\n", new ReadOptions(lenient: true));
    }


    public function testWebVttWithADamagedSignatureKeepsTheSkippedLinesInTheWarning(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "damaged_signature.vtt"), new ReadOptions(lenient: true));

        $this->assertSame(["WEBVTS", "Kind: captions"], $subtitle->getParseWarnings()[0]->block);
        $this->assertEquals([new Comment("closing time", 1)], $subtitle->getComments());
        $this->assertSame(["line" => "0", "alignment" => 8], $subtitle->getCues()[0]->findFormatData("vtt"));
    }


    public function testWebVttWithoutTheSignatureKeepsTheCueIdentifiers(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "missing_signature.vtt"), new ReadOptions(lenient: true));

        $this->assertSame(["1", "2"], array_map(fn (SubtitleCue $cue): ?string => $cue->getIdentifier(), $subtitle->getCues()));
    }


    public function testWebVttStreamReaderWithoutTheSignatureStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");
        iterator_to_array((new WebVttStreamReader(new ReadOptions(lenient: true)))->read(self::DIR . "bad_timestamp.srt"));
    }


    public function testWebVttLineNumbersCountTheEmptyLinesBeforeTheSignature(): void
    {
        $subtitle = (new WebVttParser())->parse("\n\nWEBVTT\n\nbroken\n\n00:00:01.000 --> 00:00:02.000\ntext\n", new ReadOptions(lenient: true));

        $this->assertSame(5, $subtitle->getParseWarnings()[0]->lineNumber);
    }


    public function testWebVttKeepsANoteAfterAnEmptyLineApartFromTheCue(): void
    {
        $content  = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\nThe ferry leaves.\n\nNOTE checked\n\nSTYLE\n::cue { color: red }\n";
        $subtitle = (new WebVttParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals([[1, 2, "The ferry leaves."]], TestSubtitles::describe($subtitle));
        $this->assertSame("checked", $subtitle->getComments()[0]->text);
        $this->assertCount(1, $subtitle->getParseWarnings());
    }


    public function testWebVttKeepsTheHeaderLinesBeforeTheSplit(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "missing_empty_line.vtt"), new ReadOptions(lenient: true));

        $this->assertSame(["headerLines" => ["Kind: captions", "Language: en"]], $subtitle->findFormatData("vtt"));
    }


    public function testSubViewerKeepsTheTextOfTheSkippedCueInTheWarning(): void
    {
        $subtitle = (new SubViewerParser())->parse(file_get_contents(self::DIR . "bad_time_line.sub"), new ReadOptions(lenient: true));

        $this->assertSame(["00:00:04.00,00:00:0x.00", "Apples are cheap today."], $subtitle->getParseWarnings()[1]->block);
    }


    public function testSubViewerStrictModeReadsABadTimeLineAsText(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.00,00:00:03.00\nOne\n\n00:00:04.00,00:00:0x.00\nTwo\n", new ReadOptions());

        $this->assertSame(["One", "00:00:04.00,00:00:0x.00", "Two"], $subtitle->getCues()[0]->getLines());
    }


    public static function looseAssTimes(): array
    {
        return [
            "4 fraction digits" => ["0:00:01.5000", 1.5],
            "no fraction"       => ["0:00:01", 1.0],
            "comma"             => ["0:00:01,50", 1.5],
            "colon"             => ["0:00:01:50", 1.5],
        ];
    }


    #[DataProvider("looseAssTimes")]
    public function testAssReadsALooseTimeOnlyInLenientMode(string $time, float $seconds): void
    {
        $content = "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" .
                   "Dialogue: 0,$time,0:00:03.00,Default,,0,0,0,,Hello\n";

        $subtitle = (new AssParser())->parse($content, new ReadOptions(lenient: true));
        $this->assertSame([[$seconds, 3.0, "Hello"]], TestSubtitles::describe($subtitle));
        $this->assertSame([self::REPAIRED], array_map(fn (ParseWarning $warning) => $warning->action, $subtitle->getParseWarnings()));
        $this->assertStringContainsString(sprintf("Dialogue: 0,0:00:%05.2f,0:00:03.00,", $seconds), $subtitle->toString(Format::Ass));

        $this->expectException(ParsingException::class);
        (new AssParser())->parse($content, new ReadOptions());
    }


    public static function looseSubViewerTimingLines(): array
    {
        return [
            "4 fraction digits" => ["00:00:01.5000,00:00:03.0000"],
            "no fraction"       => ["00:00:01.5,00:00:03"],
            "1-digit fields"    => ["0:0:1.50,0:0:3.00"],
        ];
    }


    #[DataProvider("looseSubViewerTimingLines")]
    public function testSubViewerReadsALooseTimingLineOnlyInLenientMode(string $line): void
    {
        $content = "00:00:00.00,00:00:01.00\nFirst\n\n$line\nHello\n";

        $subtitle = (new SubViewerParser())->parse($content, new ReadOptions(lenient: true));
        $this->assertSame([[0.0, 1.0, "First"], [1.5, 3.0, "Hello"]], TestSubtitles::describe($subtitle));
        $this->assertSame([self::REPAIRED], array_map(fn (ParseWarning $warning) => $warning->action, $subtitle->getParseWarnings()));
        $this->assertStringContainsString("00:00:01.50,00:00:03.00\nHello", $subtitle->toString(Format::SubViewer));

        $strict = (new SubViewerParser())->parse($content, new ReadOptions());
        $this->assertSame(["First", $line, "Hello"], $strict->getCues()[0]->getLines());
    }


    public function testSubViewer1SkipsABrokenHeaderLine(): void
    {
        $subtitle = (new SubViewerParser())->parse("[TITLE]\nMarket\nbroken\n" . SubViewerParser::START_SCRIPT . "\n[00:00:01]\nHello\n[00:00:02]\n", new ReadOptions(lenient: true));

        $this->assertSame("Hello", $subtitle->getCues()[0]->getText());
        $this->assertSame([[3, 0, self::SKIPPED, "The line \"broken\" is not a SubViewer 1 header tag."]], TestSubtitles::warnings($subtitle));
    }


    public function testMpSubSkipsABadFormatLineAndReadsTheTimesAsSeconds(): void
    {
        $subtitle = (new MpSubParser())->parse("FORMAT=PAL\n\n1 2\nHello\n", new ReadOptions(lenient: true));

        $this->assertEquals([[1, 3, "Hello"]], TestSubtitles::describe($subtitle));
        $this->assertSame([[1, 0, self::SKIPPED, "The FORMAT value \"PAL\" is not known."]], TestSubtitles::warnings($subtitle));
    }


    public function testSamiWarningHoldsTheLinesOfTheSkippedSync(): void
    {
        $subtitle = (new SamiParser())->parse(file_get_contents(self::DIR . "bad_sync_start.smi"), new ReadOptions(lenient: true));

        $this->assertSame(["<SYNC Start=><P Class=ENCC>Cut the grass."], $subtitle->getParseWarnings()[0]->block);
    }


    public function testTtmlWithMalformedXmlIsRepaired(): void
    {
        $subtitle = (new TtmlParser())->parse(file_get_contents(self::DIR . "malformed.ttml"), new ReadOptions(lenient: true));

        $this->assertEquals([
            [1, 3, "Salt &amp; pepper are on the table."],
            [4, 6, "Turn left\nat the bakery."],
            [7, 9, "Tickets cost 5 &amp;cur; at the door."],
            [10, 12, "The last bus leaves at ten."],
        ], TestSubtitles::describe($subtitle));

        // The text of the libxml error differs between libxml versions.
        $warnings = $subtitle->getParseWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(5, $warnings[0]->lineNumber);
        $this->assertNull($warnings[0]->blockIndex);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
        $this->assertStringStartsWith("The file is not well-formed XML. ", $warnings[0]->message);
        $this->assertStringEndsWith(". The parser repaired it.", $warnings[0]->message);
    }


    public function testTtmlWithMalformedXmlStillThrowsInStrictMode(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file is not well-formed XML.");
        (new TtmlParser())->parse(file_get_contents(self::DIR . "malformed.ttml"), new ReadOptions());
    }


    public function testTtmlWithHtmlEntitiesAndMalformedXmlGetsTwoWarnings(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body><div><p begin=\"1s\" end=\"2s\">Caf&eacute;&nbsp;ok</p>\n<p begin=\"3s\" end=\"4s\">A & B</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertEquals([[1, 2, "Caf\u{e9}\u{a0}ok"], [3, 4, "A &amp; B"]], TestSubtitles::describe($subtitle));
        $this->assertSame([2, 3], array_map(fn (ParseWarning $warning): ?int => $warning->lineNumber, $subtitle->getParseWarnings()));
    }


    #[DataProvider("unrecoverableTtml")]
    public function testTtmlThatLibxmlCannotRepairToATtRootStillThrows(string $content): void
    {
        $this->expectException(ParsingException::class);
        (new TtmlParser())->parse($content, new ReadOptions(lenient: true));
    }


    public static function unrecoverableTtml(): array
    {
        return [
            "no markup"       => ["Subtitles & captions"],
            "an HTML root"    => ["<html><body><p>Hello<br></body></html>"],
            "no root element" => ["<?xml version=\"1.0\"?>\n<!-- empty -->"],
        ];
    }


    public function testLenientModeSkipsAParagraphAfterItsTimedDivWithoutOwnEnd(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div begin=\"5s\" end=\"6s\">\n<p begin=\"2s\">Late</p>\n<p>On time</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertEquals([[5, 6, "On time"]], TestSubtitles::describe($subtitle));
        $this->assertSame([[2, 0, self::SKIPPED, "The paragraph begins at 7s, but its parent ends at 6s."]], TestSubtitles::warnings($subtitle));
    }


    #[DataProvider("looseTtmlTimes")]
    public function testLenientModeReadsLooseTtmlTimeExpressions(string $begin, float $seconds): void
    {
        $content  = "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div>\n<p begin=\"$begin\" end=\"00:00:30.000\">Text</p></div></body></tt>";
        $subtitle = (new TtmlParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertSame([[$seconds, 30.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame(
            [[2, 0, self::REPAIRED, "The time expression \"$begin\" is not valid. The parser read it as {$seconds}s."]],
            TestSubtitles::warnings($subtitle)
        );
    }


    #[DataProvider("looseTtmlTimes")]
    public function testStrictModeRejectsLooseTtmlTimeExpressions(string $begin): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time expression \"$begin\" is not valid.");
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"$begin\" end=\"00:00:30.000\">Text</p></div></body></tt>", new ReadOptions());
    }


    public static function looseTtmlTimes(): array
    {
        return [
            "one-digit seconds"         => ["00:00:7.250", 7.25],
            "one-digit hours"           => ["0:01:02.500", 62.5],
            "minutes and seconds"       => ["02:15.500", 135.5],
            "milliseconds without unit" => ["4200", 4.2],
            "frames with three digits"  => ["00:00:02:250", 2.25],
        ];
    }


    public function testLenientModeWarnsForALooseTtmlTimeOnADivWithoutAParagraphIndex(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body>\n<div begin=\"1000\"><p begin=\"1s\" end=\"2s\">Text</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[2.0, 3.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([[2, null, self::REPAIRED, "The time expression \"1000\" is not valid. The parser read it as 1s."]], TestSubtitles::warnings($subtitle));
    }


    #[DataProvider("highTtmlFrameLabels")]
    public function testLenientModeGuessesTheFrameRateOfTtmlWithHighFrameLabels(string $begin, string $end, float $start, float $stop, int $label, int $fps): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body><div><p begin=\"$begin\" end=\"$end\">Text</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[$start, $stop]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([[2, null, self::REPAIRED,
            "The frame label $label in \"" . ($label === (int) substr($begin, -2) ? $begin : $end) . "\" is not below the default frame rate of 30, and the file has no ttp:frameRate. The parser read the frames at $fps fps."]],
            TestSubtitles::warnings($subtitle));
    }


    public static function highTtmlFrameLabels(): array
    {
        return [
            "50 fps" => ["00:00:01:45", "00:00:02:10", 1.9, 2.2, 45, 50],
            "60 fps" => ["00:00:01:30", "00:00:02:55", 1.5, 2.917, 55, 60],
        ];
    }


    public function testStrictModeRejectsTtmlFrameLabelsAboveTheDefaultFrameRate(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame label 45 in \"00:00:01:45\" is not below the default frame rate of 30, and the file has no ttp:frameRate. (line 2)");
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body><div><p begin=\"00:00:01:45\" end=\"00:00:02:10\">Text</p></div></body></tt>", new ReadOptions());
    }


    public function testLenientModeReadsTtmlFrameLabelsOfSixtyOrMoreAsHundredths(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div>\n<p begin=\"00:00:01:75\" end=\"00:00:03:20\">Text</p>\n<p begin=\"00:00:04:05\" end=\"00:00:05.500\">More</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[1.75, 3.2], [4.05, 5.5]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([[2, null, self::REPAIRED,
            "The frame label 75 in \"00:00:01:75\" is not below the default frame rate of 30, and the file has no ttp:frameRate. The parser read the last field as hundredths of a second."]],
            TestSubtitles::warnings($subtitle));
    }


    public function testStrictModeRejectsTtmlFrameLabelsOfSixtyOrMore(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame label 75 in \"00:00:01:75\" is not below the default frame rate of 30, and the file has no ttp:frameRate.");
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"00:00:01:75\" end=\"00:00:03:00\">Text</p></div></body></tt>", new ReadOptions());
    }


    public function testTtmlFrameLabelsBelowThirtyKeepTheDefaultFrameRate(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"00:00:01:15\" end=\"00:00:02:29\">Text</p></div></body></tt>",
            new ReadOptions()
        );

        $this->assertSame([1.5, 2.967], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
    }


    public function testIttParserInheritsLenientMode(): void
    {
        $subtitle = (new IttParser())->parse(file_get_contents(self::DIR . "bad_begin.ttml"), new ReadOptions(lenient: true));

        $this->assertCount(3, $subtitle->getCues());
        $this->assertCount(2, $subtitle->getParseWarnings());
    }


    public function testEbuStlStrictModeKeepsACueWithABadTimeCode(): void
    {
        $content = substr(file_get_contents(self::DIR . "bad_time_code.stl"), 0, EbuStl::GSI_BLOCK_SIZE + 3 * EbuStl::TTI_BLOCK_SIZE);

        $this->assertCount(3, (new EbuStlParser())->parse($content, new ReadOptions())->getCues());
    }


    public function testJsonMovesTheCommentsToTheCueNumbersAfterTheSkip(): void
    {
        $subtitle = (new JsonParser())->parse(file_get_contents(self::DIR . "missing_end.json"), new ReadOptions(lenient: true));

        $this->assertEquals([new Comment("Platform changes", 1)], $subtitle->getComments());
        $this->assertSame(['{"start":4,"lines":["It leaves from platform two."]}'], $subtitle->getParseWarnings()[0]->block);
    }


    public function testJsonWithAnErrorOutsideTheCuesStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field version is missing.");
        (new JsonParser())->parse('{"cues": []}', new ReadOptions(lenient: true));
    }


    public function testWhisperCppSkipsASegmentWithoutOffsets(): void
    {
        $content = '{"transcription": [{"offsets": {"from": 0, "to": 2000}, "text": " Hello"}, {"text": " Lost"},' .
                   ' {"offsets": {"from": 3000, "to": 4000}, "text": " Bye"}]}';
        $subtitle = (new WhisperJsonParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals([[0, 2, "Hello"], [3, 4, "Bye"]], TestSubtitles::describe($subtitle));
        $this->assertSame([[null, 1, self::SKIPPED, "The field transcription[1].offsets.from must be a number."]], TestSubtitles::warnings($subtitle));
    }


    /**
     * Each case holds the parser, the content, the message, the cues in lenient mode and the line number and block of the warning.
     */
    public static function timesPastTheLimit(): array
    {
        return [
            "MPL2 with a deciseconds value of 14 digits" => [
                Mpl2Parser::class,
                "[10][30]The pool opens at seven.\n" .
                "[36000000000][36000000010]Towels are at the desk.\n" .
                "[70][90]No running, please.\n",
                "The time \"[36000000000]\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [2, 1],
            ],
            "MPSub with a wait of 100000 hours" => [
                MpSubParser::class,
                "FORMAT=TIME\n" .
                "\n" .
                "1 2\n" .
                "The pool opens at seven.\n" .
                "\n" .
                "360000000 2\n" .
                "Towels are at the desk.\n" .
                "\n" .
                "4 2\n" .
                "No running, please.\n",
                "The time \"360000000 2\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [6, 1],
            ],
            "SAMI with a Start of 15 digits" => [
                SamiParser::class,
                "<SAMI>\n" .
                "<BODY>\n" .
                "<SYNC Start=1000><P>The pool opens at seven.\n" .
                "<SYNC Start=3000><P>&nbsp;\n" .
                "<SYNC Start=900000000000000><P>Towels are at the desk.\n" .
                "<SYNC Start=7000><P>No running, please.\n" .
                "<SYNC Start=9000><P>&nbsp;\n" .
                "</BODY>\n" .
                "</SAMI>\n",
                "The time \"900000000000000\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [5, 2],
            ],
            "LRC with an offset that moves a line past the limit" => [
                LyricsParser::class,
                "[offset:-359999990000]\n" .
                "[00:01.00]The pool opens at seven.\n" .
                "[00:20.00]Towels are at the desk.\n",
                "The time \"[00:20.00]\" is not below 100000 hours.",
                [[359999991, 359999996, "The pool opens at seven."]],
                [NULL, 2],
            ],
            "ASS with a karaoke duration of 17 digits" => [
                AssParser::class,
                "[Script Info]\n" .
                "ScriptType: v4.00+\n" .
                "\n" .
                "[Events]\n" .
                "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" .
                "Dialogue: 0,0:00:01.00,0:00:03.00,Default,,0,0,0,,The pool opens at seven.\n" .
                "Dialogue: 0,0:00:04.00,0:00:06.00,Default,,0,0,0,,{\\k99999999999999999}Towels {\\k50}are at the desk.\n" .
                "Dialogue: 0,0:00:07.00,0:00:09.00,Default,,0,0,0,,No running, please.\n",
                "The time \"\\k99999999999999999\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [7, 1],
            ],
            "Whisper JSON with a word start of 1e20" => [
                WhisperJsonParser::class,
                '{"segments": [{"start": 1, "end": 3, "text": " The pool opens at seven."}, {"start": 4, "end": 6, "text": " Towels are at the desk.", "words": [{"word": " Towels", "start": 1e20, "end": 1e20}]}, {"start": 7, "end": 9, "text": " No running, please."}]}',
                "The field segments[1].words[0].start is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "whisper.cpp JSON with a token offset of 1e20" => [
                WhisperJsonParser::class,
                '{"transcription": [{"offsets": {"from": 1000, "to": 3000}, "text": " The pool opens at seven."}, {"offsets": {"from": 4000, "to": 6000}, "text": " Towels", "tokens": [{"text": " Towels", "offsets": {"from": 1e20, "to": 1e20}}]}, {"offsets": {"from": 7000, "to": 9000}, "text": " No running, please."}]}',
                "The field transcription[1].tokens[0].offsets.from is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "YouTube json3 with a segment offset of 1e20" => [
                YouTubeTimedTextParser::class,
                '{"events": [{"tStartMs": 1000, "dDurationMs": 2000, "segs": [{"utf8": "The pool opens at seven."}]}, {"tStartMs": 4000, "dDurationMs": 2000, "segs": [{"utf8": "Towels"}, {"utf8": " are at the desk.", "tOffsetMs": 1e20}]}, {"tStartMs": 7000, "dDurationMs": 2000, "segs": [{"utf8": "No running, please."}]}]}',
                "The field events[1].segs[1].tOffsetMs is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "YouTube srv3 with a duration of 18 digits" => [
                YouTubeTimedTextParser::class,
                "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n" .
                "<timedtext format=\"3\">\n" .
                "<body>\n" .
                "<p t=\"1000\" d=\"2000\">The pool opens at seven.</p>\n" .
                "<p t=\"4000\" d=\"900000000000000000\">Towels are at the desk.</p>\n" .
                "<p t=\"7000\" d=\"2000\">No running, please.</p>\n" .
                "</body>\n" .
                "</timedtext>\n",
                "The time \"900000000000000000\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [5, 1],
            ],
            "Podcast transcript with an end of 1e20" => [
                PodcastTranscriptParser::class,
                '{"version": "1.0.0", "segments": [{"startTime": 1, "endTime": 3, "body": "The pool opens at seven."}, {"startTime": 4, "endTime": 1e20, "body": "Towels are at the desk."}, {"startTime": 7, "endTime": 9, "body": "No running, please."}]}',
                "The field segments[1].endTime is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "AssemblyAI with a word end of 1e20 milliseconds" => [
                AssemblyAiParser::class,
                '{"words": [{"text": "Pool.", "start": 1000, "end": 3000}, {"text": "Towels.", "start": 4000, "end": 1e20}, {"text": "Running.", "start": 7000, "end": 9000}]}',
                "The field words[1].end is not below 100000 hours.",
                [[1, 3, "Pool."], [7, 9, "Running."]],
                [NULL, 1],
            ],
            "Google Speech with a result end of 1e20 seconds" => [
                GoogleSpeechParser::class,
                '{"results": [{"alternatives": [{"transcript": "Pool.", "words": [{"word": "Pool.", "startTime": "1s", "endTime": "3s"}]}]}, {"alternatives": [{"transcript": "Towels."}], "resultEndTime": "100000000000000000000s"}, {"alternatives": [{"transcript": "Running.", "words": [{"word": "Running.", "startTime": "7s", "endTime": "9s"}]}]}]}',
                "The field results[1].resultEndTime is not below 100000 hours.",
                [[1, 3, "Pool."], [7, 9, "Running."]],
                [NULL, 1],
            ],
            "TTML with a div and a p that begin at 99999 hours each" => [
                TtmlParser::class,
                "<tt xmlns=\"http://www.w3.org/ns/ttml\">\n" .
                "<body>\n" .
                "<div>\n" .
                "<p begin=\"1s\" end=\"3s\">The pool opens at seven.</p>\n" .
                "</div>\n" .
                "<div begin=\"99999h\">\n" .
                "<p begin=\"99999h\" end=\"99999h\">Towels are at the desk.</p>\n" .
                "</div>\n" .
                "<div>\n" .
                "<p begin=\"7s\" end=\"9s\">No running, please.</p>\n" .
                "</div>\n" .
                "</body>\n" .
                "</tt>\n",
                "The time \"99999h\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [7, 1],
            ],
        ];
    }


    #[DataProvider("timesPastTheLimit")]
    public function testATimeOf100000HoursOrMoreThrowsOrSkipsTheCue(
        string $parserClass,
        string $content,
        string $message,
        array $expectedCues,
        array $warningPlace
    ): void {
        $subtitle = (new $parserClass())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals($expectedCues, TestSubtitles::describe($subtitle));
        $this->assertSame([[...$warningPlace, self::SKIPPED, $message]], TestSubtitles::warnings($subtitle));

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);
        (new $parserClass())->parse($content, new ReadOptions());
    }


    public function testParsersWithoutLenientModeStillThrow(): void
    {
        $this->expectException(ParsingException::class);
        (new SccParser())->parse("Scenarist_SCC V1.0\n\nbroken\n", new ReadOptions(lenient: true));
    }


}
