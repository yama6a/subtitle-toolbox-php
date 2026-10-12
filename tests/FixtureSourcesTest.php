<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class FixtureSourcesTest extends TestCase
{
    private const DIR = __DIR__ . "/files";


    /**
     * A file counts as credited when the first cell of a row in a SOURCES.md of its folder or a parent folder names
     * its path relative to that folder. A cell may hold several names separated by commas, and a name may be a glob.
     * PHP and shell files are generator code and carry their credit in a docblock.
     */
    public function testEveryFixtureHasASourcesRow(): void
    {
        $missing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (basename($path) === "SOURCES.md" || in_array($file->getExtension(), ["php", "sh"], true)) {
                continue;
            }
            if (!$this->isCredited($path)) {
                $missing[] = substr($path, strlen(self::DIR) + 1);
            }
        }

        sort($missing);
        $this->assertSame([], $missing, "Add a row for these files to a SOURCES.md in their folder or a parent folder.");
    }


    public function testEverySourcesRowNamesAFile(): void
    {
        $unmatched = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (basename($file->getPathname()) !== "SOURCES.md") {
                continue;
            }
            $folder = dirname($file->getPathname());
            foreach (self::names($folder) as $name) {
                if (glob("$folder/$name") === [] && !file_exists("$folder/$name")) {
                    $unmatched[] = substr($folder, strlen(self::DIR) + 1) . "/SOURCES.md: $name";
                }
            }
        }

        $this->assertSame([], $unmatched, "These SOURCES.md rows name no file.");
    }


    private function isCredited(string $path): bool
    {
        for ($folder = dirname($path); strlen($folder) >= strlen(self::DIR); $folder = dirname($folder)) {
            $relative = substr($path, strlen($folder) + 1);
            foreach (self::names($folder) as $name) {
                if ($name === $relative || fnmatch($name, $relative)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * @return list<string>
     */
    private static function names(string $folder): array
    {
        static $cache = [];
        if (isset($cache[$folder])) {
            return $cache[$folder];
        }

        $names = [];
        foreach (is_file("$folder/SOURCES.md") ? file("$folder/SOURCES.md") : [] as $line) {
            if (preg_match('/^\|\s*([^|]+?)\s*\|/', $line, $match) === 1 && !in_array($match[1], ["File", ":---"], true)) {
                foreach (explode(",", $match[1]) as $name) {
                    $names[] = trim($name, " `");
                }
            }
        }

        return $cache[$folder] = $names;
    }
}
