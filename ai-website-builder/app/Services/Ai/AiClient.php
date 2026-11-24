<?php

namespace App\Services\Ai;

interface AiClient
{
    /**
     * Ask for a JSON answer that follows $schema.
     *
     * @param  array<int, array{mime: string, data: string}>  $images  raw bytes, not base64
     *
     * @throws AiUnavailableException
     */
    public function json(string $purpose, string $system, string $prompt, array $schema, array $images = [], ?int $siteId = null): array;

    /**
     * Ask a question that is answered with a live web search.
     *
     * @return array{text: string, sources: array<int, array{url: string, title: string}>}
     *
     * @throws AiUnavailableException
     */
    public function search(string $purpose, string $prompt, ?int $siteId = null): array;

    public function isConfigured(): bool;
}
