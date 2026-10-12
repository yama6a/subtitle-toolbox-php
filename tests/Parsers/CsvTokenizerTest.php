<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class CsvTokenizerTest extends TestCase
{
    /**
     * @return array<string, array{string, list<array{line: int, cells: list<string>, quoted: list<int>}>}>
     */
    public static function contents(): array
    {
        return [
            "quoted cells"               => ["\"a\",b,\"\"\n", [["line" => 1, "cells" => ["a", "b", ""], "quoted" => [0, 2]]]],
            "CR LF inside quotes"        => ["a,\"b\r\nc\"\r\nd,e\r\n", [["line" => 1, "cells" => ["a", "b\nc"], "quoted" => [1]], ["line" => 3, "cells" => ["d", "e"], "quoted" => []]]],
            "doubled quote"              => ["\"say \"\"hi\"\"\",x\n", [["line" => 1, "cells" => ["say \"hi\"", "x"], "quoted" => [0]]]],
            "quote inside unquoted cell" => ["5\" disk,x\n", [["line" => 1, "cells" => ["5\" disk", "x"], "quoted" => []]]],
            "text after a closing quote" => ["\"ab\"cd,x\n", [["line" => 1, "cells" => ["abcd", "x"], "quoted" => [0]]]],
            "missing last line break"    => ["a,b\nc,d", [["line" => 1, "cells" => ["a", "b"], "quoted" => []], ["line" => 2, "cells" => ["c", "d"], "quoted" => []]]],
            "empty last cell"            => ["a,\n", [["line" => 1, "cells" => ["a", ""], "quoted" => []]]],
            "lone CR ends a record"      => ["a\rb\r", [["line" => 1, "cells" => ["a"], "quoted" => []], ["line" => 2, "cells" => ["b"], "quoted" => []]]],
        ];
    }


    #[DataProvider("contents")]
    public function testSplitsRecords(string $content, array $records): void
    {
        $this->assertSame($records, (new CsvTokenizer())->records($content, ",", false));
    }


    public function testStrictModeThrowsForAnUnclosedQuote(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A quoted CSV cell has no closing quote. (line 2)");

        (new CsvTokenizer())->records("a,b\nc,\"d,e\nf,g\n", ",", false);
    }


    public function testLenientModeEndsAnUnclosedQuoteAtTheLineEnd(): void
    {
        $tokenizer = new CsvTokenizer();

        $this->assertSame(
            [["line" => 1, "cells" => ["a", "b"], "quoted" => []], ["line" => 2, "cells" => ["c", "d,e"], "quoted" => []], ["line" => 3, "cells" => ["f", "g"], "quoted" => []]],
            $tokenizer->records("a,b\nc,\"d,e\nf,g\n", ",", true)
        );
        $this->assertSame([["line" => 2, "text" => "c,\"d,e"]], $tokenizer->unclosedQuotes());
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function delimiterLines(): array
    {
        return [
            "comma wins a tie"          => ["a,b;c\n", ","],
            "semicolon by count"        => ["a;b;c,d\n", ";"],
            "tab by count"              => ["a\tb\tc\n", "\t"],
            "quoted delimiters ignored" => ["\"a;b;c\",d\n", ","],
            "first line with one"       => ["\nstart;end\n", ";"],
        ];
    }


    #[DataProvider("delimiterLines")]
    public function testDetectsTheDelimiter(string $content, string $delimiter): void
    {
        $this->assertSame($delimiter, CsvTokenizer::detectDelimiter($content));
    }
}
