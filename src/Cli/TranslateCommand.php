<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\GoogleTranslateOptions;
use SubtitleToolbox\Translation\OpenAiCompatibleEngine;
use SubtitleToolbox\Translation\OpenAiCompatibleOptions;
use SubtitleToolbox\Translation\TranslationRunner;

/**
 * @internal
 */
final class TranslateCommand extends WriteCommand
{
    private const KEY_VARIABLES = ["deepl" => "DEEPL_API_KEY", "google" => "GOOGLE_TRANSLATE_API_KEY", "openai" => "OPENAI_API_KEY"];

    private const OPENAI_DEFAULT_URL = "https://api.openai.com/v1";

    /**
     * Points the engines at a fake server. Only the tests set it, in the same process. The binary has no way to set it.
     */
    public static ?string $testBaseUrl = null;

    private ?TranslationRunner $runner = null;

    private string $sourceLanguage = "";

    private string $targetLanguage = "";


    public function name(): string
    {
        return "translate";
    }


    public function summary(): string
    {
        return "Translate the cue text with DeepL, Google Cloud Translation or an OpenAI-compatible service.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --engine deepl|google|openai --target-language CODE [--source-language CODE] [--api-key KEY] [options]"];
    }


    protected function details(): string
    {
        return "The API key comes from --api-key, else from DEEPL_API_KEY for deepl, GOOGLE_TRANSLATE_API_KEY for google or " .
               "OPENAI_API_KEY for openai. openai needs no key for a local service such as Ollama. " .
               "openai reads the model from OPENAI_MODEL and the base URL from OPENAI_BASE_URL, default " . self::OPENAI_DEFAULT_URL . ". " .
               "The engines need the PHP extension curl. " .
               self::OUTPUT_DETAILS;
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("engine", "deepl|google|openai", "Translation service. Required."),
            Option::value("api-key", "KEY", "API key of the engine. Default: the environment variable of the engine."),
            Option::value("source-language", "CODE", "Language of the input, for example de. Default: the engine detects it."),
            Option::value("target-language", "CODE", "Language of the output, for example en-US for DeepL or fr for Google. Required."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $engine   = $arguments->choice("engine", array_keys(self::KEY_VARIABLES)) ?? self::fail("Pass --engine deepl, google or openai.");
        $variable = self::KEY_VARIABLES[$engine];
        $apiKey   = $arguments->value("api-key") ?? self::environment($variable);

        if ($apiKey === null && $engine !== "openai") {
            self::fail("Pass --api-key or set the environment variable $variable.");
        }
        if ($apiKey !== null && trim($apiKey) === "") {
            self::fail("The API key is empty. Pass --api-key or set $variable.");
        }
        if ($apiKey !== null && (trim($apiKey) !== $apiKey || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1)) {
            self::fail("The API key has a control character, or a space at the start or end. Pass the key without them.");
        }

        $this->targetLanguage = $arguments->value("target-language") ?? self::fail("Pass --target-language, for example --target-language fr.");
        $this->sourceLanguage = $arguments->value("source-language") ?? "";

        $baseUrl      = self::$testBaseUrl;
        $this->runner = new TranslationRunner(match ($engine) {
            "deepl"  => new DeepLEngine(new DeepLOptions(apiKey: $apiKey, baseUrl: $baseUrl)),
            "google" => new GoogleTranslateEngine(new GoogleTranslateOptions(apiKey: $apiKey, baseUrl: $baseUrl)),
            "openai" => self::openAiEngine($apiKey, $baseUrl),
        });
    }


    /**
     * Returns the value of an environment variable, or null when it is unset or empty. The value 0 is a value.
     */
    private static function environment(string $variable): ?string
    {
        $value = getenv($variable);

        return $value === false || $value === "" ? null : $value;
    }


    private static function openAiEngine(?string $apiKey, ?string $testUrl): OpenAiCompatibleEngine
    {
        $model = self::environment("OPENAI_MODEL") ?? self::fail("Set the environment variable OPENAI_MODEL, for example OPENAI_MODEL=gpt-4o-mini.");
        if (trim($model) === "") {
            self::fail("The environment variable OPENAI_MODEL is empty. Set it to a model name, for example gpt-4o-mini.");
        }
        $baseUrl = $testUrl !== null ? "$testUrl/v1" : (self::environment("OPENAI_BASE_URL") ?? self::OPENAI_DEFAULT_URL);
        if (preg_match('#^https?://[^/]#i', $baseUrl) !== 1) {
            self::fail("The environment variable OPENAI_BASE_URL must start with http:// or https://, got \"$baseUrl\".");
        }

        return new OpenAiCompatibleEngine(new OpenAiCompatibleOptions($baseUrl, $model, $apiKey));
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        $report = $this->runner->translate($subtitle, $this->sourceLanguage, $this->targetLanguage);
        foreach ($report->warnings as $warning) {
            $console->err(self::label($input) . ": cue " . ($warning->cueIndex + 1) . ": $warning->message\n");
        }

        return $subtitle;
    }
}
