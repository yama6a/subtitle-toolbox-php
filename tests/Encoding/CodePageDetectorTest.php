<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodePageDetectorTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/encoding/";


    /**
     * The UTF-16 files are missing, because the library decodes UTF-16 before it calls the detector.
     */
    public static function encodingFiles(): array
    {
        return [
            "arabic-utf-8.srt"                 => ["arabic-utf-8.srt", null],
            "arabic-windows-1256.srt"          => ["arabic-windows-1256.srt", "Windows-1256"],
            "czech-windows-1250.srt"           => ["czech-windows-1250.srt", "Windows-1250"],
            "french-windows-1252.srt"          => ["french-windows-1252.srt", "Windows-1252"],
            "greek-windows-1253.srt"           => ["greek-windows-1253.srt", "Windows-1253"],
            "invalid-utf-8/one-bad-byte.ass"   => ["invalid-utf-8/one-bad-byte.ass", null],
            "invalid-utf-8/one-bad-byte.csv"   => ["invalid-utf-8/one-bad-byte.csv", null],
            "invalid-utf-8/one-bad-byte.json3" => ["invalid-utf-8/one-bad-byte.json3", null],
            "invalid-utf-8/one-bad-byte.sbv"   => ["invalid-utf-8/one-bad-byte.sbv", null],
            "invalid-utf-8/one-bad-byte.smi"   => ["invalid-utf-8/one-bad-byte.smi", null],
            "invalid-utf-8/one-bad-byte.srt"   => ["invalid-utf-8/one-bad-byte.srt", null],
            "invalid-utf-8/one-bad-byte.srv1"  => ["invalid-utf-8/one-bad-byte.srv1", null],
            "invalid-utf-8/one-bad-byte.sub"   => ["invalid-utf-8/one-bad-byte.sub", null],
            "invalid-utf-8/one-bad-byte.ttml"  => ["invalid-utf-8/one-bad-byte.ttml", null],
            "invalid-utf-8/one-bad-byte.vtt"   => ["invalid-utf-8/one-bad-byte.vtt", null],
            "japanese-shift_jis.srt"           => ["japanese-shift_jis.srt", null],
            "korean-cp949.smi"                 => ["korean-cp949.smi", null],
            "polish-iso-8859-2.srt"            => ["polish-iso-8859-2.srt", "ISO-8859-2"],
            "russian-koi8-r.srt"               => ["russian-koi8-r.srt", "KOI8-R"],
            "russian-windows-1251.srt"         => ["russian-windows-1251.srt", "Windows-1251"],
            "turkish-windows-1254.srt"         => ["turkish-windows-1254.srt", "Windows-1254"],
            "utf-8-declared-utf-16.ttml"       => ["utf-8-declared-utf-16.ttml", null],
        ];
    }


    #[DataProvider("encodingFiles")]
    public function testDetectsTheCodePageOfTheFile(string $file, ?string $codePage): void
    {
        $this->assertSame($codePage, CodePageDetector::detect(file_get_contents(self::DIR . $file))[0] ?? null);
    }
}
