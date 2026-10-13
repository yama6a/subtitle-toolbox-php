<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/** @internal */
final class FormatDetector
{
    private const LRC_TIMESTAMP = '\[[ \t]*(?:\d{1,2}:(?=[0-5]?\d:))?\d{1,3}:[0-5]\d(?:\.\d{1,3})?[ \t]*\]';

    private const SUBVIEWER_TIMING = '\d{2}:\d{2}:\d{2}\.\d{2},\d{2}:\d{2}:\d{2}\.\d{2}';

    // Two hour digits and a comma, so headerless WebVTT and SBV with 0:00:01,500 times do not match.
    private const SUBRIP_TIMING = '\d{2,}:\d{2}:\d{2},\d+[ \t]*-->';

    private const SPRUCE_STL_TIMING = '\d{2}:[0-5]\d:[0-5]\d:\d{2}[ \t]*,[ \t]*\d{2}:[0-5]\d:[0-5]\d:\d{2}[ \t]*,';

    private const XML_PROLOG = '(?:\s|<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>]*>)*';

    /**
     * The signatures of the text and binary formats, keyed by format. Order matters, first match wins.
     * Each pattern runs on the content without a UTF-8 BOM, with LF line endings and without leading white space.
     */
    private const SIGNATURES = [
        Format::WebVtt->value   => '/\AWEBVTT(?:[ \t\n]|\z)/',
        Format::Ttml->value     => '/\A' . self::XML_PROLOG . '<(?:[A-Za-z_][\w.-]*:)?tt[\s>\/]/s',
        Format::Sami->value     => '/\A' . self::XML_PROLOG . '<SAMI[\s>]/is',
        Format::Ass->value      => '/\A\[Script Info\][ \t]*$/im',   // before LRC, which also starts with [
        Format::MpSub->value    => '/\A(?=[A-Z]+=).*?^FORMAT=/ms',
        Format::MicroDvd->value => '/\A\{\d+\}\{\d*\}/',
        Format::QuickTimeText->value => '/\A\{QTtext\}/i',
        Format::SubRip->value   => '/\A\d+[ \t]*\n[ \t]*\d+:\d{2}:\d{2}(?:[,.]\d+)?[ \t]*-->' .   // after WebVTT, which looks the same without its header
                                   '|\A(?:[^\n]*\n){0,5}?[ \t]*\d+[ \t]*\n[ \t]*' . self::SUBRIP_TIMING . '|\A' . self::SUBRIP_TIMING . '/',
        Format::Sbv->value      => '/\A\d+:\d{2}:\d{2}\.\d{3},\d+:\d{2}:\d{2}\.\d{3}[ \t]*$/m',
        Format::SubViewer->value => '/^\*{8} START SCRIPT \*{8}[ \t]*$' .   // after SBV, and before LRC, which also matches [00:00:01]
                                    '|\A(?:\[INFORMATION\]|(?:\[.*\n)*' . self::SUBVIEWER_TIMING . ')[ \t]*$/m',
        Format::Lyrics->value   => '/\A(?=' . self::LRC_TIMESTAMP . '|\[[A-Za-z#][A-Za-z0-9_]*:[^\]\n]*\]).*?^[ \t]*' .
                                   self::LRC_TIMESTAMP . '/ms',
        Format::Pgs->value      => '/\APG.{8}[\x14-\x17\x80]/s',
        Format::EbuStl->value   => '/\A\d{3}STL(?:25|30)\.01/',
        // Without a $ or // line first, a quote after the times marks a headerless CSV row.
        Format::SpruceStl->value => '/\A(?:(?:\$|\/\/)[^\n]*\n[ \t\n]*)+' . self::SPRUCE_STL_TIMING . '|\A' . self::SPRUCE_STL_TIMING . '(?![ \t]*\x22)/',
        Format::Scc->value      => '/\AScenarist_SCC V1\.0[ \t]*$/m',
        Format::Mcc->value      => '/\AFile Format=MacCaption_MCC V\d+\.\d+[ \t]*$/m',
        Format::YouTubeTimedText->value => '/\A' . self::XML_PROLOG . '<(?:timedtext|transcript)[\s>\/]/s',
        Format::Mpl2->value     => '/\A\[\d+\]\[\d+\]/',
        Format::TmPlayer->value => '/\A\d+:[0-5]\d:[0-5]\d(?:,\d+)?(?:=|:(?!\d{1,2}[ \t]*,|\d+:\d\d:\d\d))/',   // not hh:mm:ss:ff frame timecodes, as in Spruce STL and CSV
        Format::HtmlTranscript->value => '/\A' . self::XML_PROLOG . '(?=<)(?=.*?<cite[\s>])(?=.*?<time[\s>])/is',   // last, as TTML, SAMI and YouTube XML can hold <cite> and <time>
    ];


    /**
     * Returns the subtitle format of $content, or null when no format matches. It never returns chapters or cloud
     * speech-to-text JSON. Content that starts with `{` and is a JSON object goes to the JSON key checks only.
     */
    public static function detect(string $content): ?Format
    {
        $content = ltrim(StringHelpers::removeUtf8Bom($content));

        if (str_starts_with($content, "{")) {
            $data = json_decode($content, false, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($data instanceof \stdClass) {
                return self::detectJson($data);
            }
        }

        $content = StringHelpers::normalizeEOLs($content);
        foreach (self::SIGNATURES as $format => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return Format::from($format);
            }
        }

        return null;
    }


    private static function detectJson(\stdClass $data): ?Format
    {
        $version  = $data->version ?? null;
        $segments = $data->segments ?? null;
        $events   = $data->events ?? null;

        return match (true) {
            (is_int($version) || is_float($version)) && is_array($data->cues ?? null) => Format::Json,
            // Podcasting 2.0 JSON comes before Whisper JSON, which also has a "segments" list.
            self::firstItemHas($segments, "startTime", "body")                         => Format::PodcastTranscript,
            is_array($segments) || is_array($data->transcription ?? null)              => Format::Whisper,
            self::firstItemHas($events, "tStartMs")                                    => Format::YouTubeTimedText,
            default                                                                    => null,
        };
    }


    private static function firstItemHas(mixed $list, string ...$keys): bool
    {
        $first = is_array($list) ? ($list[0] ?? null) : null;
        if (!$first instanceof \stdClass) {
            return false;
        }
        foreach ($keys as $key) {
            if (!property_exists($first, $key)) {
                return false;
            }
        }

        return true;
    }
}
