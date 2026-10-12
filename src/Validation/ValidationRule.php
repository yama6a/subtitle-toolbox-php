<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

/**
 * The value of a case is the name of its field in ValidationRules. YouTubeChapters::check() uses MinDuration and the
 * 2 cases without a field, MinChapters and FirstChapterAtZero.
 */
enum ValidationRule: string
{
    case MaxCharactersPerSecond    = "maxCharactersPerSecond";
    case MaxCharactersPerLine      = "maxCharactersPerLine";
    case MaxLinesPerCue            = "maxLinesPerCue";
    case MinDuration               = "minDuration";
    case MaxDuration               = "maxDuration";
    case MinGap                    = "minGap";
    case NoOverlap                 = "noOverlap";
    case NoEmptyCues               = "noEmptyCues";
    case NoDoubleSpaces            = "noDoubleSpaces";
    case NoLeadingOrTrailingSpaces = "noLeadingOrTrailingSpaces";
    case NoUnbalancedTags          = "noUnbalancedTags";
    case DialogueDashStyle         = "dialogueDashStyle";
    case MaxSpeakersPerCue         = "maxSpeakersPerCue";
    case MaxWordsPerMinute         = "maxWordsPerMinute";
    case MinSecondsPerWord         = "minSecondsPerWord";
    case AllowedCharacters         = "allowedCharacters";
    case NoAllCapsLines            = "noAllCapsLines";
    case RequireCues               = "requireCues";
    case NoNegativeDuration        = "noNegativeDuration";
    case MinChapters               = "minChapters";
    case FirstChapterAtZero        = "firstChapterAtZero";
}
