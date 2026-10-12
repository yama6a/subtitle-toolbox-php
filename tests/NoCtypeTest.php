<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class NoCtypeTest extends TestCase
{
    public function testNoSourceFileCallsACtypeFunction(): void
    {
        $root  = dirname(__DIR__);
        $calls = [];
        foreach (["src", "bin"] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS)) as $file) {
                foreach (file($file->getPathname()) ?: [] as $index => $line) {
                    if (preg_match('/\bctype_[a-z]+\s*\(/', $line) === 1) {
                        $calls[] = substr($file->getPathname(), strlen($root) + 1) . ":" . ($index + 1);
                    }
                }
            }
        }

        $this->assertSame([], $calls, "composer.json does not require ext-ctype, so these calls fail on a PHP build without it");
    }
}
