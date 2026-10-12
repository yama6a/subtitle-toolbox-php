<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\XmlLoader;

final class SamiParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = SamiReadOptions::class;
    protected const REPLACES_INVALID_UTF8 = true;

    public const FORMAT_DATA_KEY = Format::Sami->value;

    private const STYLE_TAGS = ["b" => "b", "i" => "i", "u" => "u", "s" => "s", "strike" => "s"];

    private const NBSP = "\u{00A0}";


    protected function read(string $content): Subtitle
    {
        $content = StringHelpers::normalizeEOLs($content);

        $subtitle               = new Subtitle();
        [$formatData, $classes] = $this->readHead($content, $subtitle);

        $syncs = $this->readSyncs($content);
        $class = $this->chooseClass($classes, $syncs, $content);
        if ($class !== null) {
            $formatData["class"] = $class;
            $language            = $classes[strtolower($class)]["lang"] ?? null;
            if ($language !== null && $language !== "") {
                $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
            }
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $formatData);

        return $subtitle->addCues($this->cues($syncs, $class));
    }


    /**
     * A file with SYNC tags can give no cues on purpose, for example when the chosen class has no text.
     */
    protected function findTextLineWithoutCues(string $content): ?int
    {
        if (stripos($content, "<SYNC") !== false) {
            return null;
        }

        $markup = preg_replace_callback(
            '/<HEAD\b.*?<\/HEAD\s*>|<!--.*?-->|<[^>]*>|&nbsp;/is',
            fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
            StringHelpers::normalizeEOLs($content)
        );
        $text = ltrim($markup);

        return $text === "" ? null : 1 + substr_count($markup, "\n", 0, strlen($markup) - strlen($text));
    }


    /**
     * Reads the title into $subtitle. Returns the SAMIParam and STYLE blocks as format data, and the classes.
     *
     * @return array{array<string, string>, array<string, array{name: string, lang: ?string}>}
     */
    private function readHead(string $content, Subtitle $subtitle): array
    {
        $formatData = [];
        if (preg_match('/<TITLE\b[^>]*>(.*?)<\/TITLE\s*>/is', $content, $matches) && trim($matches[1]) !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, Markup::decodeEntities(trim($matches[1])));
        }
        if (preg_match('/<SAMIParam\b[^>]*>(.*?)<\/SAMIParam\s*>/is', $content, $matches)) {
            $formatData["samiParam"] = $matches[1];
        }

        $classes = [];
        if (preg_match('/<STYLE\b[^>]*>(.*?)<\/STYLE\s*>/is', $content, $matches)) {
            $formatData["style"] = $matches[1];
            $classes             = $this->readClasses($matches[1]);
        }

        return [$formatData, $classes];
    }


    /**
     * Returns a cue for each SYNC with text of the class. Each cue ends at the next SYNC of the class.
     *
     * @param list<array{start: float, paragraphs: list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>}> $syncs
     * @return list<SubtitleCue>
     */
    private function cues(array $syncs, ?string $class): array
    {
        $parsedCues = [];
        $openCue    = null;
        foreach ($syncs as $sync) {
            $paragraphs = $this->paragraphsFor($sync, $class);
            if ($paragraphs === null) {
                continue;
            }

            if ($openCue !== null) {
                $parsedCues[] = $openCue->setEnd($sync["start"]);
                $openCue = null;
            }

            $lines = array_merge(...array_column($paragraphs, "lines"));
            if ($lines !== []) {
                $openCue = (new SubtitleCue($sync["start"], $sync["start"], $lines))->setFormatData(self::FORMAT_DATA_KEY, [
                    "paragraphs" => array_map(fn (array $paragraph): array => [
                        "attributes" => $paragraph["attributes"],
                        "html"       => $paragraph["html"],
                    ], $paragraphs),
                    "lines"      => $lines,
                ]);
            }
        }

        if ($openCue !== null) {
            $parsedCues[] = $openCue->setEnd($openCue->getStart() + $this->options->lastCueDuration);
        }

        return $parsedCues;
    }


    /**
     * Reads the .CLASS rules of the STYLE block, keyed by the lowercase class name.
     *
     * @return array<string, array{name: string, lang: ?string}>
     */
    private function readClasses(string $style): array
    {
        $css = preg_replace('/\/\*.*?\*\/|<!--|-->/s', "", $style);
        preg_match_all('/\.([A-Za-z_][\w-]*)\s*\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $classes = [];
        foreach ($rules as [, $name, $body]) {
            $lang = null;
            foreach (explode(";", $body) as $declaration) {
                $parts = explode(":", $declaration, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === "lang") {
                    $lang = trim($parts[1], " \t\n\"'");
                }
            }
            $classes[strtolower($name)] ??= ["name" => $name, "lang" => $lang];
        }

        return $classes;
    }


    /**
     * @return list<array{start: float, paragraphs: list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>}>
     */
    private function readSyncs(string $content): array
    {
        $bodyStart = self::bodyStart($content);
        $body      = substr($content, $bodyStart);
        if (preg_match('/<\/BODY\s*>/i', $body, $end, PREG_OFFSET_CAPTURE) === 1) {
            $body = substr($body, 0, $end[0][1]);
        }
        $body = preg_replace('/<\/SYNC\s*>/i', "", $body);

        $syncs      = [];
        $lineNumber = 1 + substr_count($content, "\n", 0, $bodyStart);
        $counted    = 0;
        foreach (array_slice(preg_split('/<SYNC\b/i', $body, -1, PREG_SPLIT_OFFSET_CAPTURE), 1) as $index => [$chunk, $offset]) {
            $lineNumber += substr_count($body, "\n", $counted, $offset - $counted);
            $counted     = $offset;
            $block       = fn (): array => array_values(array_filter(
                array_map("trim", explode("\n", "<SYNC" . $chunk)),
                fn (string $line): bool => $line !== ""
            ));
            try {
                [$start, $syncContent] = $this->readSyncTag($chunk, $index, $lineNumber);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $index, $block());
                continue;
            }

            if ($start < 0) {
                if ($this->options->lenient) {
                    $this->warn(
                        "SYNC tag " . ($index + 1) . " has a negative Start. The parser read it as 0.",
                        $lineNumber,
                        $index,
                        $block(),
                        ParseWarningAction::Repaired
                    );
                }
                $start = 0.0;
            }

            $syncs[] = ["start" => $start, "paragraphs" => $this->readParagraphs($syncContent)];
        }

        usort($syncs, fn (array $sync1, array $sync2): int => $sync1["start"] <=> $sync2["start"]);

        return $syncs;
    }


    /**
     * @return array{float, string} the Start time in seconds and the content after the SYNC tag
     */
    private function readSyncTag(string $chunk, int $index, int $lineNumber): array
    {
        if (!preg_match('/^([^>]*)>(.*)$/s', $chunk, $matches) ||
            !preg_match('/\bStart\s*=\s*["\']?\s*(-?\d+)/i', $matches[1], $start)) {
            throw new ParsingException("SYNC tag " . ($index + 1) . " has no valid Start attribute.", $lineNumber);
        }

        return [self::boundedTime(((int) $start[1]) / 1000, $start[1], $lineNumber), $matches[2]];
    }


    /**
     * Returns the offset after the BODY start tag, or 0 without one.
     * A pattern that starts with ^.*? would hit the PCRE backtrack limit after about 1 MB of head.
     */
    private static function bodyStart(string $content): int
    {
        return preg_match('/<BODY\b[^>]*>/i', $content, $start, PREG_OFFSET_CAPTURE) === 1 ? $start[0][1] + strlen($start[0][0]) : 0;
    }


    /**
     * Reads the P elements of one SYNC block. Content outside a P becomes a paragraph without class.
     *
     * @return list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>
     */
    private function readParagraphs(string $html): array
    {
        // libxml keeps &nbsp without a semicolon as text. Browsers and other SAMI readers read it as a non-breaking space.
        $html = preg_replace('/&nbsp(?!;)/', "&nbsp;", $html);
        // The meta tag makes libxml read the input as UTF-8 in place of ISO-8859-1.
        $document = XmlLoader::html('<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html);

        $paragraphs = [];
        $loose      = [];
        $body       = $document->getElementsByTagName("body")->item(0);
        foreach ($body === null ? [] : $body->childNodes as $node) {
            if ($node instanceof DOMElement && $node->nodeName === "p") {
                $paragraphs[] = $this->readParagraph($document, $node, $node->hasAttribute("class") ? $node->getAttribute("class") : null);
            } else {
                $loose[] = $node;
            }
        }

        $looseText = implode("", array_map(fn (DOMNode $node): string => $node->textContent, $loose));
        if ($paragraphs === [] || trim(str_replace(self::NBSP, " ", $looseText)) !== "") {
            $wrapper = $document->createElement("p");
            foreach ($loose as $node) {
                $wrapper->appendChild($node);
            }
            array_unshift($paragraphs, $this->readParagraph($document, $wrapper, null));
        }

        return $paragraphs;
    }


    /**
     * @return array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}
     */
    private function readParagraph(DOMDocument $document, DOMElement $paragraph, ?string $class): array
    {
        $attributes = [];
        foreach ($paragraph->attributes as $attribute) {
            if ($attribute->nodeName !== "class") {
                $attributes[$attribute->nodeName] = $attribute->nodeValue;
            }
        }

        $html = "";
        foreach ($paragraph->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        $lines = [];
        foreach (explode("\n", $this->toMarkup($paragraph)) as $line) {
            $line = trim($line);
            if (trim($line, " " . self::NBSP) !== "") {
                $lines[] = $line;
            }
        }

        return ["class" => $class, "attributes" => $attributes, "html" => trim($html), "lines" => $lines];
    }


    private function toMarkup(DOMNode $node): string
    {
        $markup = "";
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $markup .= Markup::escapeText(preg_replace('/[ \t\n\r\f]+/', " ", $child->nodeValue));
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }

            $inner = $this->toMarkup($child);
            $tag   = self::STYLE_TAGS[$child->nodeName] ?? null;
            $color = $child->nodeName === "font" ? $this->readColor($child->getAttribute("color")) : null;
            if ($child->nodeName === "br") {
                $markup .= "\n";
            } elseif ($tag !== null) {
                $markup .= "<$tag>$inner</$tag>";
            } elseif ($color !== null) {
                $markup .= "<font color=\"$color\">$inner</font>";
            } else {
                $markup .= $inner;
            }
        }

        return $markup;
    }


    private function readColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#?([0-9a-f]{6})$/', $value, $matches)) {
            return "#" . $matches[1];
        }

        return ColorNames::HTML[$value] ?? null;
    }


    /**
     * @param array<string, array{name: string, lang: ?string}> $classes
     */
    private function chooseClass(array $classes, array $syncs, string $content): ?string
    {
        $used = [];
        foreach ($syncs as $sync) {
            foreach ($sync["paragraphs"] as $paragraph) {
                if ($paragraph["class"] !== null) {
                    $used[strtolower($paragraph["class"])] ??= $paragraph["class"];
                }
            }
        }

        $languageClass = $this->formatOptions()->languageClass;
        if ($languageClass !== null) {
            $key = strtolower($languageClass);
            if (!isset($classes[$key]) && !isset($used[$key])) {
                throw new ParsingException("The SAMI file has no class $languageClass.");
            }

            return $classes[$key]["name"] ?? $used[$key];
        }

        $first = reset($classes);
        if ($first === false) {
            return reset($used) ?: null;
        }
        if ($used === []) {
            return $first["name"];
        }
        $usedStyleClasses = array_intersect_key($classes, $used);
        if ($usedStyleClasses !== []) {
            return reset($usedStyleClasses)["name"];
        }

        $class = reset($used);
        if ($this->options->lenient) {
            $lineNumber = null;
            $block      = [];
            if (preg_match('/^.*\bClass\s*=\s*["\']?' . preg_quote($class, "/") . '\b.*$/im', $content, $match, PREG_OFFSET_CAPTURE, self::bodyStart($content)) === 1) {
                $lineNumber = 1 + substr_count($content, "\n", 0, $match[0][1]);
                $block      = [trim($match[0][0])];
            }
            $this->warn(
                "No <P> class matches a class of the STYLE block. The parser read the class $class.",
                $lineNumber,
                null,
                $block,
                ParseWarningAction::Repaired
            );
        }

        return $class;
    }


    /**
     * Returns the paragraphs of the SYNC block that the class shows, or null when the block does not touch the class.
     *
     * @return ?list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>
     */
    private function paragraphsFor(array $sync, ?string $class): ?array
    {
        $paragraphs = array_values(array_filter(
            $sync["paragraphs"],
            fn (array $paragraph): bool => $paragraph["class"] === null || $class === null || strcasecmp($paragraph["class"], $class) === 0
        ));

        return $paragraphs === [] ? null : $paragraphs;
    }
}
