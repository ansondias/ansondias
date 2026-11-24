<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Gemini REST API (and any web pages) at the HTTP layer, so tests run the real client.
 * Handlers are keyed by what the call is for: extract | search | write | image.
 */
trait FakesGemini
{
    /** @var array<int, array{purpose: string, body: array}> */
    protected array $aiCalls = [];

    protected function fakeWeb(array $ai, array $pages = []): void
    {
        Http::swap(new Factory); // replace, not stack, earlier fakes
        Http::fake(function (Request $request) use ($ai, $pages) {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                $body = $request->data();
                $purpose = $this->purposeOf($body);
                $this->aiCalls[] = ['purpose' => $purpose, 'body' => $body];
                $handler = $ai[$purpose] ?? $ai['*'] ?? null;
                if ($handler === null) {
                    return Http::response(['error' => ['message' => "No fake for $purpose"]], 500);
                }
                $result = is_callable($handler) ? $handler($body) : $handler;
                if ($result instanceof PromiseInterface) {
                    return $result;
                }

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => is_string($result) ? $result : json_encode($result)]]]]]]);
            }
            foreach ($pages as $pattern => $response) {
                if (str_contains($request->url(), $pattern)) {
                    return $response;
                }
            }

            return Http::response('Not found', 404);
        });
    }

    protected function purposeOf(array $body): string
    {
        if (isset($body['tools'])) {
            return 'search';
        }
        $system = $body['systemInstruction']['parts'][0]['text'] ?? '';

        return match (true) {
            str_contains($system, 'extract facts') => 'extract',
            str_contains($system, 'write the text') => 'write',
            str_contains($system, 'describe photos') => 'image',
            default => 'other',
        };
    }

    protected function aiPrompt(array $body): string
    {
        return $body['contents'][0]['parts'][0]['text'] ?? '';
    }
}
