<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;

class OcrReplaceListTest extends TestCase
{
    public function testReadsTheSectionsOfASubtitleEditUserFile(): void
    {
        $list = OcrReplaceList::fromSubtitleEditXml(file_get_contents(__DIR__ . "/../files/fixing/user_OCRFixReplaceList.xml"));

        $this->assertSame(["celd" => "cold", "oD" => "on", "trom" => "from"], $list->wholeWords);
        $this->assertSame(["iD" => "in"], $list->partialWordsAlways);
        $this->assertSame(["Ple*se kee* you* *icket." => "Please keep your ticket."], $list->wholeLines);
        $this->assertSame(["T*e " => "The "], $list->beginLines);
        $this->assertSame([" deck'" => " deck."], $list->endLines);
        $this->assertSame(["*'*." => "p.m."], $list->partialLines);
        $this->assertSame(["Pe* hou*" => "per hour"], $list->partialLinesAlways);
        $this->assertSame(["/(\\p{L})'$/u" => "$1.", "/\\b(\\d+) k\\*/u" => "$1 km", "/x/u" => '\\${name}'], $list->regularExpressions);
    }


    public function testConvertsDotNetReplacementsAndSkipsWhatPcreCannotRun(): void
    {
        $list = OcrReplaceList::fromSubtitleEditXml(
            "<OCRFixReplaceList><RegularExpressions>" .
            '<RegEx find="(a)/b" replaceWith="$$ $&amp; \ ${1}" />' .
            '<RegEx find="(?&lt;x&gt;c)" replaceWith="${x}" />' .
            "<RegEx find='[' replaceWith='' />" .
            "<RegEx find='' replaceWith='e' />" .
            "<RegEx find='d' />" .
            "<RegEx find='(a)/b' replaceWith='second' />" .
            "</RegularExpressions></OCRFixReplaceList>"
        );

        $this->assertSame(["~(a)/b~u" => "\\$ \$0 \\\\ \${1}", "/(?<x>c)/u" => '${x}'], $list->regularExpressions);
        $this->assertSame("$ a/b \\ a x", preg_replace("~(a)/b~u", $list->regularExpressions["~(a)/b~u"], "a/b x"));
    }


    public function testKeepsTheFirstEntryOfEachSearchTextAndSkipsEntriesWithoutChange(): void
    {
        $list = OcrReplaceList::fromSubtitleEditXml(
            "<ReplaceList><WholeWords><Word from='Teh' to='The' /><Word from='Teh' to='Ten' /><Word from='keep' to='keep' />" .
            "<Word from='' to='x' /><Word to='y' /><!-- note --></WholeWords><WholeWords><Word from='lt' to='It' /></WholeWords>" .
            "<RemovedWholeWords><Word from='lt' to='' /></RemovedWholeWords><PartialWords><WordPart from='1' to='l' /></PartialWords>" .
            "</ReplaceList>"
        );

        $this->assertSame(["Teh" => "The", "lt" => "It"], $list->wholeWords);
        $this->assertSame([], $list->partialWordsAlways);
    }


    public function testReadsRawIso88591BytesWithAMatchingDeclaration(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n<ReplaceList><WholeWords><Word from='caf\u{e8}' to='caf\u{e9}' /></WholeWords></ReplaceList>";

        $list = OcrReplaceList::fromSubtitleEditXml(mb_convert_encoding($xml, "ISO-8859-1", "UTF-8"));

        $this->assertSame(["caf\u{e8}" => "caf\u{e9}"], $list->wholeWords);
    }


    public function testRejectsInvalidXmlWithTheLineNumber(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessageMatches('/The OCR replace list is not valid XML: .+ \(line 2\)$/');

        OcrReplaceList::fromSubtitleEditXml("<ReplaceList>\n<WholeWords></ReplaceList>");
    }


    public function testRejectsAnEmptyString(): void
    {
        $this->expectException(ParsingException::class);

        OcrReplaceList::fromSubtitleEditXml("");
    }


    public function testRejectsAnInvalidRegularExpressionInTheConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The regular expression \"/[/u\" is not valid");

        new OcrReplaceList(regularExpressions: ["/[/u" => ""]);
    }


    public function testKeepsTheDotNetSubstitutionsOfTheSubject(): void
    {
        $list = OcrReplaceList::fromSubtitleEditXml(
            "<ReplaceList><RegularExpressions>" .
            '<RegEx find="x" replaceWith="[${name}] [$`] [$\'] [$+] [$_] [$$`]" />' .
            "</RegularExpressions></ReplaceList>"
        );

        $this->assertSame(["/x/u" => '[\\${name}] [$`] [$\'] [$+] [$_] [\\$`]'], $list->regularExpressions);
    }
}
