<?php

declare(strict_types=1);

// Writes the OCR fixtures to tests/files/fixing/. Run from the repository root: php tests/files/fixing/generate.php

namespace SubtitleToolbox\Fixing;

use GlyphOcr\GlyphDatabase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Ocr\TextBitmap;
use SubtitleToolbox\Parsers\PgsFixtures;
use SubtitleToolbox\Parsers\PgsFixtureWriter;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

require_once __DIR__ . "/../pgs/generator/PgsFixtures.php";

/** Language => the lines of each cue. Each cue lasts 2.5 s, and the next cue starts 0.5 s later. */
const OCR_CUES = [
    "en" => [
        ["I'm sure I'll be late.", "It's the bus again."],
        ["If I can, I will call you at 9."],
        ["-Is it open?", "-I think so."],
        ["DON'T LEAVE IT IN THE CAR."],
        ["I've seen it. I'd go again."],
        ["Wait . . . Really ?"],
    ],
    "de" => [
        ["Ich bin um 8 Uhr im Büro."],
        ["Ist das Ihr Schirm?", "In der Ecke."],
        ["ICH KOMME MORGEN."],
    ],
    "fr" => [
        ["Il pleut. Ils restent à la maison."],
        ["Il cherche l'hôtel.", "Il est où ?"],
    ],
    "es" => [
        ["¿Ir a la isla?", "Isabel llega hoy."],
        ["Iván llama a las 9."],
    ],
];


/**
 * White Liberation Sans text at 52 px on a 1920x1080 screen, one PGS display set per cue of OCR_CUES[$language].
 */
function ocrFixture(string $language): string
{
    $w = new PgsFixtureWriter();
    foreach (OCR_CUES[$language] as $index => $lines) {
        [$pixels, $palette, $width, $height] = PgsFixtures::textObject(TextBitmap::render($lines, 52, 52 / 16, 2),
                                                                       [255, 255, 255]);
        $x        = intdiv(1920 - $width, 2);
        $y        = 1020 - $height;
        $startPts = 90000 + 270000 * $index;
        $endPts   = $startPts + 225000;
        $w->presentation($startPts, 1920, 1080, 2 * $index, PgsFixtureWriter::STATE_EPOCH_START, 0,
                         [["id" => 0, "window" => 0, "x" => $x, "y" => $y]])
          ->windows($startPts, [0 => [$x, $y, $width, $height]])
          ->palette($startPts, 0, 0, $palette)
          ->object($startPts, 0, 0, $width, $height, $pixels)
          ->end($startPts)
          ->presentation($endPts, 1920, 1080, 2 * $index + 1, PgsFixtureWriter::STATE_NORMAL, 0, [])
          ->windows($endPts, [0 => [$x, $y, $width, $height]])
          ->end($endPts);
    }

    return $w->bytes();
}


/**
 * Reads the image cues as php-glyph-ocr 0.1 did, with the Latin database and without line context. So the text
 * keeps the I and l errors that CommonErrorFixer fixes.
 */
function ocrWithErrors(Subtitle $subtitle): string
{
    $engine = new GlyphOcrEngine(new GlyphOcrOptions(GlyphDatabase::latin(), lineContext: false));

    return $subtitle->recognizeText($engine)->toString(Format::SubRip);
}


/**
 * Returns the image subtitles of the other fixture folders that the fixing tests read, keyed by the name of
 * their OCR file here.
 *
 * @return array<string, Subtitle>
 */
function imageFixtures(): array
{
    $files = __DIR__ . "/..";

    return [
        "text_1080p.ocr.srt" => (new PgsParser())->parse(file_get_contents("$files/pgs/text_1080p.sup"), new ReadOptions()),
        "text-pal.ocr.srt"   => (new VobSubParser())
            ->parse(file_get_contents("$files/vobsub/text-pal.sub"), new ReadOptions(formats: [new VobSubReadOptions(file_get_contents("$files/vobsub/text-pal.idx"))])),
    ];
}


if (realpath($_SERVER["SCRIPT_FILENAME"] ?? "") !== __FILE__) {
    return;
}

require_once __DIR__ . "/../../../vendor/autoload.php";

foreach (array_keys(OCR_CUES) as $language) {
    $sup = ocrFixture($language);
    file_put_contents(__DIR__ . "/ocr-$language.sup", $sup);
    file_put_contents(__DIR__ . "/ocr-$language.ocr.srt", ocrWithErrors((new PgsParser())->parse($sup, new ReadOptions())));
    echo "Wrote ocr-$language.sup and ocr-$language.ocr.srt\n";
}
foreach (imageFixtures() as $name => $subtitle) {
    file_put_contents(__DIR__ . "/$name", ocrWithErrors($subtitle));
    echo "Wrote $name\n";
}
