<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * The position of a cue on the screen. The values are the keys of a numeric keypad, as in the ASS tag {\an8}.
 */
enum Alignment: int
{
    case BottomLeft   = 1;
    case BottomCenter = 2;
    case BottomRight  = 3;
    case MiddleLeft   = 4;
    case MiddleCenter = 5;
    case MiddleRight  = 6;
    case TopLeft      = 7;
    case TopCenter    = 8;
    case TopRight     = 9;
}
