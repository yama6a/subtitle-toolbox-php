<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

/**
 * The fixes in the order they run. The value of a case is the name of its field in CommonErrorOptions.
 */
enum CommonErrorRule: string
{
    case AdCues                       = "adCues";
    case ReplaceList                  = "replaceList";
    case UnbalancedTags               = "unbalancedTags";
    case EmptyTags                    = "emptyTags";
    case DoubleApostrophes            = "doubleApostrophes";
    case OcrPipe                      = "ocrPipe";
    case OcrZeroInWords               = "ocrZeroInWords";
    case OcrLowercaseL                = "ocrLowercaseL";
    case LoneLowercaseI               = "loneLowercaseI";
    case Ellipsis                     = "ellipsis";
    case UnneededPeriods              = "unneededPeriods";
    case DoubleSpaces                 = "doubleSpaces";
    case SpaceBeforePunctuation       = "spaceBeforePunctuation";
    case MissingSpaceAfterPunctuation = "missingSpaceAfterPunctuation";
    case DialogueOnOneLine            = "dialogueOnOneLine";
    case DialogueDashes               = "dialogueDashes";
    case MusicNotes                   = "musicNotes";
    case SentenceStartCase            = "sentenceStartCase";
}
