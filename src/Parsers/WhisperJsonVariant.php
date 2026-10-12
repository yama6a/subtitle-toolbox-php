<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The JSON shapes of the Whisper tools, as the "format" field of the Whisper format data names them.
 * openai-whisper also stands for faster-whisper, which writes the same shape.
 *
 * @internal
 */
enum WhisperJsonVariant: string
{
    case OpenAiWhisper = "openai-whisper";
    case OpenAiApi     = "openai-api";
    case WhisperX      = "whisperx";
    case WhisperCpp    = "whisper.cpp";
}
