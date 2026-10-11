<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class MpSubFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = MpSubWriteOptions::class;

    protected const DEFAULT_BOM = true;

    private const DEFAULT_TYPE = "VIDEO";
    private const DEFAULT_NOTE = "Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox-php)";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fps         = $this->formatOptions($options)->frameRate;
        $frameRate   = $fps === null ? null : new FrameRate($fps);
        $output      = $this->header($subtitle, $frameRate);
        $previousEnd = 0;
        foreach ($subtitle->getCues() as $cue) {
            $output .= LineEnding::Lf->value;
            $start   = max(0.0, $cue->getStart());
            $end     = max(0.0, $cue->getEnd());
            if ($frameRate === null) {
                $wait     = Timecode::totalMilliseconds($start - $previousEnd) / 1000;
                $duration = Timecode::totalMilliseconds($end - $start) / 1000;
            } else {
                $wait     = $frameRate->secondsToFrames($start) - $frameRate->secondsToFrames($previousEnd);
                $duration = $frameRate->secondsToFrames($end) - $frameRate->secondsToFrames($start);
            }
            $output .= "$wait $duration" . LineEnding::Lf->value;
            $output .= Markup::plainText(Markup::rubyAsText(implode(LineEnding::Lf->value, $cue->getLines())));
            $output .= LineEnding::Lf->value;

            $previousEnd = $end;
        }

        return $this->applyOutputOptions($output, $options);
    }


    private function header(Subtitle $subtitle, ?FrameRate $frameRate): string
    {
        $fileData = $subtitle->findFormatData(MpSubParser::FORMAT_DATA_KEY);
        $headers  = [
            "TITLE"  => $subtitle->findMetadata(Subtitle::METADATA_TITLE) ?? "",
            "AUTHOR" => $subtitle->findMetadata(Subtitle::METADATA_AUTHOR) ?? "",
            "TYPE"   => $fileData["TYPE"] ?? self::DEFAULT_TYPE,
        ];
        foreach ($fileData as $key => $value) {
            if (!in_array($key, ["TITLE", "AUTHOR", "TYPE", "FORMAT", "NOTE"], true)) {
                $headers[$key] = $value;
            }
        }
        $headers["FORMAT"] = $frameRate === null ? "TIME" : (string)(int)$frameRate->getFramesPerSecond();
        $headers["NOTE"]   = $fileData["NOTE"] ?? self::DEFAULT_NOTE;

        $header = "";
        foreach ($headers as $key => $value) {
            $header .= "$key=$value" . LineEnding::Lf->value;
        }

        return $header;
    }
}
