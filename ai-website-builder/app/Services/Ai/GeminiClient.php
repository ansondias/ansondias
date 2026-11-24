<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Minimal Gemini REST client. No SDK so the request is easy to read and to fake in tests.
 */
class GeminiClient implements AiClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
        private readonly int $retries = 2,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function json(string $purpose, string $system, string $prompt, array $schema, array $images = [], ?int $siteId = null): array
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => base64_encode($image['data'])]];
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $response = $this->call($purpose, $body, strlen($system) + strlen($prompt), $siteId);
        $decoded = self::decodeJson($this->text($response));
        if (! is_array($decoded)) {
            throw new AiUnavailableException('The AI returned something that was not valid JSON.');
        }

        return $decoded;
    }

    public function search(string $purpose, string $prompt, ?int $siteId = null): array
    {
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'tools' => [['google_search' => new \stdClass]],
            'generationConfig' => ['temperature' => 0.1],
        ];

        $response = $this->call($purpose, $body, strlen($prompt), $siteId);
        $sources = [];
        foreach ($response->json('candidates.0.groundingMetadata.groundingChunks', []) as $chunk) {
            if (! empty($chunk['web']['uri'])) {
                $sources[] = ['url' => $chunk['web']['uri'], 'title' => $chunk['web']['title'] ?? $chunk['web']['uri']];
            }
        }

        return ['text' => $this->text($response), 'sources' => $sources];
    }

    private function call(string $purpose, array $body, int $promptChars, ?int $siteId): Response
    {
        if (! $this->isConfigured()) {
            $this->log($siteId, $purpose, 'error', 0, $promptChars, 'GEMINI_API_KEY is not set');
            throw new AiUnavailableException('No AI key is configured (set GEMINI_API_KEY in .env).');
        }

        $url = rtrim($this->baseUrl, '/')."/models/{$this->model}:generateContent";
        $attempt = 0;
        $started = microtime(true);

        while (true) {
            $attempt++;
            $error = null;
            try {
                $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->acceptJson()
                    ->timeout($this->timeout)
                    ->post($url, $body);

                if ($response->successful()) {
                    $blocked = $response->json('promptFeedback.blockReason');
                    if ($blocked) {
                        $error = "The AI refused the request ($blocked).";
                        $this->log($siteId, $purpose, 'error', $started, $promptChars, $error);
                        throw new AiUnavailableException($error);
                    }
                    $this->log($siteId, $purpose, 'ok', $started, $promptChars);

                    return $response;
                }

                $error = 'HTTP '.$response->status().': '.Str::limit((string) $response->json('error.message', $response->body()), 200);
                $retryable = $response->status() === 429 || $response->serverError();
            } catch (ConnectionException $e) {
                $error = 'Could not reach the AI service: '.$e->getMessage();
                $retryable = true;
            }

            if (! $retryable || $attempt > $this->retries) {
                $this->log($siteId, $purpose, 'error', $started, $promptChars, $error);
                throw new AiUnavailableException($error);
            }

            usleep((int) (1_000_000 * (2 ** $attempt))); // 2s, 4s ...
        }
    }

    private function text(Response $response): string
    {
        $text = '';
        foreach ($response->json('candidates.0.content.parts', []) as $part) {
            $text .= $part['text'] ?? '';
        }

        return $text;
    }

    /** Parse JSON even when the model wraps it in a ```json fence or adds prose around it. */
    public static function decodeJson(string $text): mixed
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if ($decoded !== null) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);
            if ($start !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function log(?int $siteId, string $purpose, string $status, float|int $started, int $promptChars, ?string $error = null): void
    {
        try {
            AiRun::create([
                'site_id' => $siteId,
                'purpose' => $purpose,
                'model' => $this->model,
                'status' => $status,
                'latency_ms' => $started ? (int) ((microtime(true) - $started) * 1000) : 0,
                'prompt_chars' => $promptChars,
                'error' => $error,
            ]);
        } catch (Throwable) {
            // Logging must never break the build.
        }
    }
}
