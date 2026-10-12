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
        foreach (["src", "tests", "bin", ".build"] as $directory) {
            if (!is_dir("$root/$directory")) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS)) as $file) {
                $content = file_get_contents($file->getPathname());
                // bin/stbox has no .php extension, so a PHP shebang or opening tag also marks a PHP file.
                if ($file->getExtension() !== "php" && preg_match('/\A(?:#!.*php.*\n)?<\?php\b/', $content) !== 1) {
                    continue;
                }
                $checked++;
                if (preg_match('/\A(?:#!.*\n)?<\?php\n\ndeclare\(strict_types=1\);\n/', $content) !== 1) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertGreaterThan(300, $checked);
        $this->assertSame([], $missing, "These files must start with <?php, a blank line and declare(strict_types=1);");
    }
}
