<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class UnusedImportsTest extends TestCase
{
    public function testNoPhpFileHasAnUnusedImport(): void
    {
        $root = dirname(__DIR__);
        $checked = 0;
        $unused = [];
        foreach (["src", "tests"] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== "php") {
                    continue;
                }
                $checked++;
                foreach (self::unusedImports(file_get_contents($file->getPathname())) as $import) {
                    $unused[] = substr($file->getPathname(), strlen($root) + 1) . ": $import";
                }
            }
        }

        $this->assertGreaterThan(300, $checked);
        $this->assertSame([], $unused, "These imports are never used");
    }

    public function testDetectsUnusedImportsOfEveryKind(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Demo;
            use A\UsedClass;
            use A\UnusedClass;
            use A\{GroupUsed, GroupUnused as Alias};
            use function A\used_function, A\unused_function;
            use const A\USED_CONST;
            use const A\UNUSED_CONST;
            use A\DocParam, A\DocReturn, A\DocVar, A\DocThrows, A\DocSee, A\DocLink, A\DocGeneric, A\PhpstanType, A\InProse;
            use A\SomeTrait;
            use A\MethodName;

            /**
             * Mentions InProse in a sentence.
             * @phpstan-type Row array{cue: PhpstanType, n: int}
             * @see DocSee::run()
             */
            class Demo extends UsedClass
            {
                use SomeTrait;

                /**
                 * @param DocParam $a and {@link DocLink}
                 * @param array<int, DocGeneric> $b
                 * @return DocReturn|null
                 * @throws DocThrows
                 */
                public function run($a, $b): mixed
                {
                    /** @var DocVar $c */
                    $c = used_function(USED_CONST, new GroupUsed());
                    return $c->MethodName();
                }
            }
            PHP;

        $this->assertSame(
            ["A\\UnusedClass", "A\\GroupUnused", "function A\\unused_function", "const A\\UNUSED_CONST", "A\\InProse", "A\\MethodName"],
            self::unusedImports($code),
        );
    }

    /**
     * @return list<string>
     */
    private static function unusedImports(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT], true),
        ));

        $imports = [];
        $importTokens = [];
        $depth = 0;
        $namespaceDepth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === "{" || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === "}") {
                $depth--;
            } elseif (is_array($token) && $token[0] === T_NAMESPACE) {
                $end = $i + 1;
                while ($end < $count && $tokens[$end] !== "{" && $tokens[$end] !== ";") {
                    $end++;
                }
                $namespaceDepth = ($tokens[$end] ?? null) === "{" ? $depth + 1 : 0;
            } elseif (is_array($token) && $token[0] === T_USE && $depth === $namespaceDepth) {
                $end = $i;
                while ($tokens[$end] !== ";") {
                    $importTokens[$end] = true;
                    $end++;
                }
                $importTokens[$end] = true;
                array_push($imports, ...self::parseUse(array_slice($tokens, $i + 1, $end - $i - 1)));
                $i = $end;
            }
        }

        $classes = [];
        $names = [];
        $previous = null;
        foreach ($tokens as $index => $token) {
            if (isset($importTokens[$index])) {
                continue;
            }
            if (is_array($token)) {
                if ($token[0] === T_DOC_COMMENT) {
                    foreach (self::docblockClasses($token[1]) as $class) {
                        $classes[strtolower($class)] = true;
                    }
                } elseif ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED) {
                    $afterMember = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
                    if (!$afterMember) {
                        $first = explode("\\", $token[1])[0];
                        $classes[strtolower($first)] = true;
                        $names[$token[1]] = true;
                    }
                }
            }
            $previous = $token;
        }

        $unused = [];
        foreach ($imports as [$kind, $name, $alias]) {
            $used = match ($kind) {
                "class" => isset($classes[strtolower($alias)]),
                "function" => isset(array_change_key_case($names)[strtolower($alias)]),
                "const" => isset($names[$alias]),
            };
            if (!$used) {
                $unused[] = ($kind === "class" ? "" : "$kind ") . $name;
            }
        }

        return $unused;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<array{string, string, string}>
     */
    private static function parseUse(array $tokens): array
    {
        $kind = "class";
        if (is_array($tokens[0]) && in_array($tokens[0][0], [T_FUNCTION, T_CONST], true)) {
            $kind = $tokens[0][0] === T_FUNCTION ? "function" : "const";
            array_shift($tokens);
        }

        $imports = [];
        $prefix = "";
        $itemKind = $kind;
        $name = "";
        $alias = null;
        $expectAlias = false;
        $flush = function () use (&$imports, &$prefix, &$itemKind, &$name, &$alias, $kind): void {
            if ($name !== "") {
                $full = ltrim($prefix . $name, "\\");
                $parts = explode("\\", $full);
                $imports[] = [$itemKind, $full, $alias ?? end($parts)];
            }
            $itemKind = $kind;
            $name = "";
            $alias = null;
        };
        foreach ($tokens as $token) {
            if ($token === "{") {
                $prefix = $name;
                $name = "";
            } elseif ($token === "," || $token === "}") {
                $flush();
            } elseif (is_array($token) && $token[0] === T_AS) {
                $expectAlias = true;
            } elseif (is_array($token) && in_array($token[0], [T_FUNCTION, T_CONST], true)) {
                $itemKind = $token[0] === T_FUNCTION ? "function" : "const";
            } elseif (is_array($token) && $expectAlias) {
                $alias = $token[1];
                $expectAlias = false;
            } elseif (is_array($token)) {
                $name .= $token[1];
            }
        }
        $flush();

        return $imports;
    }

    /**
     * @return list<string>
     */
    private static function docblockClasses(string $docblock): array
    {
        $classes = [];
        preg_match_all('/@[\w-]+/', $docblock, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $offset]) {
            $rest = ltrim(substr($docblock, $offset + strlen($tag)), " \t");
            $wholeLine = preg_match('/^@(?:method|[\w]+-type|[\w]+-import-type)$/', $tag) === 1;
            $expression = $wholeLine ? strtok($rest, "\n") : self::typeExpression($rest);
            preg_match_all('/(?<![\w\\\\$:>-])[A-Za-z_]\w*/', (string) $expression, $words);
            array_push($classes, ...$words[0]);
        }

        return $classes;
    }

    private static function typeExpression(string $text): string
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (str_contains("<({[", $char)) {
                $depth++;
            } elseif (str_contains(">)}]", $char)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif (ctype_space($char) && $depth === 0) {
                break;
            }
        }

        return substr($text, 0, $i);
    }
}
