<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMElement;
use DOMNode;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\AlignmentGrid;
use SubtitleToolbox\CuePosition;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\TtmlNamespaces;
use SubtitleToolbox\XmlLoader;

/**
 * Loads the stored TTML head and adds the title, agents and regions that TtmlFormatter needs. It hands out each xml:id.
 *
 * @internal
 */
final class TtmlHead
{
    public static function load(string $namespace, array $stored, string $headXml): TtmlContext
    {
        $namespaces = ["" => $namespace];
        foreach ($stored as $prefix => $uri) {
            if ($prefix !== "") {
                $namespaces[$prefix] = $uri;
            }
        }

        [$styling, $metadata] = match ($namespace) {
            TtmlNamespaces::DFXP         => [1, 1],
            TtmlNamespaces::DFXP_2006_04 => [3, 2],
            default                      => [0, 0],
        };
        $tts = self::bindPrefix($namespaces, "tts", TtmlNamespaces::STYLING, $styling);
        $ttm = self::bindPrefix($namespaces, "ttm", TtmlNamespaces::METADATA, $metadata);
        ksort($namespaces);

        $declarations = self::namespaceDeclarations($namespaces);
        $document     = XmlLoader::xml("<tt$declarations>$headXml</tt>");
        $head         = $document?->documentElement->firstChild;
        if (!$head instanceof DOMElement || $head->localName !== "head") {
            throw new UnwritableContentException("The stored TTML head is not a well-formed <head> element.");
        }

        $context = new TtmlContext($namespace, $namespaces, $tts, $ttm, $document, $head);
        foreach ($head->getElementsByTagName("*") as $element) {
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            if ($id !== "") {
                $context->usedIds[$id] = true;
            }
            if ($element->localName === "region" && $element->namespaceURI === $namespace
                && $element->hasAttributeNS(TtmlNamespaces::IMSC_STYLING, "forcedDisplay")) {
                $context->forcedRegions[$id] ??= trim($element->getAttributeNS(TtmlNamespaces::IMSC_STYLING, "forcedDisplay")) === "true";
            }
            if ($element->localName === "agent" && in_array($element->namespaceURI, TtmlNamespaces::METADATA, true)) {
                foreach ($element->getElementsByTagNameNS($element->namespaceURI, "name") as $name) {
                    $context->agentIds[trim($name->textContent)] ??= $id;
                }
            }
        }

        return $context;
    }


    public static function bindPrefix(array &$namespaces, string $prefix, array $uris, int $preferred): string
    {
        foreach ($namespaces as $boundPrefix => $uri) {
            if ($boundPrefix !== "" && in_array($uri, $uris, true)) {
                return $boundPrefix;
            }
        }

        $candidate = $prefix;
        for ($index = 2; isset($namespaces[$candidate]); $index++) {
            $candidate = $prefix . $index;
        }
        $namespaces[$candidate] = $uris[$preferred];

        return $candidate;
    }


    public static function addTitle(TtmlContext $context, string $title): void
    {
        $element = $context->headDocument->createElementNS($context->namespaces[$context->ttm], "$context->ttm:title");
        $element->appendChild($context->headDocument->createTextNode(XmlOutput::text($title)));
        self::insertChild($context->head, $element, $context->head->firstChild, 2);
    }


    public static function agentId(TtmlContext $context, string $name): string
    {
        if (isset($context->agentIds[$name])) {
            return $context->agentIds[$name];
        }

        $id      = self::unusedId($context, "agent" . (count($context->agentIds) + 1));
        $uri     = $context->namespaces[$context->ttm];
        $element = $context->headDocument->createElementNS($uri, "$context->ttm:agent");
        $element->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $element->setAttribute("type", "person");
        $nameElement = $context->headDocument->createElementNS($uri, "$context->ttm:name");
        $nameElement->setAttribute("type", "full");
        $nameElement->appendChild($context->headDocument->createTextNode(XmlOutput::text($name)));
        $element->appendChild($nameElement);

        $before = null;
        foreach ($context->head->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, ["styling", "layout"], true)) {
                $before = $child;
                break;
            }
        }
        self::insertChild($context->head, $element, $before, 2);

        return $context->agentIds[$name] = $id;
    }


    /**
     * Adds a region for the alignment, in the shape of the IMSC 1.1 examples with a safe area of 10%.
     */
    public static function regionId(TtmlContext $context, Alignment $alignment): string
    {
        return self::addRegion($context, lcfirst($alignment->name), "10% 10%", "80% 80%", $alignment);
    }


    /**
     * Adds a region whose anchor for the alignment is the point $position. The region reaches from the point to the
     * edges of the screen, so it stays inside the root container.
     */
    public static function positionRegionId(TtmlContext $context, Alignment $alignment, CuePosition $position): string
    {
        [$originX, $extentX] = self::span(AlignmentGrid::roundPercent($position->x), AlignmentGrid::column($alignment) - 1);
        [$originY, $extentY] = self::span(AlignmentGrid::roundPercent($position->y), AlignmentGrid::TOP_ROW - AlignmentGrid::row($alignment));
        $name = lcfirst($alignment->name) . "-" . AlignmentGrid::formatPercent($position->x) . "-" . AlignmentGrid::formatPercent($position->y);

        return self::addRegion($context, $name, "$originX $originY", "$extentX $extentY", $alignment);
    }


    /**
     * Returns the origin and the extent of a region on one axis, with the anchor at $value percent. $side 0 anchors
     * the start of the region, 1 its middle and 2 its end.
     *
     * @return array{string, string}
     */
    private static function span(float $value, int $side): array
    {
        [$start, $length] = match ($side) {
            0       => [$value, 100 - $value],
            1       => [$value - min($value, 100 - $value), 2 * min($value, 100 - $value)],
            default => [0.0, $value],
        };

        return [AlignmentGrid::formatPercent($start) . "%", AlignmentGrid::formatPercent($length) . "%"];
    }


    private static function addRegion(TtmlContext $context, string $name, string $origin, string $extent, Alignment $alignment): string
    {
        if (isset($context->regionIds[$name])) {
            return $context->regionIds[$name];
        }

        $layout = null;
        foreach ($context->head->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === "layout" && $child->namespaceURI === $context->namespace) {
                $layout = $child;
            }
        }
        if ($layout === null) {
            $layout = $context->headDocument->createElementNS($context->namespace, "layout");
            self::insertChild($context->head, $layout, null, 2);
        }

        $id     = self::unusedId($context, $name);
        $region = $context->headDocument->createElementNS($context->namespace, "region");
        $region->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $uri = $context->namespaces[$context->tts];
        $region->setAttributeNS($uri, "$context->tts:origin", $origin);
        $region->setAttributeNS($uri, "$context->tts:extent", $extent);
        $displayAlign = [AlignmentGrid::BOTTOM_ROW => "after", AlignmentGrid::MIDDLE_ROW => "center", AlignmentGrid::TOP_ROW => "before"];
        $textAlign    = [AlignmentGrid::LEFT => "left", AlignmentGrid::CENTER => "center", AlignmentGrid::RIGHT => "right"];
        $region->setAttributeNS($uri, "$context->tts:displayAlign", $displayAlign[AlignmentGrid::row($alignment)]);
        $region->setAttributeNS($uri, "$context->tts:textAlign", $textAlign[AlignmentGrid::column($alignment)]);
        self::insertChild($layout, $region, null, 3);

        return $context->regionIds[$name] = $id;
    }


    public static function unusedId(TtmlContext $context, string $id): string
    {
        $candidate = $id;
        for ($index = 2; isset($context->usedIds[$candidate]); $index++) {
            $candidate = $id . "_" . $index;
        }
        $context->usedIds[$candidate] = true;

        return $candidate;
    }


    private static function insertChild(DOMElement $parent, DOMElement $element, ?DOMNode $before, int $depth): void
    {
        $document = $parent->ownerDocument;
        if (!$parent->hasChildNodes()) {
            $parent->appendChild($document->createTextNode(LineEnding::Lf->value . str_repeat("  ", $depth - 1)));
        }
        if ($before === null) {
            $last   = $parent->lastChild;
            $before = $last->nodeType === XML_TEXT_NODE && trim($last->nodeValue) === ""
                ? $last
                : $parent->appendChild($document->createTextNode(LineEnding::Lf->value . str_repeat("  ", $depth - 1)));
        } elseif ($before->previousSibling !== null && $before->previousSibling->nodeType === XML_TEXT_NODE
                  && trim($before->previousSibling->nodeValue) === "" && $before !== $parent->firstChild) {
            $before = $before->previousSibling;
        }

        $parent->insertBefore($document->createTextNode(LineEnding::Lf->value . str_repeat("  ", $depth)), $before);
        $parent->insertBefore($element, $before);
    }


    /**
     * @param array<string, string> $namespaces
     */
    public static function namespaceDeclarations(array $namespaces): string
    {
        $output = "";
        foreach ($namespaces as $prefix => $uri) {
            $output .= self::attribute($prefix === "" ? "xmlns" : "xmlns:$prefix", $uri);
        }

        return $output;
    }


    public static function attribute(string $name, string $value): string
    {
        return " $name=\"" . htmlspecialchars(XmlOutput::text($value), ENT_XML1 | ENT_COMPAT, "UTF-8") . "\"";
    }
}
