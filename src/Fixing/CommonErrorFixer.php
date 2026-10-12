<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use SubtitleToolbox\CueList;
use SubtitleToolbox\DialogueDash;
use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\TextCase;

final class CommonErrorFixer
{
    // A next cue that starts within 0.6 s and with a lowercase letter continues the sentence of the cue before it.
    private const CONTINUATION_GAP = 0.6;

    private const NOT_IN_WORD  = '(?<![\p{L}\p{N}\'\x{2019}])';
    private const WORD_ENDS    = '(?![\p{L}\p{N}\'\x{2019}])';
    private const SPACES       = '[ \t\x{00A0}]';
    private const SENTENCE_END = '/(?:(?<!\.)\.|[?!])[\p{Pe}\p{Pf}"\']*$/u';
    private const L_CONSONANTS = [
        "en" => "bcdfghjkmnpqrstvwxz",
        "de" => "bcdfghjkmnpqrstvwxz",
        "es" => "bcdfghjkmnpqrstvwxz",
        "fr" => "bcdfghjklmnpqrstvwxz",
    ];


    /**
     * Fixes common text and OCR errors in the cue text and reports each change.
     */
    public static function apply(Subtitle $subtitle, ?CommonErrorOptions $options = null): CommonErrorReport
    {
        $options ??= new CommonErrorOptions();
        return self::run($subtitle, $options, change: true);
    }


    /**
     * Returns the report that apply() would return, and leaves the subtitle as it is.
     */
    public static function preview(Subtitle $subtitle, ?CommonErrorOptions $options = null): CommonErrorReport
    {
        $options ??= new CommonErrorOptions();
        return self::run($subtitle, $options, change: false);
    }


    private static function run(Subtitle $subtitle, CommonErrorOptions $options, bool $change): CommonErrorReport
    {
        $languageCode = $options->language ?? $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        $language     = self::language($languageCode);
        $turkic       = TextCase::isTurkic($languageCode);
        $cues         = $subtitle->getCues();
        $indexes      = array_keys($cues);
        $positions    = array_flip($indexes);
        $fixes        = [];
        $ends         = $options->sentenceStartCase ? array_map(fn (SubtitleCue $cue): bool =>
            preg_match(self::SENTENCE_END, Markup::visibleText(implode("\n", $cue->getLines()))) === 1, $cues) : [];

        $fixCue = function (SubtitleCue $cue, int $index)
            use ($cues, $indexes, $positions, $ends, $options, $language, $turkic, $change, &$fixes): ?array {
            $lines = array_values($cue->getLines());
            if ($lines === []) {
                return null;
            }

            $next      = $cues[$indexes[$positions[$index] + 1] ?? -1] ?? null;
            $continues = $next !== null && $next->getStart() - $cue->getEnd() <= self::CONTINUATION_GAP
                         && preg_match('/^\p{Ll}/u', implode("\n", Markup::plainLines($next->getLines()))) === 1;
            $startsSentence = $positions[$index] === 0 || ($ends[$indexes[$positions[$index] - 1]] ?? false);
            $context        = new CueFixContext($language, $turkic, $continues, $startsSentence);
            $original       = $lines;
            foreach (CommonErrorRule::cases() as $rule) {
                if (!$options->enables($rule, $language)) {
                    continue;
                }
                $fixed = self::applyRule($rule, $lines, $options, $context);
                if ($fixed !== $lines) {
                    $fixes[] = new AppliedFix($index, $rule, implode("\n", $lines), implode("\n", $fixed));
                    $lines   = $fixed;
                }
            }

            return $change && $lines !== $original ? $lines : null;
        };
        CueList::setLinesAndRemoveEmptied($subtitle, $fixCue, fn (array $lines): bool => Markup::plainLines($lines) !== []);

        return new CommonErrorReport($fixes);
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function applyRule(CommonErrorRule $rule, array $lines, CommonErrorOptions $options, CueFixContext $context): array
    {
        $language = $context->language;

        return match ($rule) {
            CommonErrorRule::ReplaceList     => OcrReplaceListFixer::replaceList($lines, $options->replaceList, $context->continues),
            CommonErrorRule::UnbalancedTags  => self::unbalancedTags($lines),
            CommonErrorRule::EmptyTags       => array_map(fn (string $line): string =>
                Markup::removeEmptyTagPairs($line, ignoreCase: true, withSpaces: true), $lines),
            CommonErrorRule::DoubleApostrophes => Markup::mapTextRuns($lines, fn (string $text): string =>
                self::replace("/''|\\x{2019}\\x{2019}/u", '"', $text)),
            CommonErrorRule::OcrPipe         => Markup::mapTextRuns($lines, fn (string $text): string => self::ocrPipe($text, $language)),
            CommonErrorRule::OcrZeroInWords  => Markup::mapTextRuns($lines, fn (string $text, bool $first): string => self::ocrZero($text, $first)),
            CommonErrorRule::OcrLowercaseL   => Markup::mapTextRuns($lines, fn (string $text): string => self::ocrLowercaseL($text, $language)),
            CommonErrorRule::LoneLowercaseI  => array_map(self::loneLowercaseI(...), $lines),
            CommonErrorRule::Ellipsis        => Markup::mapTextRuns($lines, fn (string $text): string => self::ellipsis($text, $options->ellipsis)),
            CommonErrorRule::UnneededPeriods => Markup::mapTextRuns($lines, fn (string $text): string =>
                self::replace('/(?<=[?!])\\.(?![.\\x{2026}])/u', "", $text)),
            CommonErrorRule::DoubleSpaces    => self::doubleSpaces($lines),
            CommonErrorRule::SpaceBeforePunctuation       => Markup::mapTextRuns($lines, fn (string $text): string =>
                self::spaceBeforePunctuation($text, $language)),
            CommonErrorRule::MissingSpaceAfterPunctuation => Markup::mapTextRuns($lines, fn (string $text): string =>
                self::missingSpaceAfterPunctuation($text)),
            CommonErrorRule::DialogueOnOneLine => self::dialogueOnOneLine($lines, $options->dialogueDashStyle),
            CommonErrorRule::DialogueDashes  => Markup::mapTextRuns($lines, fn (string $text, bool $first): string =>
                $first ? self::dialogueDash($text, $options->dialogueDashStyle) : $text),
            CommonErrorRule::MusicNotes      => array_map(self::musicNotes(...), $lines),
            CommonErrorRule::SentenceStartCase => self::sentenceStartCase($lines, $context->startsSentence, $context->turkic),
        };
    }


    private static function ellipsis(string $text, EllipsisStyle $style): string
    {
        return self::replace('/\.(?: ?\.){2,}' . ($style === EllipsisStyle::Unicode ? '|\x{2026}' : '') . '/u', $style->value, $text);
    }


    private static function dialogueDash(string $text, DialogueDashStyle $style): string
    {
        return self::replace(DialogueDash::REGEX, $style->value, $text);
    }


    /**
     * Splits one line, or two lines joined, at the one dialogue dash after a sentence end, as in "- Hi. - Hello.".
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function dialogueOnOneLine(array $lines, DialogueDashStyle $style): array
    {
        if (count($lines) > 2) {
            return $lines;
        }

        $joined  = implode(" ", $lines);
        $dash    = '[' . DialogueDash::CHARACTERS . ']';
        $tags    = '(?:' . Markup::TAG . ')*';
        $pattern = '/[.?!\x{2026}]["\'\x{2019}\x{201D})\]]*' . $tags . '(' . self::SPACES . '+)(?=' . $tags . $dash . '(?!' . $dash . ')'
                   . self::SPACES . '*[^\s\p{N}])/u';
        if (preg_match_all($pattern, $joined, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $lines;
        }

        [$spaces, $offset] = $matches[1][0];
        if (count($lines) === 2 && $offset === strlen($lines[0])) {
            return $lines;
        }

        $first   = substr($joined, 0, $offset);
        $open    = Markup::openCoreTags($first);
        $split   = [$first . Markup::closeCoreTags($open),
                    implode("", array_column($open, "tag")) . substr($joined, $offset + strlen($spaces))];

        return Markup::mapTextRuns($split, fn (string $text, bool $isFirst): string =>
            $isFirst && preg_match("/^\s*$dash/u", $text) !== 1 ? $style->value . ltrim($text) : $text);
    }


    /**
     * Replaces a "#" or "*" at the start or the end of the line text with U+266A. Tags, spaces and a dialogue dash may
     * stand between the sign and the line edge. A space or the line edge must follow a sign at the start and precede a
     * sign at the end, so "*sigh*" and "Room #5" stay.
     */
    private static function musicNotes(string $line): string
    {
        $edge = '(?:' . Markup::TAG . '|\s)*';
        $line = self::replace('/^(' . $edge . '(?:[' . DialogueDash::CHARACTERS . ']\s*)?)[#*](?=\s|' . $edge . '$)/u', "\$1\u{266A}", $line);

        return self::replace('/(?<=\s)[#*](' . $edge . ')$/u', "\u{266A}\$1", $line);
    }


    /**
     * Writes the first letter of each line that starts a sentence in upper case. A line starts a sentence when it is the
     * first line and $startsSentence is true, or when the line before it ends a sentence.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function sentenceStartCase(array $lines, bool $startsSentence, bool $turkic): array
    {
        foreach ($lines as $lineIndex => $line) {
            $starts = $lineIndex === 0 ? $startsSentence
                                       : preg_match(self::SENTENCE_END, Markup::visibleText($lines[$lineIndex - 1])) === 1;
            if ($starts) {
                $lines[$lineIndex] = self::capitalizeLine($line, $turkic);
            }
        }

        return $lines;
    }


    /**
     * Writes the first letter of $line in upper case. Dashes, quotes, music notes and tags before it stay. A word with an
     * upper case letter after the first, such as "iPhone", stays.
     */
    private static function capitalizeLine(string $line, bool $turkic): string
    {
        $opening = '[\s"\'\x{2018}\x{201C}\x{00AB}\x{00BF}\x{00A1}\x{266A}' . DialogueDash::CHARACTERS . ']*';
        $done    = false;

        return Markup::mapTextRuns([$line], function (string $text) use ($opening, $turkic, &$done): string {
            if ($done || preg_match("/^$opening$/u", $text) === 1) {
                return $text;
            }

            $done = true;
            if (preg_match("/^($opening)(\\p{Ll})([\\p{L}\\p{M}\\p{N}'\\x{2019}]*)/u", $text, $match) !== 1
                || preg_match('/\p{Lu}/u', $match[3]) === 1) {
                return $text;
            }

            $upper = $turkic && $match[2] === "i" ? "\u{0130}" : TextCase::upper($match[2], false);

            return $match[1] . $upper . substr($text, strlen($match[1]) + strlen($match[2]));
        })[0];
    }


    /**
     * Returns "en", "de", "fr" or "es" for a code such as "en-US" or "deu", and null for other languages.
     */
    private static function language(?string $code): ?string
    {
        return match (StringHelpers::primaryLanguage($code)) {
            "en", "eng"        => "en",
            "de", "deu", "ger" => "de",
            "fr", "fra", "fre" => "fr",
            "es", "spa"        => "es",
            default            => null,
        };
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function unbalancedTags(array $lines): array
    {
        $tags = Markup::unbalancedTags($lines, Markup::STYLE_TAGS);
        foreach (array_reverse($tags["stray"]) as [$lineIndex, $offset, $length]) {
            $lines[$lineIndex] = substr_replace($lines[$lineIndex], "", $offset, $length);
        }
        $last = count($lines) - 1;
        foreach (array_reverse($tags["open"]) as $name) {
            $lines[$last] .= "</$name>";
        }

        return $lines;
    }


    private static function ocrPipe(string $text, ?string $language): string
    {
        $offset = 0;
        while (($offset = strpos($text, "|", $offset)) !== false) {
            $before = substr($text, 0, $offset);
            $after  = substr($text, $offset + 1);
            $letter = match (true) {
                preg_match('/\p{Ll}$/u', $before) === 1                   => "l",
                preg_match('/\p{L}$/u', $before) === 1,
                preg_match('/^\p{L}/u', $after) === 1                     => "I",
                preg_match('/^[\'\x{2019}]\p{L}/u', $after) === 1         => ["en" => "I", "fr" => "l"][$language ?? ""] ?? "|",
                $language === "en" && preg_match('/(?:^|[\s"\'(' . DialogueDash::CHARACTERS . '])$/u', $before) === 1
                    && preg_match('/^(?:$|[\s.,!?;:"])/u', $after) === 1  => "I",
                default                                                    => "|",
            };
            $text[$offset] = $letter;
            $offset++;
        }

        return $text;
    }


    private static function ocrZero(string $text, bool $startsLine): string
    {
        $pattern = '/[\p{L}\p{N}\'\x{2019}]*0[\p{L}\p{N}\'\x{2019}]*/u';

        return self::replaceCallback($pattern, function (array $match) use ($text, $startsLine): string {
            [$word, $offset] = $match[0];
            if (preg_match_all('/\p{N}/u', $word) !== substr_count($word, "0") || preg_match_all('/\p{L}/u', $word) < 2) {
                return $word;
            }

            $before        = substr($text, 0, $offset);
            $opening       = '[\s"\'\x{00BF}\x{00A1}' . DialogueDash::CHARACTERS . ']*$';
            $sentenceStart = ($startsLine && preg_match("/^$opening/u", $before) === 1)
                             || preg_match("/[.!?]\\s+$opening/u", $before) === 1;
            $result        = str_replace("0", preg_match('/\p{Ll}/u', $word) === 1 ? "o" : "O", $word);

            return $sentenceStart && $word[0] === "0" ? "O" . substr($result, 1) : $result;
        }, $text, true);
    }


    private static function ocrLowercaseL(string $text, ?string $language): string
    {
        $text = self::replaceCallback('/' . self::NOT_IN_WORD . '[\p{L}\'\x{2019}]*l[\p{L}\'\x{2019}]*' . self::WORD_ENDS . '/u',
            fn (array $match): string =>
                preg_match('/(?!l)\p{Ll}/u', $match[0]) !== 1
                && (preg_match_all('/\p{Lu}/u', $match[0]) >= 2 || preg_match('/l\p{Lu}/u', $match[0]) === 1)
                    ? str_replace("l", "I", $match[0])
                    : $match[0],
            $text);

        if (!isset(self::L_CONSONANTS[$language ?? ""])) {
            return $text;
        }

        $start = self::NOT_IN_WORD . '(?<!\p{N} )';
        if ($language === "en") {
            $text   = self::replace('/' . $start . 'l(?=[\'\x{2019}](?:m|ll|ve|d)' . self::WORD_ENDS . ')/u', "I", $text);
            $text   = self::replace('/' . $start . '(?<![^\s"\'(\[\x{00BF}\x{00A1}-])l(?=$|[\s.,!?;:"\')\]\x{2026}-])/u', "I", $text);
            $start .= '(?!l(?:bs?|td)' . self::WORD_ENDS . ')';
        }

        return self::replace('/' . $start . 'l(?=[' . self::L_CONSONANTS[$language] . '])/u', "I", $text);
    }


    /**
     * Writes the pronoun "i" and "i'm", "i'll", "i've" and "i'd" in upper case. The letters around it may be in other text runs.
     * A line that is not valid UTF-8 stays as it is.
     */
    private static function loneLowercaseI(string $line): string
    {
        $tokens = Markup::splitTags($line);
        $runs   = array_filter($tokens, fn (int $index): bool => $index % 2 === 0, ARRAY_FILTER_USE_KEY);

        return implode("", array_replace($tokens, Markup::upperCaseEnglishI($runs, false)));
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function doubleSpaces(array $lines): array
    {
        $previousSpace = false;

        return Markup::mapTextRuns($lines, function (string $text, bool $first) use (&$previousSpace): string {
            $text = self::replace('/' . self::SPACES . '{2,}/u', " ", $text);
            if (!$first && $previousSpace) {
                $text = self::replace('/^' . self::SPACES . '+/u', "", $text);
            }
            $previousSpace = preg_match('/' . self::SPACES . '$/u', $text) === 1 || ($previousSpace && $text === "");

            return $text;
        });
    }


    private static function spaceBeforePunctuation(string $text, ?string $language): string
    {
        $end   = '(?:[\s"\'\x{2019}\x{00BB})\]]|[,.!?;:]|$)';
        $marks = $language === "fr" ? "(?:,|\\.(?!\\.))$end" : "(?:(?:[,!?]|\\.(?!\\.))$end|[;:](?:\\s|$))";

        return self::replace('/(?<=[\p{L}\p{N}"\'\x{2019}\x{00BB})\]])' . self::SPACES . "+(?=$marks)/u", "", $text);
    }


    private static function missingSpaceAfterPunctuation(string $text): string
    {
        $text = self::replace('/(?<=\p{L})([,;]|[!?]+)(?=[\p{L}\x{00BF}\x{00A1}])/u', '$1 ', $text);
        $text = self::replace('/(?<=\p{L}\p{L})\.(?=\p{Lu}\p{Ll})/u', ". ", $text);

        return self::replace('/(?<=\p{L})(\.\.\.|\x{2026})(?=\p{L})/u', '$1 ', $text);
    }


    /**
     * Returns $text unchanged when it is not valid UTF-8.
     */
    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? $text;
    }


    private static function replaceCallback(string $pattern, callable $fn, string $text, bool $offsets = false): string
    {
        return preg_replace_callback($pattern, $fn, $text, -1, $count, $offsets ? PREG_OFFSET_CAPTURE : 0) ?? $text;
    }
}
