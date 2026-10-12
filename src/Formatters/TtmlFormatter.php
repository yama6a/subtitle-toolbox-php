<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\Parsers\TtmlNamespaces;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\TtmlStyles;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class TtmlFormatter extends SubtitleFormatter
{
    // The formatter writes media times, which these time parameters would change.
    private const SKIPPED_ROOT_PARAMETERS = ["timeBase", "clockMode", "dropMode", "markerMode"];


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData = $subtitle->findFormatData(TtmlParser::FORMAT_DATA_KEY);
        $context  = TtmlHead::load(
            ($fileData["namespace"] ?? "") ?: TtmlNamespaces::TTML,
            $fileData["namespaces"] ?? [],
            $fileData["head"] ?? "<head/>"
        );

        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE);
        if ($title !== null) {
            TtmlHead::addTitle($context, $title);
        }

        $styles = new TtmlStyles($context->namespace, $context->head);
        $body   = $this->styleProperties($context, $styles, $this->writableAttributes($context, $fileData["body"] ?? [], []));
        // Regions and agents go into the head while the paragraphs are formatted, so the IDs of the body come later.
        $divs = [];
        foreach ($subtitle->getCues() as $cue) {
            $div        = $this->writableAttributes($context, $cue->findFormatData(TtmlParser::FORMAT_DATA_KEY)["div"] ?? [], []);
            $containers = [$body, $this->styleProperties($context, $styles, $div)];
            $paragraph  = [$this->paragraphId($cue), $this->formatParagraph($context, $styles, $containers, $cue, $options, $fileData === [])];
            if ($divs !== [] && $divs[count($divs) - 1]["attributes"] === $div) {
                $divs[count($divs) - 1]["paragraphs"][] = $paragraph;
            } else {
                $divs[] = ["attributes" => $div, "paragraphs" => [$paragraph]];
            }
        }

        $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" . LineEnding::Lf->value
                  . "<tt" . $this->formatRootAttributes($context, $subtitle, $fileData["attributes"] ?? []) . ">" . LineEnding::Lf->value
                  . "  " . $context->headDocument->saveXML($context->head) . LineEnding::Lf->value
                  . "  <body" . $this->formatAttributes($context, $fileData["body"] ?? [], []) . ">" . LineEnding::Lf->value;
        if ($divs === []) {
            $output .= "    <div/>" . LineEnding::Lf->value;
        }
        foreach ($divs as $div) {
            $output .= "    <div" . $this->formatWritableAttributes($context, $div["attributes"]) . ">" . LineEnding::Lf->value;
            foreach ($div["paragraphs"] as [$id, $paragraph]) {
                $idAttribute = $id === null ? "" : $this->formatAttribute("xml:id", TtmlHead::unusedId($context, $id));
                $output     .= "      <p$idAttribute$paragraph" . LineEnding::Lf->value;
            }
            $output .= "    </div>" . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions(XmlOutput::requireUtf8($output . "  </body>" . LineEnding::Lf->value . "</tt>" . LineEnding::Lf->value), $options);
    }


    private function formatRootAttributes(TtmlContext $context, Subtitle $subtitle, array $attributes): string
    {
        $output  = TtmlHead::namespaceDeclarations($context->namespaces);
        $output .= $this->formatAttribute("xml:lang", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE) ?? "");

        foreach ($attributes as $name => $value) {
            [$prefix, $localName] = $this->splitName($name);
            $isParameter = in_array($context->namespaces[$prefix] ?? null, TtmlNamespaces::PARAMETER, true);
            if ($this->isWritable($context, $name) && !($isParameter && in_array($localName, self::SKIPPED_ROOT_PARAMETERS, true))) {
                $output .= $this->formatAttribute($name, $name === "xml:id" ? TtmlHead::unusedId($context, $value) : $value);
            }
        }

        return $output;
    }


    private function paragraphId(SubtitleCue $cue): ?string
    {
        $identifier = $cue->findIdentifier();

        return $identifier !== null && preg_match("/^[A-Za-z_][\w.-]*$/", $identifier) ? $identifier : null;
    }


    /**
     * Returns the paragraph without "<p" and without xml:id, so that the caller adds the ID in output order.
     *
     * @param list<array<string, string>> $containers the style properties of <body> and <div>
     */
    private function formatParagraph(
        TtmlContext $context,
        TtmlStyles $styles,
        array $containers,
        SubtitleCue $cue,
        WriteOptions $options,
        bool $writeDefaultRegion
    ): string
    {
        $attributes  = $this->formatAttribute("begin", Markup::coreTimestamp($cue->getStart()));
        $attributes .= $this->formatAttribute("end", Markup::coreTimestamp($cue->getEnd()));

        $cueData   = $cue->findFormatData(TtmlParser::FORMAT_DATA_KEY);
        $stored    = $cueData["attributes"] ?? [];
        $realigned = ($cueData["alignment"] ?? Alignment::BottomCenter->value) !== $cue->getAlignment()->value;
        if ($realigned) {
            unset($stored["region"]);
        }
        $stored = $this->writableAttributes($context, $stored, ["xml:id", "begin", "end", "dur"]);
        if ($realigned) {
            $stored = $this->withTextAlign($context, $styles, $containers, $stored, $cue->getAlignment());
        }
        $forcedAttribute = $this->forcedDisplayAttributes($context, $stored, $cueData, $cue);
        $attributes     .= $this->formatWritableAttributes($context, $stored);
        if (!isset($stored["region"]) && ($cue->getAlignment() !== Alignment::BottomCenter || $writeDefaultRegion || $realigned)) {
            $attributes .= $this->formatAttribute("region", TtmlHead::regionId($context, $cue->getAlignment()));
        }
        $attributes .= $forcedAttribute;

        $text = Markup::rubyAsText(implode(LineEnding::Lf->value, $cue->getLines()));
        if ($options->stripTags) {
            return "$attributes>" . $this->formatText(Markup::stripAllTags($text)) . "</p>";
        }

        $inherited = TtmlStyles::DEFAULT_STYLE;
        $region    = $styles->regionProperties($stored["region"] ?? null);
        foreach ([$region, ...$containers, $this->styleProperties($context, $styles, $stored)] as $properties) {
            $inherited = TtmlStyles::apply($inherited, $properties);
        }
        [$agent, $content] = $this->markupToTtml($context, $text, $inherited);
        if ($agent !== null) {
            $attributes .= $this->formatAttribute("$context->ttm:agent", TtmlHead::agentId($context, $agent));
        }

        return "$attributes>$content</p>";
    }


    /**
     * A text alignment of the paragraph style or of a parent element wins over the region, so it must match the new alignment.
     *
     * @param list<array<string, string>> $containers the style properties of <body> and <div>
     */
    private function withTextAlign(TtmlContext $context, TtmlStyles $styles, array $containers, array $attributes, Alignment $alignment): array
    {
        $textAlign = null;
        foreach ([...$containers, $this->styleProperties($context, $styles, $attributes)] as $properties) {
            $textAlign = $properties["textAlign"] ?? $textAlign;
        }
        $column = ["left", "center", "right"][($alignment->value - 1) % 3];
        if ($textAlign !== null && trim($textAlign) !== $column) {
            $attributes["$context->tts:textAlign"] = $column;
        }

        return $attributes;
    }


    /**
     * Writes the forced flag of $cue where the file does not already give it. A stored itts:forcedDisplay attribute of
     * the paragraph gets the new value. Otherwise the flag comes from the div or the region, and a differing flag
     * gives a new attribute, which the method returns.
     *
     * @param array<string, string> $stored the writable attributes of the paragraph
     */
    private function forcedDisplayAttributes(TtmlContext $context, array &$stored, array $cueData, SubtitleCue $cue): string
    {
        $forcedName = $this->forcedDisplayName($context, $stored);
        $forced     = $forcedName === null
            ? $this->forcedDisplay($context, $cueData["div"] ?? []) ?? $context->forcedRegions[$stored["region"] ?? ""] ?? false
            : trim($stored[$forcedName]) === "true";
        if ($forced === $cue->isForced()) {
            return "";
        }
        $value = $cue->isForced() ? "true" : "false";
        if ($forcedName !== null) {
            $stored[$forcedName] = $value;

            return "";
        }

        return $this->formatAttribute($this->declareImscStylingPrefix($context) . ":forcedDisplay", $value);
    }


    /**
     * Returns the name of the stored itts:forcedDisplay attribute, or null when the attributes do not hold it.
     */
    private function forcedDisplayName(TtmlContext $context, array $attributes): ?string
    {
        foreach (array_keys($attributes) as $name) {
            [$prefix, $localName] = $this->splitName($name);
            if ($localName === "forcedDisplay" && $prefix !== ""
                && ($context->namespaces[$prefix] ?? null) === TtmlNamespaces::IMSC_STYLING) {
                return $name;
            }
        }

        return null;
    }


    private function forcedDisplay(TtmlContext $context, array $attributes): ?bool
    {
        $name = $this->forcedDisplayName($context, $attributes);

        return $name === null ? null : trim($attributes[$name]) === "true";
    }


    /**
     * Declares the IMSC styling namespace on the root element when the input file did not, and returns its prefix.
     */
    private function declareImscStylingPrefix(TtmlContext $context): string
    {
        $prefix = TtmlHead::bindPrefix($context->namespaces, "itts", [TtmlNamespaces::IMSC_STYLING], 0);
        ksort($context->namespaces);

        return $prefix;
    }


    /**
     * Returns the speaker of the whole cue separately, so that it goes on the paragraph.
     *
     * @return array{?string, string}
     */
    private function markupToTtml(TtmlContext $context, string $text, array $inherited): array
    {
        [$agent, $tokens] = $this->takeParagraphAgent(Markup::splitTags($text), $text);

        $output = "";
        $stack  = [];
        $resets = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                $output .= $this->formatText($token);
                if (trim($token) !== "") {
                    $resets += $this->resets($inherited, $stack);
                }
                continue;
            }

            if (preg_match("/^<\/([a-zA-Z]+)\s*>$/", $token, $matches)) {
                $output .= $this->closeSpan($stack, strtolower($matches[1]));
                continue;
            }
            if (!preg_match("/^<([a-zA-Z]+)([\s.][^>]*)?>$/", $token, $matches)) {
                continue;
            }

            $tag = strtolower($matches[1]);
            if ($tag === "v") {
                $output .= $this->closeSpan($stack, "v");
            }
            $span = $this->openSpan($context, $tag, $matches[2] ?? "");
            if ($span !== null) {
                $stack[] = ["tag" => $tag, "span" => $span];
                $output .= $span;
            }
        }

        while ($stack !== []) {
            $output .= array_pop($stack)["span"] === "" ? "" : "</span>";
        }
        if ($resets !== []) {
            $output = "<span" . $this->resetAttributes($context, $resets) . ">$output</span>";
        }

        return [$agent, $output];
    }


    /**
     * Takes the speaker out of the tokens when one <v> tag at the start covers the whole cue.
     * Returns the speaker, or null, and the remaining tokens.
     *
     * @param list<string> $tokens the text and tags of $text, from Markup::splitTags()
     *
     * @return array{?string, list<string>}
     */
    private function takeParagraphAgent(array $tokens, string $text): array
    {
        if (preg_match("/^" . Markup::VOICE_TAG . "$/", $tokens[1] ?? "", $matches) && trim($tokens[0]) === ""
            && preg_match_all("/<\/?v[\s.>]/", $text) === 1) {
            return [Markup::decodeEntities(trim($matches[2])), array_slice($tokens, 2)];
        }

        return [null, $tokens];
    }


    /**
     * Returns the inherited styles that the open spans do not repeat. Core markup has no tag that turns a style off.
     *
     * @return array<string, true>
     */
    private function resets(array $inherited, array $stack): array
    {
        $open = [];
        foreach ($stack as $entry) {
            if ($entry["span"] !== "") {
                $open[$entry["tag"] === "c" && str_contains($entry["span"], ":color=") ? "font" : $entry["tag"]] = true;
            }
        }

        $resets = [];
        foreach (["b", "i", "u", "s"] as $tag) {
            if ($inherited[$tag] && !isset($open[$tag])) {
                $resets[$tag] = true;
            }
        }
        if ($inherited["color"] !== null && !isset($open["font"])) {
            $resets["color"] = true;
        }

        return $resets;
    }


    /**
     * @param array<string, true> $resets
     */
    private function resetAttributes(TtmlContext $context, array $resets): string
    {
        $decorations = array_intersect_key(["u" => "noUnderline", "s" => "noLineThrough"], $resets);
        $values      = [
            "fontWeight"     => isset($resets["b"]) ? "normal" : null,
            "fontStyle"      => isset($resets["i"]) ? "normal" : null,
            "textDecoration" => $decorations === [] ? null : implode(" ", $decorations),
            "color"          => isset($resets["color"]) ? "white" : null,
        ];

        $output = "";
        foreach (array_filter($values, fn (?string $value): bool => $value !== null) as $name => $value) {
            $output .= $this->formatAttribute("$context->tts:$name", $value);
        }

        return $output;
    }


    /**
     * Resolves stored attributes through a detached element, so that style references and prefixes resolve as in
     * the parser.
     *
     * @return array<string, string>
     */
    private function styleProperties(TtmlContext $context, TtmlStyles $styles, array $attributes): array
    {
        $element = $context->headDocument->createElementNS($context->namespace, "p");
        foreach ($attributes as $name => $value) {
            [$prefix] = $this->splitName($name);
            if ($prefix === "") {
                $element->setAttribute($name, $value);
            } elseif ($prefix !== "xml") {
                $element->setAttributeNS($context->namespaces[$prefix], $name, $value);
            }
        }

        return $styles->ownProperties($element);
    }


    /**
     * Returns the opening span of a core markup tag, or "" for a tag without TTML style.
     * Returns null for a tag outside the core markup.
     */
    private function openSpan(TtmlContext $context, string $tag, string $rest): ?string
    {
        $style = match ($tag) {
            "b"     => ["fontWeight", "bold"],
            "i"     => ["fontStyle", "italic"],
            "u"     => ["textDecoration", "underline"],
            "s"     => ["textDecoration", "lineThrough"],
            default => null,
        };
        if ($style !== null) {
            return "<span" . $this->formatAttribute("$context->tts:$style[0]", $style[1]) . ">";
        }

        if ($tag === "font") {
            $color = Markup::decodeEntities(trim(MarkupColors::fontColor($rest) ?? ""));
            // A value that ttmlColor() cannot read stays as written, so that no color drops.
            $color = MarkupColors::ttmlColor($color) ?? $color;

            return $color === "" ? "" : "<span" . $this->formatAttribute("$context->tts:color", $color) . ">";
        }

        if ($tag === "c") {
            $colors     = MarkupColors::webVttClassColors(preg_replace('/\s.*$/s', "", $rest));
            $attributes = ($colors["color"] === null ? "" : $this->formatAttribute("$context->tts:color", $colors["color"]))
                . ($colors["background"] === null ? "" : $this->formatAttribute("$context->tts:backgroundColor", $colors["background"]));

            return $attributes === "" ? "" : "<span$attributes>";
        }

        if ($tag === "v") {
            $name = Markup::decodeEntities(trim(preg_replace("/^\.[^\s]*/", "", $rest)));

            return $name === "" ? "" : "<span" . $this->formatAttribute("$context->ttm:agent", TtmlHead::agentId($context, $name)) . ">";
        }

        return null;
    }


    /**
     * Reopens the spans above the closed tag, so that mis-nested core markup keeps its styles.
     */
    private function closeSpan(array &$stack, string $tag): string
    {
        $position = null;
        foreach ($stack as $index => $entry) {
            if ($entry["tag"] === $tag) {
                $position = $index;
            }
        }
        if ($position === null) {
            return "";
        }

        $output = "";
        $above  = array_slice($stack, $position + 1);
        for ($index = count($stack) - 1; $index >= $position; $index--) {
            $output .= $stack[$index]["span"] === "" ? "" : "</span>";
        }
        $stack = [...array_slice($stack, 0, $position), ...$above];
        foreach ($above as $entry) {
            $output .= $entry["span"];
        }

        return $output;
    }


    private function formatText(string $text): string
    {
        $text = htmlspecialchars(XmlOutput::text(Markup::decodeEntities($text)), ENT_XML1 | ENT_NOQUOTES, "UTF-8");

        return str_replace(LineEnding::Lf->value, "<br/>", $text);
    }


    private function formatAttributes(TtmlContext $context, array $attributes, array $skip): string
    {
        return $this->formatWritableAttributes($context, $this->writableAttributes($context, $attributes, $skip));
    }


    private function writableAttributes(TtmlContext $context, array $attributes, array $skip): array
    {
        return array_filter(
            $attributes,
            fn (string $name): bool => $this->isWritable($context, $name) && !in_array($name, $skip, true),
            ARRAY_FILTER_USE_KEY
        );
    }


    private function formatWritableAttributes(TtmlContext $context, array $attributes): string
    {
        $output = "";
        foreach ($attributes as $name => $value) {
            $output .= $this->formatAttribute($name, $name === "xml:id" ? TtmlHead::unusedId($context, $value) : $value);
        }

        return $output;
    }


    /**
     * Skips attributes whose prefix the output does not declare, for example a prefix declared on the paragraph.
     */
    private function isWritable(TtmlContext $context, string $name): bool
    {
        if (!str_contains($name, ":")) {
            return true;
        }
        [$prefix] = $this->splitName($name);

        return $prefix === "xml" || ($prefix !== "" && isset($context->namespaces[$prefix]));
    }


    /**
     * @return array{string, string} the prefix, "" for none, and the local name
     */
    private function splitName(string $name): array
    {
        return str_contains($name, ":") ? explode(":", $name, 2) : ["", $name];
    }


    private function formatAttribute(string $name, string $value): string
    {
        return TtmlHead::attribute($name, $value);
    }
}
