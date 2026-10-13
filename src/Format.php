<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * A subtitle, transcript or chapter format. The value is the format name of the command line tool.
 */
enum Format: string
{
    case Ass                = "ass";
    case Csv                = "csv";
    case FfMetadataChapters = "ffmeta-chapters";
    case HtmlTranscript     = "html";
    case Itt                = "itt";
    case Json               = "json";
    case AssemblyAi         = "assemblyai";
    case AwsTranscribe      = "aws-transcribe";
    case Deepgram           = "deepgram";
    case GoogleSpeech       = "google-speech";
    case Lyrics             = "lrc";
    case Mcc                = "mcc";
    case MicroDvd           = "microdvd";
    case MpSub              = "mpsub";
    case Pgs                = "pgs";
    case Sami               = "sami";
    case Sbv                = "sbv";
    case Scc                = "scc";
    case SubRip             = "srt";
    case EbuStl             = "stl";
    case SpruceStl          = "spruce-stl";
    case SubViewer          = "subviewer";
    case Ttml               = "ttml";
    case Dcp                = "dcp";
    case Tsv                = "tsv";
    case PlainText          = "txt";
    case Mpl2               = "mpl2";
    case TmPlayer           = "tmplayer";
    case QuickTimeText      = "qttext";
    case VobSub             = "vobsub";
    case WebVtt             = "vtt";
    case Whisper            = "whisper";
    case YouTubeTimedText   = "youtube";
    case OgmChapters        = "ogm-chapters";
    case PodcastChapters    = "podcast-chapters";
    case PodcastTranscript  = "podcast-transcript";
    case YouTubeChapters    = "youtube-chapters";


    /**
     * Returns the format of the file extension of $path, or null for an unknown or missing extension.
     * When two formats share an extension, the first matching row of FormatRegistry::FORMATS owns it.
     * The rows follow the order of the cases, so `.sub` is MicroDVD, `.json` is the library JSON and `.txt` is plain text.
     * The first extension of a format is the one for new files.
     */
    public static function tryFromPath(string $path): ?self
    {
        return FormatRegistry::forPath($path);
    }


    /**
     * Returns the subtitle format of $content, or null. It never returns a format whose isAutoDetected() is false.
     */
    public static function detect(string $content): ?self
    {
        return FormatDetector::detect($content);
    }


    /**
     * Returns the file extensions without the dot. The first one is the extension for new files.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return FormatRegistry::extensions($this);
    }


    public function canRead(): bool
    {
        return FormatRegistry::parserClass($this) !== null;
    }


    public function canWrite(): bool
    {
        return FormatRegistry::formatterClass($this) !== null;
    }


    /**
     * Returns false for chapters and cloud speech-to-text JSON. Their content looks like other formats, so they load
     * only when the caller names them. True means only that the format is not excluded from auto-detection.
     * It does not mean that the format can be read or that detection finds it by content.
     */
    public function isAutoDetected(): bool
    {
        return match ($this) {
            self::YouTubeChapters, self::PodcastChapters, self::FfMetadataChapters, self::OgmChapters,
            self::AwsTranscribe, self::Deepgram, self::AssemblyAi, self::GoogleSpeech => false,
            default => true,
        };
    }
}
