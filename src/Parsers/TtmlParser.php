<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use LibXMLError;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\AlignmentGrid;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\XmlLoader;

final class TtmlParser extends SubtitleParser
{
    protected const REPLACES_INVALID_UTF8 = true;

    public const FORMAT_DATA_KEY = Format::Ttml->value;

    private const TIMING_ATTRIBUTES = ["begin", "end", "dur"];

    private const XML_ENTITIES = ["amp", "lt", "gt", "quot", "apos"];

    private const ROOT_PATTERN = '(?:[A-Za-z_][\w.-]*:)?tt';

    // Styles can refer to each other in a loop, so the parser follows at most 20 references.
    private const MAX_STYLE_DEPTH = 20;

    private ?string $namespace;

    private DOMElement $root;

    private TtmlStyles $styles;

    /** @var array<string, string> */
    private array $agents = [];

    private TtmlClock $clock;

    private int $paragraphIndex = 0;

    /** @var array<int, array{cueKey: int, cue: SubtitleCue, line: int, paragraphIndex: int, lines: list<string>}> the paragraphs without an end */
    private array $openParagraphs = [];


    protected function read(string $content): Subtitle
    {
        $this->paragraphIndex = 0;
        $this->openParagraphs = [];
        $document             = $this->loadDocument($content);
        $this->root           = $document->documentElement;
        $this->namespace      = $this->root->namespaceURI;
        if ($this->root->localName !== "tt"
            || !in_array($this->namespace, [TtmlNamespaces::TTML, TtmlNamespaces::DFXP, TtmlNamespaces::DFXP_2006_04, null], true)) {
            throw new ParsingException("The root element is not a TTML <tt> element.");
        }

        $this->clock = $this->readClock();
        $head = $this->firstChild($this->root, "head");
        $body = $this->firstChild($this->root, "body");
        $this->styles = new TtmlStyles($this->namespace, $head);
        $this->agents = $head === null ? [] : $this->readAgents($head);

        $subtitle = new Subtitle();
        $language = $this->root->getAttributeNS(TtmlNamespaces::XML, "lang");
        if ($language !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
        }

        $fileData = [
            "namespace"  => $this->namespace ?? "",
            "namespaces" => $this->readNamespaces($document),
            "attributes" => $this->readAttributes($this->root, ["xml:lang"]),
        ];
        if ($head !== null) {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, $this->removeTitle($head));
            $fileData["head"] = $document->saveXML($head);
        }
        if ($body !== null && $this->readAttributes($body, self::TIMING_ATTRIBUTES) !== []) {
            $fileData["body"] = $this->readAttributes($body, self::TIMING_ATTRIBUTES);
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData);

        $cues = [];
        if ($body !== null) {
            $this->readContainer($cues, $body, new TtmlScope(), []);
        }
        foreach ($this->openParagraphs as $open) {
            unset($cues[$open["cueKey"]]);
            $this->fail(
                new ParsingException("The paragraph that begins at {$open["cue"]->getStart()}s has no end time."),
                $open["line"],
                $open["paragraphIndex"],
                $open["lines"]
            );
        }

        return $subtitle->addCues($cues);
    }


    private function boundedTimeExpression(string $expression): float
    {
        return self::boundedTime($this->clock->seconds($expression), trim($expression), null);
    }


    private function loadDocument(string $xml): DOMDocument
    {
        $repaired = XmlLoader::skipLeadingText($xml, self::ROOT_PATTERN, $skipped);
        if ($skipped !== "") {
            $this->skipTextBeforeXml($xml, $skipped);
        }

        $document = XmlLoader::xml($repaired, firstError: $error);
        if ($document !== null) {
            return $document;
        }
        if (!$this->options->lenient) {
            throw new ParsingException("The file is not well-formed XML.");
        }

        [$replaced, $firstEntity] = $this->replaceHtmlEntities($repaired);
        if ($firstEntity !== null) {
            $this->warn(
                "The file has HTML entities that XML does not define, such as \"$firstEntity\". The parser read them as characters.",
                1 + substr_count($repaired, "\n", 0, (int) strpos($repaired, $firstEntity)),
                null,
                [],
                ParseWarningAction::Repaired
            );
            $document = XmlLoader::xml($replaced, firstError: $error);
        }

        return $document ?? $this->recoverDocument($replaced, $error);
    }


    /**
     * Replaces the HTML named entities that XML does not define with character references.
     * The parser never loads a DTD, so no file can define these entities itself.
     *
     * @return array{string, ?string} the new XML, and the first entity that was replaced
     */
    private function replaceHtmlEntities(string $xml): array
    {
        $characters = array_flip(get_html_translation_table(HTML_ENTITIES, ENT_HTML5 | ENT_QUOTES));
        $first      = null;
        $replaced   = preg_replace_callback(
            "/&([A-Za-z][A-Za-z0-9]*);/",
            function (array $match) use ($characters, &$first): string {
                if (in_array($match[1], self::XML_ENTITIES, true) || !isset($characters[$match[0]])) {
                    return $match[0];
                }
                $first ??= $match[0];

                $codePoints = array_map(fn (string $char): int => mb_ord($char, "UTF-8"), mb_str_split($characters[$match[0]], 1, "UTF-8"));

                return implode("", array_map(fn (int $codePoint): string => "&#$codePoint;", $codePoints));
            },
            $xml
        );

        return [$replaced, $first];
    }


    /**
     * Escapes each & that starts no XML entity or character reference, closes each <br>, and lets libxml repair the rest.
     * libxml alone drops a bare & and puts the text after an open <br> inside it.
     */
    private function recoverDocument(string $xml, ?LibXMLError $error): DOMDocument
    {
        $repaired = preg_replace(
            ['/&(?!(?:' . implode("|", self::XML_ENTITIES) . '|#[0-9]+|#x[0-9A-Fa-f]+);)/', '/<((?:[A-Za-z_][\w.-]*:)?br)(\s[^<>]*)?(?<!\/)>/i'],
            ["&amp;", "<\$1\$2/>"],
            $xml
        );
        $document = XmlLoader::xml($repaired, recover: true) ?? throw new ParsingException("The file is not well-formed XML.");

        $message = $error === null ? "" : " " . rtrim($error->message, ". \n") . ".";
        $this->warn(
            "The file is not well-formed XML.$message The parser repaired it.",
            $error?->line ?: null,
            null,
            [],
            ParseWarningAction::Repaired
        );

        return $document;
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#parameter-attribute-frameRate
     */
    private function readClock(): TtmlClock
    {
        $frameRate  = $this->attribute($this->root, "ttp", "frameRate");
        $multiplier = preg_split("/[\s:]+/", trim($this->attribute($this->root, "ttp", "frameRateMultiplier") ?? "1 1"));
        if (count($multiplier) !== 2 || (float) $multiplier[0] <= 0 || (float) $multiplier[1] <= 0) {
            $multiplier = [1, 1];
        }

        $guess          = $frameRate === null ? $this->guessFrameRate() : null;
        $smpteFrameRate = (float) ($frameRate ?? $guess ?? 30) ?: 30;
        $effectiveRate  = $smpteFrameRate * (float) $multiplier[0] / (float) $multiplier[1];
        $tickRate       = $this->attribute($this->root, "ttp", "tickRate");

        return new TtmlClock(
            frameRate: $effectiveRate,
            smpteFrameRate: $smpteFrameRate,
            smpteTimeBase: trim($this->attribute($this->root, "ttp", "timeBase") ?? "") === "smpte",
            dropMode: trim($this->attribute($this->root, "ttp", "dropMode") ?? "nonDrop"),
            subFrameRate: (float) ($this->attribute($this->root, "ttp", "subFrameRate") ?? 1) ?: 1,
            tickRate: $tickRate !== null && (float) $tickRate > 0 ? (float) $tickRate : ($frameRate !== null ? $effectiveRate : 1),
            lastFieldIsHundredths: $frameRate === null && $guess === null,
        );
    }


    /**
     * Returns the default of 30 fps, or 50 or 60 fps in lenient mode when a frame label in the file is 30 or more.
     * No frame rate fits a label of 60 or more, so lenient mode then returns null and reads the last field as hundredths of a second.
     *
     * @see https://www.w3.org/TR/ttml2/#parameter-attribute-frameRate
     */
    private function guessFrameRate(): ?float
    {
        $highest = null;
        // libxml merges the node sets of a union query such as "//@begin | //@end" in quadratic time.
        foreach ((new DOMXPath($this->root->ownerDocument))->query("//@*[namespace-uri() = '' and (local-name() = 'begin' or local-name() = 'end' or local-name() = 'dur')]") as $attribute) {
            if (preg_match("/^\d{2,}:\d{2}:\d{2}:(\d{2})(?:\.\d+)?$/", trim($attribute->value), $matches)
                && (int) $matches[1] >= 30 && (int) $matches[1] > (int) ($highest[1] ?? 0)) {
                $highest = [$attribute, (int) $matches[1]];
            }
        }
        if ($highest === null) {
            return 30;
        }

        [$attribute, $label] = $highest;
        $expression = trim($attribute->value);
        $line       = $attribute->parentNode->getLineNo();
        $message    = "The frame label $label in \"$expression\" is not below the default frame rate of 30, and the file has no ttp:frameRate.";
        if (!$this->options->lenient) {
            throw new ParsingException($message, $line);
        }
        if ($label >= 60) {
            $this->warn("$message The parser read the last field as hundredths of a second.", $line, null, [], ParseWarningAction::Repaired);

            return null;
        }

        $guess = $label < 50 ? 50 : 60;
        $this->warn("$message The parser read the frames at $guess fps.", $line, null, [], ParseWarningAction::Repaired);

        return $guess;
    }


    /**
     * @param list<SubtitleCue> $cues
     *
     * @see https://www.w3.org/TR/ttml2/#timing-time-intervals
     */
    private function readContainer(array &$cues, DOMElement $container, TtmlScope $parent, array $divAttributes): void
    {
        try {
            [$begin, $end] = $this->interval($container, $parent->begin, $parent->end);
        } catch (ParsingException $exception) {
            if (!$this->options->lenient) {
                throw $exception;
            }
            [$begin, $end] = [$parent->begin, $parent->end];
            $this->warn(
                $exception->getRawMessage() . " The parser ignored the timing of the " . $container->localName . ".",
                $container->getLineNo(),
                null,
                [],
                ParseWarningAction::Repaired
            );
        }
        $properties    = $this->styles->ownProperties($container);
        $scope         = new TtmlScope(
            $begin,
            $end,
            $container->hasAttribute("region") ? $container->getAttribute("region") : $parent->region,
            $properties["textAlign"] ?? $parent->textAlign,
            $this->preservesSpace($container, $parent->preserveSpace),
            $this->forcedDisplay($container) ?? $parent->forced,
            $properties === [] ? $parent->styleProperties : [...$parent->styleProperties, $properties],
        );
        if ($this->isTtElement($container, "div")) {
            $divAttributes = [
                ...$divAttributes,
                ...$this->readAttributes($container, [...self::TIMING_ATTRIBUTES, "xml:id", "region"]),
            ];
        }

        foreach ($container->childNodes as $child) {
            if ($this->isTtElement($child, "div")) {
                $this->readContainer($cues, $child, $scope, $divAttributes);
            } elseif ($this->isTtElement($child, "p")) {
                try {
                    [$cue, $open] = $this->readParagraph($child, $scope);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $child->getLineNo(), $this->paragraphIndex++, $this->xmlLines($child));
                    continue;
                }
                $this->paragraphIndex++;
                if ($divAttributes !== []) {
                    $cue->setFormatData(self::FORMAT_DATA_KEY, [...$cue->findFormatData(self::FORMAT_DATA_KEY), "div" => $divAttributes]);
                }
                $this->endOpenParagraphs($cue->getStart());
                if ($open) {
                    $this->openParagraphs[] = [
                        "cueKey"         => count($cues),
                        "cue"            => $cue,
                        "line"           => $child->getLineNo(),
                        "paragraphIndex" => $this->paragraphIndex - 1,
                        "lines"          => $this->xmlLines($child),
                    ];
                }
                $cues[] = $cue;
            }
        }
    }


    /**
     * @return list<string>
     */
    private function xmlLines(DOMElement $element): array
    {
        $lines = array_map("trim", explode("\n", $element->ownerDocument->saveXML($element)));

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ""));
    }


    /**
     * In lenient mode, a paragraph without an end gets its begin as end.
     *
     * @return array{SubtitleCue, bool} the cue, and true for a paragraph without an end
     */
    private function readParagraph(DOMElement $paragraph, TtmlScope $scope): array
    {
        $open = false;
        [$begin, $end] = $this->interval($paragraph, $scope->begin, $scope->end);
        if ($scope->end !== null && $begin >= $scope->end) {
            [$begin, $end] = $this->absoluteInterval($paragraph, $begin, $scope->end);
        }

        $region = $paragraph->hasAttribute("region") ? $paragraph->getAttribute("region") : $scope->region;
        $style  = TtmlStyles::DEFAULT_STYLE;
        foreach ([$this->styles->regionProperties($region), ...$scope->styleProperties] as $properties) {
            $style = TtmlStyles::apply($style, $properties);
        }
        $style = $this->resolveStyle($paragraph, $style);
        $agent = $this->agentName($paragraph);
        $runs  = [];
        $this->collectRuns($paragraph, $style, $agent, $this->preservesSpace($paragraph, $scope->preserveSpace), [$begin, $end], $runs);
        if ($end === null) {
            $spans = $this->timedSpansInterval($runs);
            $open  = $spans === null && $this->options->lenient;
            [$begin, $end] = $spans ?? ($open ? [$begin, $begin] : throw new ParsingException("The paragraph that begins at {$begin}s has no end time.", $paragraph->getLineNo()));
        }
        $runs = array_values(array_filter(
            $runs,
            fn (TtmlRun $run): bool => !$run->isTimedSpan() || $run->begin > $begin && ($open || $run->begin < $end)
        ));

        $cue = new SubtitleCue($begin, $end, $this->runsToLines($runs));
        $id  = $paragraph->getAttributeNS(TtmlNamespaces::XML, "id");
        $cue->setIdentifier($id === "" ? null : $id);

        $attributes = $this->readAttributes($paragraph, [...self::TIMING_ATTRIBUTES, "xml:id"]);
        if (!$paragraph->hasAttribute("region") && $scope->region !== null) {
            $attributes["region"] = $scope->region;
        }
        $textAlign = $this->styles->ownProperties($paragraph)["textAlign"] ?? $scope->textAlign;
        $alignment = $this->alignment($region, $textAlign);
        $cue->setAlignment($alignment ?? Alignment::BottomCenter);
        $cue->setFormatData(self::FORMAT_DATA_KEY, array_filter(["attributes" => $attributes, "alignment" => $alignment?->value], fn (mixed $value): bool => $value !== [] && $value !== null));

        $forced = $this->forcedDisplay($paragraph) ?? $scope->forced ?? $this->regionForcedDisplay($region) ?? false;
        $cue->setForced($forced || $this->hasForcedSpan($paragraph, $forced));

        return [$cue, $open];
    }


    /**
     * Ends each paragraph without an end that begins before $begin.
     */
    private function endOpenParagraphs(float $begin): void
    {
        foreach ($this->openParagraphs as $key => $open) {
            if ($open["cue"]->getStart() < $begin) {
                $open["cue"]->setEnd($begin);
                $this->warn(
                    "The paragraph that begins at {$open["cue"]->getStart()}s has no end time. The parser ended it at the next paragraph at {$begin}s.",
                    $open["line"],
                    $open["paragraphIndex"],
                    $open["lines"],
                    ParseWarningAction::Repaired
                );
                unset($this->openParagraphs[$key]);
            }
        }
    }


    /**
     * Reads itts:forcedDisplay from the element or from the styles that it references.
     *
     * @see https://www.w3.org/TR/ttml-imsc1.1/#forceddisplay
     */
    private function forcedDisplay(DOMElement $element, int $depth = 0): ?bool
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === TtmlNamespaces::IMSC_STYLING && $attribute->localName === "forcedDisplay"
                || $attribute->namespaceURI === null && $attribute->nodeName === "itts:forcedDisplay") {
                return trim($attribute->value) === "true";
            }
        }

        $forced = null;
        if ($depth < self::MAX_STYLE_DEPTH) {
            foreach ($this->styles->styleIds($element) as $id) {
                if (isset($this->styles->styles[$id])) {
                    $forced = $this->forcedDisplay($this->styles->styles[$id], $depth + 1) ?? $forced;
                }
            }
        }

        return $forced;
    }


    private function regionForcedDisplay(?string $regionId): ?bool
    {
        if ($regionId === null || !isset($this->styles->regions[$regionId])) {
            return null;
        }

        $forced = $this->forcedDisplay($this->styles->regions[$regionId]);
        foreach ($this->styles->regions[$regionId]->childNodes as $child) {
            if ($forced === null && $this->isTtElement($child, "style")) {
                $forced = $this->forcedDisplay($child);
            }
        }

        return $forced;
    }


    private function hasForcedSpan(DOMElement $element, bool $inherited): bool
    {
        foreach ($element->childNodes as $child) {
            if ($this->isTtElement($child, "span")) {
                $forced = $this->forcedDisplay($child) ?? $inherited;
                if ($forced || $this->hasForcedSpan($child, $forced)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Some files give the times of a paragraph in a timed div as absolute times, so the paragraph begins after the div ends.
     *
     * @return array{float, float}
     */
    private function absoluteInterval(DOMElement $paragraph, float $begin, float $parentEnd): array
    {
        $message  = sprintf("The paragraph begins at %ss, but its parent ends at %ss.", round($begin, 3), round($parentEnd, 3));
        $absolute = $paragraph->hasAttribute("begin") ? $this->interval($paragraph, 0.0, null) : [0.0, null];
        if (!$this->options->lenient || $absolute[1] === null || $absolute[1] <= $absolute[0]) {
            throw new ParsingException($message, $paragraph->getLineNo());
        }

        $this->warn(
            sprintf("%s The parser read its times as absolute: %ss to %ss.", $message, round($absolute[0], 3), round($absolute[1], 3)),
            $paragraph->getLineNo(),
            $this->paragraphIndex,
            $this->xmlLines($paragraph),
            ParseWarningAction::Repaired
        );

        return $absolute;
    }


    private function offsetTime(float $base, DOMElement $element, string $attribute): float
    {
        $expression = trim($element->getAttribute($attribute));
        try {
            $seconds = $this->boundedTimeExpression($expression);
        } catch (ParsingException $exception) {
            $seconds = $this->options->lenient ? TtmlClock::looseSeconds($expression) : null;
            if ($seconds === null) {
                throw $exception;
            }
            $time = self::boundedTime($base + $seconds, $expression, $element->getLineNo());
            $this->warn(
                "The time expression \"$expression\" is not valid. The parser read it as {$seconds}s.",
                $element->getLineNo(),
                $this->isInParagraph($element) ? $this->paragraphIndex : null,
                [],
                ParseWarningAction::Repaired
            );

            return $time;
        }

        return self::boundedTime($base + $seconds, $expression, $element->getLineNo());
    }


    private function isInParagraph(DOMNode $node): bool
    {
        for (; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($this->isTtElement($node, "p")) {
                return true;
            }
        }

        return false;
    }


    /**
     * @return array{float, ?float}
     */
    private function interval(DOMElement $element, float $parentBegin, ?float $parentEnd): array
    {
        $begin = $parentBegin;
        if ($element->hasAttribute("begin")) {
            $begin = $this->offsetTime($begin, $element, "begin");
        }

        $ends = $parentEnd === null ? [] : [$parentEnd];
        if (trim($element->getAttribute("end")) !== "") {
            $ends[] = $this->offsetTime($parentBegin, $element, "end");
        }
        if (trim($element->getAttribute("dur")) !== "") {
            $ends[] = $this->offsetTime($begin, $element, "dur");
        }

        return [$begin, $ends === [] ? null : min($ends)];
    }


    /**
     * @param array{float, ?float} $interval the time interval of $node
     * @param list<TtmlRun>        $runs
     */
    private function collectRuns(DOMNode $node, array $style, ?string $agent, bool $preserveSpace, array $interval, array &$runs): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                self::addTextRuns($runs, $child->nodeValue, $style, $agent, $preserveSpace);
            } elseif ($this->isTtElement($child, "br")) {
                $runs[] = TtmlRun::lineBreak();
            } elseif ($this->isTtElement($child, "span")) {
                $spanInterval = $interval;
                if (array_filter(self::TIMING_ATTRIBUTES, $child->hasAttribute(...)) !== []) {
                    try {
                        $spanInterval = $this->interval($child, ...$interval);
                        $runs[]       = TtmlRun::timedSpan(...$spanInterval);
                    } catch (ParsingException) {
                        // A broken span time only drops the word timestamp, so the text of the cue stays.
                    }
                }
                $this->collectRuns(
                    $child,
                    $this->resolveStyle($child, $style),
                    $this->agentName($child) ?? $agent,
                    $this->preservesSpace($child, $preserveSpace),
                    $spanInterval,
                    $runs
                );
            }
        }
    }


    /**
     * Returns the union of the timed spans, for a paragraph that has no end of its own.
     *
     * @param list<TtmlRun> $runs
     *
     * @return ?array{float, float}
     */
    private function timedSpansInterval(array $runs): ?array
    {
        $timed = array_filter($runs, fn (TtmlRun $run): bool => $run->isTimedSpan());
        $ends  = array_filter(array_map(fn (TtmlRun $run): ?float => $run->end, $timed), fn (?float $end): bool => $end !== null);
        if ($ends === []) {
            return null;
        }

        return [min(array_map(fn (TtmlRun $run): float => $run->begin, $timed)), max($ends)];
    }


    /**
     * Adds the runs of a text node. With xml:space="preserve", each line feed becomes a line break.
     *
     * @param list<TtmlRun> $runs
     */
    private static function addTextRuns(array &$runs, string $text, array $style, ?string $agent, bool $preserveSpace): void
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = $preserveSpace ? explode("\n", $text) : [$text];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $runs[] = TtmlRun::lineBreak();
            }
            $runs[] = TtmlRun::text(preg_replace("/[ \t\n]+/", " ", $part), $style, $agent);
        }
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#content-attribute-space
     *
     * @param list<TtmlRun> $runs
     *
     * @return list<string>
     */
    private function runsToLines(array $runs): array
    {
        $lines       = [];
        $lineRuns    = [];
        $openAgent   = null;
        foreach ([...$runs, TtmlRun::lineBreak()] as $run) {
            if (!$run->isLineBreak()) {
                $lineRuns[] = $run;
                continue;
            }

            $endsWithSpace = true;
            foreach ($lineRuns as $lineRun) {
                if ($endsWithSpace && str_starts_with($lineRun->text, " ")) {
                    $lineRun->text = substr($lineRun->text, 1);
                }
                if ($lineRun->text !== "") {
                    $endsWithSpace = str_ends_with($lineRun->text, " ");
                }
            }
            for ($index = count($lineRuns) - 1; $index >= 0; $index--) {
                $lineRuns[$index]->text = rtrim($lineRuns[$index]->text, " ");
                if ($lineRuns[$index]->text !== "") {
                    break;
                }
            }

            $lines[]  = $this->runsToMarkup($lineRuns, $openAgent);
            $lineRuns = [];
        }

        return $lines;
    }


    /**
     * A speaker tag stays open across lines, as in WebVTT.
     *
     * @param list<TtmlRun> $runs
     */
    private function runsToMarkup(array $runs, ?string &$openAgent): string
    {
        $markup    = "";
        $agentRuns = [];
        foreach ($runs as $run) {
            if ($run->isTimedSpan()) {
                $markup   .= StyleRuns::toMarkup($agentRuns) . "<" . Markup::coreTimestamp($run->begin) . ">";
                $agentRuns = [];
                continue;
            }
            if (trim($run->text) !== "" && $run->agent !== $openAgent) {
                $markup   .= StyleRuns::toMarkup($agentRuns) . ($openAgent === null ? "" : "</v>")
                    . ($run->agent === null ? "" : Markup::voiceTag($run->agent));
                $agentRuns = [];
                $openAgent = $run->agent;
            }
            $agentRuns[] = [$run->text, $run->style];
        }

        return $markup . StyleRuns::toMarkup($agentRuns);
    }


    private function resolveStyle(DOMElement $element, array $inherited): array
    {
        return TtmlStyles::apply($inherited, $this->styles->ownProperties($element));
    }


    /**
     * Start and end depend on the text direction, so they give no alignment.
     *
     * @see https://www.w3.org/TR/ttml2/#style-attribute-displayAlign
     */
    private function alignment(?string $regionId, ?string $textAlign): ?Alignment
    {
        if ($regionId !== null && !isset($this->styles->regions[$regionId]) || $regionId === null && $this->styles->regions !== []) {
            return null;
        }

        $properties = $this->styles->regionProperties($regionId);
        $column = match (trim($textAlign ?? $properties["textAlign"] ?? "start")) {
            "left"   => AlignmentGrid::LEFT,
            "center" => AlignmentGrid::CENTER,
            "right"  => AlignmentGrid::RIGHT,
            default  => null,
        };
        $origin = $this->verticalLength($properties["origin"] ?? "auto", 0.0);
        $extent = $this->verticalLength($properties["extent"] ?? "auto", 1.0);
        if ($column === null || $origin === null || $extent === null) {
            return null;
        }

        $anchor = match (trim($properties["displayAlign"] ?? "before")) {
            "before" => $origin,
            "center" => $origin + $extent / 2,
            "after"  => $origin + $extent,
            default  => null,
        };

        $row = match (true) {
            $anchor === null => null,
            $anchor < 1 / 3  => AlignmentGrid::TOP_ROW,
            $anchor > 2 / 3  => AlignmentGrid::BOTTOM_ROW,
            default          => AlignmentGrid::MIDDLE_ROW,
        };

        return $row === null ? null : AlignmentGrid::of($row, $column);
    }


    /**
     * Returns the second length of an origin or extent value as a fraction of the root container height.
     */
    private function verticalLength(string $value, float $auto): ?float
    {
        $parts = preg_split("/\s+/", trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === ["auto"]) {
            return $auto;
        }
        if (count($parts) !== 2 || !preg_match("/^([+-]?\d+(?:\.\d+)?)(%|px|c)$/", $parts[1], $matches)) {
            return null;
        }

        $length = (float) $matches[1];
        if ($matches[2] === "%") {
            return $length / 100;
        }
        if ($matches[2] === "c") {
            $resolution = preg_split("/\s+/", trim($this->attribute($this->root, "ttp", "cellResolution") ?? "32 15"));

            return count($resolution) === 2 && (int) $resolution[1] > 0 ? $length / (int) $resolution[1] : null;
        }

        $body       = $this->firstChild($this->root, "body");
        $rootExtent = TtmlStyles::stylingAttributes($this->root)["extent"]
                      ?? ($body === null ? null : TtmlStyles::stylingAttributes($body)["extent"] ?? null);
        if ($rootExtent === null || !preg_match("/^\s*\d+(?:\.\d+)?px\s+(\d+(?:\.\d+)?)px\s*$/", $rootExtent, $matches)
            || (float) $matches[1] <= 0) {
            return null;
        }

        return $length / (float) $matches[1];
    }


    private function agentName(DOMElement $element): ?string
    {
        $ids = preg_split("/\s+/", trim($this->attribute($element, "ttm", "agent") ?? ""), -1, PREG_SPLIT_NO_EMPTY);
        if ($ids === []) {
            return null;
        }

        return $this->agents[$ids[0]] ?? $ids[0];
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#metadata-vocabulary-agent
     */
    private function readAgents(DOMElement $head): array
    {
        $agents = [];
        foreach ($head->getElementsByTagName("*") as $element) {
            if (!$this->isMetadataElement($element, "agent")) {
                continue;
            }
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && $this->isMetadataElement($child, "name") && trim($child->textContent) !== "") {
                    $agents[$id] = trim($child->textContent);
                    break;
                }
            }
        }

        return $agents;
    }


    /**
     * The formatter writes the title metadata in place of the removed element.
     */
    private function removeTitle(DOMElement $head): ?string
    {
        foreach ($head->getElementsByTagName("*") as $element) {
            if ($this->isMetadataElement($element, "title")) {
                $title    = trim($element->textContent);
                $previous = $element->previousSibling;
                if ($previous !== null && $previous->nodeType === XML_TEXT_NODE && trim($previous->nodeValue) === "") {
                    $previous->parentNode->removeChild($previous);
                }
                $element->parentNode->removeChild($element);

                return $title === "" ? null : $title;
            }
        }

        return null;
    }


    private function readNamespaces(DOMDocument $document): array
    {
        $namespaces = [];
        foreach ((new DOMXPath($document))->query("namespace::*", $this->root) as $namespace) {
            if ($namespace->prefix !== "xml") {
                $namespaces[$namespace->prefix ?? ""] = $namespace->namespaceURI;
            }
        }
        ksort($namespaces);

        return $namespaces;
    }


    private function readAttributes(DOMElement $element, array $skip): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $name = $attribute->namespaceURI === TtmlNamespaces::XML ? "xml:" . $attribute->localName : $attribute->nodeName;
            if (in_array($attribute->namespaceURI, TtmlNamespaces::METADATA, true) && $attribute->localName === "agent") {
                continue;
            }
            if (!in_array($name, $skip, true)) {
                $attributes[$name] = $attribute->value;
            }
        }

        return $attributes;
    }


    private function preservesSpace(DOMElement $element, bool $inherited): bool
    {
        $space = $element->getAttributeNS(TtmlNamespaces::XML, "space");

        return $space === "" ? $inherited : $space === "preserve";
    }


    /**
     * Reads a ttp:, tts: or ttm: attribute. A file that does not declare the prefix still gets its value.
     */
    private function attribute(DOMElement $element, string $prefix, string $localName): ?string
    {
        $namespaces = match ($prefix) {
            "ttp" => TtmlNamespaces::PARAMETER,
            "tts" => TtmlNamespaces::STYLING,
            "ttm" => TtmlNamespaces::METADATA,
        };
        foreach ($element->attributes as $attribute) {
            if (in_array($attribute->namespaceURI, $namespaces, true) && $attribute->localName === $localName
                || $attribute->namespaceURI === null && $attribute->nodeName === "$prefix:$localName") {
                return $attribute->value;
            }
        }

        return null;
    }


    private function isTtElement(DOMNode $node, string $localName): bool
    {
        return $node instanceof DOMElement && $node->localName === $localName && $node->namespaceURI === $this->namespace;
    }


    private function isMetadataElement(DOMElement $element, string $localName): bool
    {
        return in_array($element->namespaceURI, TtmlNamespaces::METADATA, true) && $element->localName === $localName
               || $element->namespaceURI === null && $element->nodeName === "ttm:$localName";
    }


    private function firstChild(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($this->isTtElement($child, $localName)) {
                return $child;
            }
        }

        return null;
    }
}
