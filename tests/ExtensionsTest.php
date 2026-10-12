<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class ExtensionsTest extends TestCase
{
    private const ALWAYS_ENABLED = ["Core", "date", "hash", "json", "pcre", "Reflection", "SPL", "standard"];

    // ext-dom needs libxml. Dependency::check() guards the zlib and curl calls.
    private const OTHER_ALLOWED = ["libxml", "zlib", "curl"];


    public function testEveryFunctionThatSrcCallsComesFromARequiredOrGuardedExtension(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__) . "/composer.json"), true);
        $required = array_map(fn (string $name): string => substr($name, 4),
                              array_filter(array_keys($composer["require"]), fn (string $name): bool => str_starts_with($name, "ext-")));
        $allowed  = array_map("strtolower", [...self::ALWAYS_ENABLED, ...self::OTHER_ALLOWED, ...$required]);

        $calls = [];
        foreach ($this->calledFunctions() as $function => $file) {
            $extension = (new \ReflectionFunction($function))->getExtensionName();
            if (!in_array(strtolower((string)$extension), $allowed, true)) {
                $calls[] = "$file: $function() of ext-$extension";
            }
        }

        $this->assertSame([], $calls);
    }


    /**
     * @return array<string, string> each global function that a file in src calls, with the first file
     */
    private function calledFunctions(): array
    {
        $src       = dirname(__DIR__) . "/src/";
        $functions = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $tokens = array_values(array_filter(\PhpToken::tokenize((string)file_get_contents($file->getPathname())),
                                                fn (\PhpToken $token): bool => !$token->isIgnorable()));
            foreach ($tokens as $index => $token) {
                $name = ltrim($token->text, "\\");
                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ($tokens[$index + 1]->text ?? "") === "("
                    && !($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])
                    && function_exists($name)) {
                    $functions[$name] ??= substr($file->getPathname(), strlen($src));
                }
            }
        }

        return $functions;
    }
}
