<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Http\CurlHttpClient;
use SubtitleToolbox\Http\HttpClient;

final class OpenAiCompatibleEngine implements TranslationEngine
{
    private const PROMPT = "You translate subtitle text from {source} to {target}. The user sends a JSON array of strings. " .
                           "Translate each string on its own. Keep the tags such as <x1>, </x1> and <x2/> around the words that " .
                           "they mark, and keep the entities &lt;, &gt; and &amp;. Keep the line breaks. Answer only with a JSON " .
                           "array of strings that has as many strings as the input, in the same order, and no other text.";

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly string $url;

    private readonly HttpClient $client;


    /**
     * Creates the engine for a service with the OpenAI chat completions API, such as OpenAI, Ollama, LM Studio, the
     * llama.cpp server or vLLM. It throws InvalidArgumentException when the options pass no HttpClient and PHP has no
     * ext-curl.
     */
    public function __construct(private readonly OpenAiCompatibleOptions $options)
    {
        $this->url    = rtrim($options->baseUrl, "/") . "/chat/completions";
        $this->client = $options->httpClient ?? new CurlHttpClient();
    }


    /**
     * Sends all texts in one request as a JSON array. When the model answers with another number of strings or with no
     * JSON array, the engine sends each text in a request of its own.
     */
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        if ($texts === []) {
            return [];
        }

        $prompt       = $this->prompt($sourceLanguage, $targetLanguage);
        $translations = $this->request($texts, $prompt);
        if ($translations !== null) {
            return $translations;
        }

        $translations = [];
        foreach ($texts as $text) {
            $translations[] = ($this->request([$text], $prompt) ?? throw new TranslationException(
                "The model \"{$this->options->model}\" did not answer with a JSON array that holds 1 string for 1 text."
            ))[0];
        }

        return $translations;
    }


    private function prompt(string $sourceLanguage, string $targetLanguage): string
    {
        return strtr($this->options->prompt ?? self::PROMPT, [
            "{source}" => $sourceLanguage !== "" ? $sourceLanguage : "the language of the text",
            "{target}" => $targetLanguage,
        ]);
    }


    /**
     * Returns null when the answer holds no JSON array with one string per text.
     *
     * @param list<string> $texts
     * @return list<string>|null
     */
    private function request(array $texts, string $prompt): ?array
    {
        $headers = ["Content-Type: application/json"];
        if ($this->options->apiKey !== null) {
            $headers[] = "Authorization: Bearer {$this->options->apiKey}";
        }
        $body = ["model" => $this->options->model, "messages" => [
            ["role" => "system", "content" => $prompt],
            ["role" => "user", "content" => json_encode($texts, self::JSON_FLAGS)],
        ]];

        [$status, $response] = HttpRetry::post($this->client, $this->url, $headers, json_encode($body, self::JSON_FLAGS));

        $data = json_decode($response, true);
        if ($status !== 200) {
            $error  = is_array($data) ? ($data["error"] ?? null) : null;
            $detail = is_array($error) && is_string($error["message"] ?? null) ? " " . $error["message"] : (is_string($error) ? " $error" : "");
            $message = match ($status) {
                401     => "The OpenAI-compatible service rejected the API key (HTTP 401).$detail",
                429     => "The OpenAI-compatible service got too many requests (HTTP 429). Wait and try again.",
                default => "The OpenAI-compatible service answered with HTTP $status.$detail",
            };
            throw new TranslationException($this->options->apiKey !== null ? str_replace($this->options->apiKey, "***", $message) : $message);
        }

        $message = is_array($data) ? ($data["choices"][0]["message"] ?? null) : null;
        foreach (["content", "reasoning", "reasoning_content"] as $field) {
            $answer = is_array($message) ? ($message[$field] ?? null) : null;
            if (is_string($answer) && trim($answer) !== "") {
                return self::stringArray($answer, count($texts));
            }
        }

        return null;
    }


    /**
     * Reads the JSON array in the answer of a model, which can stand in a code block or after a <think> block.
     *
     * @return list<string>|null
     */
    private static function stringArray(string $content, int $count): ?array
    {
        $content = preg_replace('#<think>.*?</think>#s', "", $content) ?? $content;
        $start   = strpos($content, "[");
        $end     = strrpos($content, "]");
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $strings = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($strings) && array_is_list($strings) && count($strings) === $count
            && count(array_filter($strings, "is_string")) === $count ? $strings : null;
    }
}
