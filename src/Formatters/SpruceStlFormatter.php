<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Formatters\Options\SpruceStlWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\SpruceStl;
use SubtitleToolbox\Parsers\SpruceStlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class SpruceStlFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = SpruceStlWriteOptions::class;

    private const DEFAULT_FRAME_RATE = 25.0;

    // DVD Studio Pro can fail to import a file without these commands.
    private const DEFAULT_HEADER = [
        '$FontName = Arial',
        '$FontSize = 42',
        '$HorzAlign = Center',
        '$VertAlign = Bottom',
        '$XOffset = 0',
        '$YOffset = 0',
        '$Bold = False',
        '$Italic = False',
        '$Underlined = False',
        '$TextContrast = 15',
        '$Outline1Contrast = 15',
        '$Outline2Contrast = 15',
        '$BackgroundContrast = 0',
        '$ForceDisplay = False',
        '$FadeIn = 0',
        '$FadeOut = 0',
        '$TapeOffset = False',
        '',
    ];

    private const DEFAULT_SEPARATOR = " , ";

    // The frame number of a cue line has 2 digits.
    private const MAX_FRAME_RATE = 100;


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData  = $subtitle->findFormatData(SpruceStlParser::FORMAT_DATA_KEY) ?? [];
        $frameRate = (new FrameRate($this->formatOptions($options)->frameRate ?? $fileData["frameRate"] ?? self::DEFAULT_FRAME_RATE))->getFramesPerSecond();
        if ($frameRate > self::MAX_FRAME_RATE) {
            throw new UnwritableContentException("Spruce STL writes a frame number of 2 digits, so the frame rate must be at most " . self::MAX_FRAME_RATE . " fps, got $frameRate.");
        }
        $separator = preg_match('/^[ \t]*,[ \t]*$/D', $fileData["separator"] ?? "") === 1 ? $fileData["separator"] : self::DEFAULT_SEPARATOR;
        $lineBreak = preg_match('/^[ \t]*\|[ \t]*$/D', $fileData["lineBreak"] ?? "") === 1 ? $fileData["lineBreak"] : "|";
        $header    = $fileData["header"] ?? self::DEFAULT_HEADER;

        $state  = SpruceStl::DEFAULT_STATE;
        $output = "";
        foreach ($this->storedLines($header, true) as $line) {
            $state   = SpruceStl::apply($state, $line);
            $output .= $line . LineEnding::Lf->value;
        }

        $comments = $this->commentsByCue($subtitle);
        foreach ($subtitle->getCues() as $index => $cue) {
            $output .= $this->formatComments($comments[$index] ?? []);
            foreach ($this->storedLines($cue->findFormatData(SpruceStlParser::FORMAT_DATA_KEY)["commands"] ?? [], false) as $command) {
                $state   = SpruceStl::apply($state, $command);
                $output .= $command . LineEnding::Lf->value;
            }
            $output .= $this->alignmentCommands($cue, $state);
            $output .= $this->timecode($cue->getStart(), $frameRate) . $separator .
                       $this->timecode($cue->getEnd(), $frameRate) . $separator .
                       $this->formatText($cue, $state, $lineBreak) . LineEnding::Lf->value;
        }
        $output .= $this->formatComments($comments[count($subtitle)] ?? []);
        foreach ($this->storedLines($fileData["trailer"] ?? [], false) as $command) {
            $output .= $command . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Returns the stored lines that the parser reads back, with each CR and LF written as a space.
     * A header may also hold blank lines and "//" comments. Any other line would fail the read.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function storedLines(array $lines, bool $header): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $line    = str_replace(["\r", "\n"], " ", $line);
            $trimmed = trim($line);
            if (str_starts_with($trimmed, "$") || ($header && ($trimmed === "" || str_starts_with($trimmed, "//")))) {
                $kept[] = $line;
            }
        }

        return $kept;
    }


    /**
     * @param list<Comment> $comments
     */
    private function formatComments(array $comments): string
    {
        $output = "";
        foreach ($comments as $comment) {
            foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $comment->text)) as $line) {
                $output .= "//" . $line . LineEnding::Lf->value;
            }
        }

        return $output;
    }


    /**
     * Writes the $VertAlign and $HorzAlign commands that move the cue to its alignment, and applies them to $state.
     */
    private function alignmentCommands(SubtitleCue $cue, array &$state): string
    {
        $output = "";
        foreach (SpruceStl::alignmentState($cue->getAlignment()) as $key => $value) {
            if ($state[$key] !== $value) {
                $line    = ($key === "vertical" ? '$VertAlign' : '$HorzAlign') . " = " . ucfirst($value);
                $state   = SpruceStl::apply($state, $line);
                $output .= $line . LineEnding::Lf->value;
            }
        }

        return $output;
    }


    private function timecode(float $seconds, float $frameRate): string
    {
        $whole = (int) floor(max(0.0, $seconds));
        $frame = (int) round((max(0.0, $seconds) - $whole) * $frameRate);
        if ($frame >= ceil($frameRate)) {
            $whole++;
            $frame = 0;
        }

        return sprintf("%02d:%02d:%02d:%02d", intdiv($whole, 3600), intdiv($whole, 60) % 60, $whole % 60, $frame);
    }


    /**
     * Writes ^B, ^I and ^U where the bold, italic and underline style of the text differs from the style so far.
     * Each cue starts with the style of the commands.
     *
     * @param array{b: bool, i: bool, u: bool} $state
     */
    private function formatText(SubtitleCue $cue, array $state, string $lineBreak): string
    {
        $current = ["b" => $state["b"], "i" => $state["i"], "u" => $state["u"]];
        $lines   = [];
        foreach (Markup::carryOpenTags(array_map(Markup::rubyAsText(...), $cue->getLines())) as $line) {
            $open = ["b" => 0, "i" => 0, "u" => 0];
            $text = "";
            foreach (Markup::splitTags($line) as $index => $token) {
                if ($index % 2 === 1) {
                    if (preg_match('/^<(\/?)([biu])>$/i', $token, $tag)) {
                        $flag        = strtolower($tag[2]);
                        $open[$flag] = max(0, $open[$flag] + ($tag[1] === "" ? 1 : -1));
                    }
                    continue;
                }
                $token = self::replaceBar(Markup::decodeEntities($token));
                $token = preg_replace('/\^(?=[BIU])/', "^\u{2060}", $token);
                if (trim($token) === "") {
                    $text .= $token;
                    continue;
                }
                foreach (SpruceStl::TOGGLES as $flag => $toggle) {
                    if ($current[$flag] !== $open[$flag] > 0) {
                        $current[$flag] = !$current[$flag];
                        $text          .= $toggle;
                    }
                }
                if (str_ends_with($text, "^") && preg_match('/^[BIU]/', $token) === 1) {
                    $token = "\u{2060}" . $token;
                }
                $text .= $token;
            }
            $text = trim($text);
            if ($text !== "") {
                $lines[] = $text;
            }
        }

        return implode($lineBreak, $lines);
    }
}
