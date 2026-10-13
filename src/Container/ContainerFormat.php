<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

/**
 * ContainerFormat names the video container that holds a SubtitleTrack.
 */
enum ContainerFormat: string
{
    case Matroska = "matroska";
    case Mp4      = "mp4";
    case MpegTs   = "mpegts";
}
