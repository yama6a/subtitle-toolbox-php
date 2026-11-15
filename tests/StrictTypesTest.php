<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class StrictTypesTest extends TestCase
{
    public function testEveryPhpFileDeclaresStrictTypes(): void
    {
        $root = dirname(__DIR__);
        $checked = 0;
        $missing = [];
        foreach (["src", "tests"] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== "php") {
                    continue;
                }
                $checked++;
                if (preg_match('/\A<\?php\n\ndeclare\(strict_types=1\);\n/', file_get_contents($file->getPathname())) !== 1) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertGreaterThan(300, $checked);
        $this->assertSame([], $missing, "These files must start with <?php, a blank line and declare(strict_types=1);");
    }
}
