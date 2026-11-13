<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

final class IttParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Itt->value;

    private const TIMING_PARAMETERS = ["timeBase", "frameRate", "frameRateMultiplier", "dropMode"];


    /**
     * Parses the file as TTML and copies the SMPTE timing parameters of the root element to the `itt` format data.
     */
    protected function read(string $content): Subtitle
    {
        $subtitle       = (new TtmlParser())->parse($content, $this->options);
        $this->warnings = $subtitle->getParseWarnings();
        $ttmlData       = $subtitle->findFormatData(TtmlParser::FORMAT_DATA_KEY);

        $parameters = [];
        foreach ($ttmlData["attributes"] ?? [] as $name => $value) {
            [$prefix, $localName] = str_contains($name, ":") ? explode(":", $name, 2) : ["", $name];
            $namespace            = $ttmlData["namespaces"][$prefix] ?? null;
            if (in_array($localName, self::TIMING_PARAMETERS, true)
                && (in_array($namespace, TtmlNamespaces::PARAMETER, true) || $namespace === null && $prefix === "ttp")) {
                // TtmlParser reads the times with the first of 2 same-name parameters, such as ttp:frameRate in 2 namespaces.
                $parameters[$localName] ??= trim($value);
            }
        }
        $parameters = array_intersect_key(array_replace(array_flip(self::TIMING_PARAMETERS), $parameters), $parameters);
        if ($parameters !== []) {
            $subtitle->setFormatData(self::FORMAT_DATA_KEY, $parameters);
        }

        return $subtitle;
    }
}
