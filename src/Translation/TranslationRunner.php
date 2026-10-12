<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class TranslationRunner
{
    private const SENTENCE_END_REGEX   = '/[.?!\x{2026}\x{3002}\x{FF0E}\x{FF1F}\x{FF01}]["\'\x{201D}\x{2019}\x{00BB})\]]*$/u';
    private const NOT_TRANSLATED_REGEX = '/^[\p{N}\p{P}\p{S}\s]*$/u';

    public function __construct(private readonly TranslationEngine $engine)
    {
    }


    /**
     * Translates the text of each cue with the engine and sets the language metadata to $targetLanguage. The subtitle
     * changes only when every engine call succeeds.
     */
    public function translate(Subtitle $subtitle, string $sourceLanguage, string $targetLanguage, ?TranslationOptions $options = null): TranslationReport
    {
        $options  = $options ?? new TranslationOptions();
        $warnings = [];
        $cues     = array_map(fn (SubtitleCue $cue): SubtitleCue => clone $cue, $subtitle->getCues());

        $requests = [];
        foreach ($this->groupCues($cues, $options) as $cueIndexes) {
            $text       = self::requestText(array_map(fn (int $index): SubtitleCue => $cues[$index], $cueIndexes));
            $requests[] = new TranslationRequest($cueIndexes, PlaceholderTags::encode($text));
        }

        foreach ($this->batches($requests, $options->maxCharactersPerRequest) as $batch) {
            $texts        = array_map(fn (TranslationRequest $request): string => $request->encoded->text, $batch);
            $translations = $this->engine->translate($texts, $sourceLanguage, $targetLanguage);
            if (!array_is_list($translations) || count($translations) !== count($texts) ||
                count(array_filter($translations, "is_string")) !== count($texts)) {
                throw new InvalidArgumentException("The translation engine must return one string per text, " .
                                                   "got " . count($translations) . " values for " . count($texts) . " texts.");
            }

            foreach ($batch as $requestIndex => $request) {
                array_push($warnings, ...$this->fillCues($cues, $request, $translations[$requestIndex]));
            }
        }

        foreach ($subtitle->getCues() as $cueIndex => $cue) {
            $cue->setLines($cues[$cueIndex]->getLines());
        }
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $targetLanguage);
        usort($warnings, fn (TranslationWarning $warning1, TranslationWarning $warning2): int => $warning1->cueIndex <=> $warning2->cueIndex);

        return new TranslationReport($warnings);
    }


    /**
     * @param array<int, SubtitleCue> $cues
     * @return list<list<int>>
     */
    private function groupCues(array $cues, TranslationOptions $options): array
    {
        $groups   = [];
        $cueIndex = 0;
        while ($cueIndex < count($cues)) {
            if (!$this->needsTranslation($cues[$cueIndex])) {
                $cueIndex++;
                continue;
            }

            $group = [$cueIndex];
            while ($options->joinSentences && count($group) < $options->maxCuesPerSentence && !$this->endsSentence($cues[$cueIndex])
                   && isset($cues[$cueIndex + 1]) && $this->needsTranslation($cues[$cueIndex + 1])) {
                $group[] = ++$cueIndex;
            }

            $groups[] = $group;
            $cueIndex++;
        }

        return $groups;
    }


    private function needsTranslation(SubtitleCue $cue): bool
    {
        $text = self::visibleText($cue);

        return $text !== "" && preg_match(self::NOT_TRANSLATED_REGEX, $text) !== 1;
    }


    private function endsSentence(SubtitleCue $cue): bool
    {
        // Invalid UTF-8 makes preg_match() return false. Such a cue ends the sentence, so the runner never cuts it.
        return preg_match(self::SENTENCE_END_REGEX, self::visibleText($cue)) !== 0;
    }


    private static function visibleText(SubtitleCue $cue): string
    {
        return Markup::visibleText(implode(" ", $cue->getLines()));
    }


    /**
     * @param list<SubtitleCue> $cues
     */
    private static function requestText(array $cues): string
    {
        return count($cues) === 1
            ? implode("\n", $cues[0]->getLines())
            : implode(" ", array_map(fn (SubtitleCue $cue): string => implode(" ", $cue->getLines()), $cues));
    }


    /**
     * Fills each batch with whole texts up to $maxCharacters. A longer text goes out alone.
     *
     * @param list<TranslationRequest> $requests
     * @return list<list<TranslationRequest>>
     */
    private function batches(array $requests, int $maxCharacters): array
    {
        $batches    = [];
        $batch      = [];
        $characters = 0;
        foreach ($requests as $request) {
            $length = Markup::countCharacters($request->encoded->text);
            if ($batch !== [] && $characters + $length > $maxCharacters) {
                $batches[]  = $batch;
                $batch      = [];
                $characters = 0;
            }
            $batch[]     = $request;
            $characters += $length;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }


    /**
     * @param array<int, SubtitleCue> $cues
     * @return list<TranslationWarning>
     */
    private function fillCues(array $cues, TranslationRequest $request, string $translation): array
    {
        $warnings   = [];
        $cueIndexes = $request->cueIndexes;
        $tags       = $request->encoded->tags;
        if (!PlaceholderTags::keepsPlaceholders($translation, $tags)) {
            $translation = PlaceholderTags::remove($translation);
            $tags        = [];
            foreach ($cueIndexes as $cueIndex) {
                $warnings[] = new TranslationWarning($cueIndex, "The engine dropped or changed a placeholder tag. The cue has no tags.");
            }
        }

        $tokens = PlaceholderTags::tokenize($translation);
        if (count($cueIndexes) === 1) {
            $cues[$cueIndexes[0]]->setLines(explode("\n", PlaceholderTags::restore($tokens, $tags)));

            return $warnings;
        }

        $weights = array_map(fn (int $cueIndex): int => max(1, Markup::countCharacters(self::visibleText($cues[$cueIndex]))), $cueIndexes);
        foreach (SentenceSplitter::split($tokens, $weights) as $pieceIndex => $piece) {
            $cueIndex = $cueIndexes[$pieceIndex];
            $cues[$cueIndex]->setLines(str_replace("\n", " ", PlaceholderTags::restore($piece, $tags)));
            if ($cues[$cueIndex]->getLines() === []) {
                $warnings[] = new TranslationWarning($cueIndex, "The translation of the sentence is too short to fill " .
                                                                "this cue. The cue has no text.");
            }
        }

        return $warnings;
    }
}
