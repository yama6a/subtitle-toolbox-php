<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMElement;
use DOMNode;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\AlignmentGrid;
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
        if (isset($context->regionIds[$alignment->value])) {
            return $context->regionIds[$alignment->value];
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

        $id     = self::unusedId($context, lcfirst($alignment->name));
        $region = $context->headDocument->createElementNS($context->namespace, "region");
        $region->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $uri = $context->namespaces[$context->tts];
        $region->setAttributeNS($uri, "$context->tts:origin", "10% 10%");
        $region->setAttributeNS($uri, "$context->tts:extent", "80% 80%");
        $displayAlign = [AlignmentGrid::BOTTOM_ROW => "after", AlignmentGrid::MIDDLE_ROW => "center", AlignmentGrid::TOP_ROW => "before"];
        $textAlign    = [AlignmentGrid::LEFT => "left", AlignmentGrid::CENTER => "center", AlignmentGrid::RIGHT => "right"];
        $region->setAttributeNS($uri, "$context->tts:displayAlign", $displayAlign[AlignmentGrid::row($alignment)]);
        $region->setAttributeNS($uri, "$context->tts:textAlign", $textAlign[AlignmentGrid::column($alignment)]);
        self::insertChild($layout, $region, null, 3);

        return $context->regionIds[$alignment->value] = $id;
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
