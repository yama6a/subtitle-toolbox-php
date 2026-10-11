<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\TtmlNamespaces;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * @see https://help.apple.com/itc/videoaudioassetguide/en.lproj/static.html
 */
final class IttFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = IttWriteOptions::class;

    private const TIME_PATTERN = "%02d:%02d:%02d:%02d";

    private const HEAD_LINES = [
        "<head>",
        "    <styling>",
        "      <style xml:id=\"normal\" tts:fontFamily=\"sansSerif\" tts:fontWeight=\"normal\" tts:fontStyle=\"normal\" tts:color=\"white\" tts:fontSize=\"100%\"/>",
        "    </styling>",
        "    <layout>",
        "      <region xml:id=\"top\" tts:origin=\"0% 0%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\" tts:displayAlign=\"before\"/>",
        "      <region xml:id=\"bottom\" tts:origin=\"0% 85%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\" tts:displayAlign=\"after\"/>",
        "    </layout>",
        "  </head>",
    ];

    // TTML 1, section 8.3.13, named colors.
    private const NAMED_COLORS = [
        "black", "silver", "gray", "white", "maroon", "red", "purple", "fuchsia", "magenta",
        "green", "lime", "olive", "yellow", "navy", "blue", "teal", "aqua", "cyan",
    ];


    /**
     * Writes an Apple iTunes Timed Text file with SMPTE times, one div, and a top and a bottom region.
     *
     * IttWriteOptions::$frameRate wins over the frame rate of the `itt` format data.
     *
     * @throws InvalidArgumentException when neither IttWriteOptions nor the `itt` format data gives a supported frame rate.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fps                         = $this->formatOptions($options)->frameRate;
        [$ttpFrameRate, $multiplier] = $this->frameRateParameters($subtitle->findFormatData(IttParser::FORMAT_DATA_KEY), $fps);
        $frameRate                   = new FrameRate((float) $ttpFrameRate * $this->multiplierFactor($multiplier));

        $ttml = $this->toTtmlSubtitle($subtitle);
        $xml  = (new TtmlFormatter())->format($ttml, new WriteOptions(stripTags: $options->stripTags));

        $document = XmlOutput::load($xml);
        $root     = $document->documentElement;
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:timeBase", "smpte");
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:frameRate", $ttpFrameRate);
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:frameRateMultiplier", $multiplier);
        $root->setAttributeNS(TtmlNamespaces::PARAMETER[0], "ttp:dropMode", "nonDrop");

        $cues       = $ttml->getCues();
        $paragraphs = $document->getElementsByTagNameNS(TtmlNamespaces::TTML, "p");
        foreach ($paragraphs as $index => $paragraph) {
            $begin = $frameRate->secondsToFrames(max(0.0, $cues[$index]->getStart()));
            $end   = max($begin + 1, $frameRate->secondsToFrames(max(0.0, $cues[$index]->getEnd())));
            $paragraph->setAttribute("begin", sprintf(self::TIME_PATTERN, ...Timecode::frameNumber($begin, $frameRate)));
            $paragraph->setAttribute("end", sprintf(self::TIME_PATTERN, ...Timecode::frameNumber($end, $frameRate)));
        }

        return $this->applyOutputOptions($document->saveXML(), $options);
    }


    /**
     * @return array{string, string} ttp:frameRate and ttp:frameRateMultiplier
     */
    private function frameRateParameters(array $ittData, ?float $fps): array
    {
        $stored = null;
        if (isset($ittData["frameRate"])) {
            $multiplier = $ittData["frameRateMultiplier"] ?? "1 1";
            $key        = IttFrameRates::key((float) $ittData["frameRate"] * $this->multiplierFactor($multiplier));
            if ($key !== null && IttFrameRates::PARAMETERS[$key][0] === $ittData["frameRate"]) {
                $stored = [$key, [$ittData["frameRate"], $multiplier]];
            }
        }

        if ($fps === null) {
            return $stored[1] ?? throw new InvalidArgumentException("The ITT formatter needs a frame rate. Set IttWriteOptions::\$frameRate.");
        }
        $optionKey = IttFrameRates::key($fps);

        // Keeps a parsed multiplier such as "1000 1001" when the option names the same frame rate.
        return $stored !== null && $stored[0] === $optionKey ? $stored[1] : IttFrameRates::PARAMETERS[$optionKey];
    }


    private function multiplierFactor(string $multiplier): float
    {
        $parts = preg_split("/[\s:]+/", trim($multiplier));

        return count($parts) === 2 && (float) $parts[0] > 0 && (float) $parts[1] > 0 ? (float) $parts[0] / (float) $parts[1] : 1.0;
    }


    /**
     * Copies the cues with the Apple regions and the core markup that ITT supports, so that TtmlFormatter writes the body.
     */
    private function toTtmlSubtitle(Subtitle $subtitle): Subtitle
    {
        $ttml = new Subtitle();
        $ttml->setMetadata(Subtitle::METADATA_LANGUAGE, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $ttml->setMetadata(Subtitle::METADATA_TITLE, $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $ttml->setFormatData(TtmlParser::FORMAT_DATA_KEY, [
            "namespace"  => TtmlNamespaces::TTML,
            "namespaces" => ["ttp" => TtmlNamespaces::PARAMETER[0]],
            "head"       => implode(LineEnding::Lf->value, self::HEAD_LINES),
            "body"       => ["style" => "normal"],
        ]);

        $copies = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = array_map(fn (string $line): string => $this->keepSupportedMarkup($line), $cue->getLines());
            $copy  = (new SubtitleCue($cue->getStart(), $cue->getEnd(), $lines))
                ->setIdentifier($cue->getIdentifier())
                ->setForced($cue->isForced());
            $copy->setFormatData(TtmlParser::FORMAT_DATA_KEY, [
                "attributes" => ["region" => in_array($cue->getAlignment(), [Alignment::TopLeft, Alignment::TopCenter, Alignment::TopRight], true) ? "top" : "bottom"],
            ]);
            $copies[] = $copy;
        }
        $ttml->addCues($copies);

        return $ttml;
    }


    private function keepSupportedMarkup(string $line): string
    {
        $line = Markup::keepTags(Markup::webVttColorsToFont(Markup::rubyAsText($line)), ["b", "i", "u", "font"]);

        return preg_replace_callback(
            "/<font\b([^>]*)>/i",
            function (array $matches): string {
                $color = Markup::fontColor($matches[1]);
                if ($color === null) {
                    return "<font>";
                }
                $color = strtolower(trim(Markup::decodeEntities($color)));
                if (preg_match("/^#[0-9a-f]{6}([0-9a-f]{2})?$/", $color)) {
                    return "<font color=\"" . substr($color, 0, 7) . "\">";
                }

                return in_array($color, self::NAMED_COLORS, true) ? "<font color=\"$color\">" : "<font>";
            },
            $line
        );
    }
}
