<?php

namespace App\Services\Ingest;

use App\Models\Source;
use App\Services\Ai\AiUnavailableException;
use App\Services\Research\GooglePlaces;
use App\Services\Safety\InjectionScanner;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/** Reads one pending source (file or link) into text, scans it for injected instructions. */
class SourceIngestor
{
    public function __construct(
        private readonly UrlFetcher $fetcher,
        private readonly FileExtractor $files,
        private readonly InjectionScanner $scanner,
        private readonly GooglePlaces $places,
    ) {}

    /** @throws AiUnavailableException when a photo/scanned PDF needs the AI and it is down */
    public function ingest(Source $source, bool $useAi = true): void
    {
        $site = $source->site;
        $status = 'read';
        $error = null;
        $text = '';
        $meta = $source->meta ?? [];

        if ($source->kind === 'upload') {
            if (! $useAi && ($source->isImage() || $source->mime === 'application/pdf' && ! $this->hasTextLayer($source))) {
                if ($source->isImage()) {
                    $meta['alt_text'] = 'Photo of '.$site->business_name;
                }
                $source->forceFill(['status' => 'read', 'meta' => $meta, 'error' => $source->isImage() ? null : 'Not read: needs the AI.'])->save();

                return;
            }
            $result = $this->files->extract(Storage::disk('local')->path($source->path), (string) $source->mime, (string) $source->original_name, $site->id);
            $text = $result['text'];
            $meta = array_merge($meta, $result['meta']);
            if ($text === '' && ! $source->isImage()) {
                $status = 'failed';
                $error = 'No readable text found in this file.';
            }
        } elseif ($source->kind === 'pasted') {
            $text = (string) $source->content;
        } elseif ($source->url) {
            [$status, $text, $error, $meta] = $this->readLink($source, $meta);
        }

        $scan = $this->scanner->scan($text);

        $source->forceFill([
            'status' => $status,
            'content' => $scan['text'],
            'error' => $error,
            'injection_findings' => $scan['findings'] ?: null,
            'meta' => $meta,
        ])->save();

        if ($status === 'blocked' || ($status === 'failed' && $source->kind !== 'upload')) {
            $site->questions()->firstOrCreate(
                ['dedupe_key' => 'blocked:'.$source->id],
                [
                    'type' => 'blocked_source',
                    'field' => 'source:'.$source->id,
                    'prompt' => "We couldn't fully read your {$source->label}. ".rtrim((string) $error, '.').'. '."Paste your bio, services, hours or contact details from it here and we'll use them. Or skip.",
                    'allow_custom' => true,
                    'blocking' => false,
                ],
            );
        }
    }

    private function readLink(Source $source, array $meta): array
    {
        if ($source->kind === 'google_business' && $this->places->isConfigured()) {
            $name = GooglePlaces::nameFromMapsUrl($source->url) ?? $source->site->business_name;
            $results = $this->places->search(trim($name.' '.$source->site->city));
            if ($results) {
                $place = $results[0];
                $meta['place'] = $place;
                $text = collect($place)->filter()->map(fn ($v, $k) => ucfirst($k).': '.$v)->implode("\n");

                return ['read', $text, null, $meta];
            }
        }

        $result = $this->fetcher->fetch($source->url);
        $meta = array_merge($meta, $result->meta);
        $error = $result->error;
        if ($source->kind === 'google_business' && $result->status !== 'read') {
            $error = 'Google Maps pages load with JavaScript, so they cannot be read directly. Add GOOGLE_PLACES_API_KEY to read listings automatically.';
        }

        return [$result->status, $result->text, $error, $meta];
    }

    private function hasTextLayer(Source $source): bool
    {
        try {
            $text = (new Parser)->parseFile(Storage::disk('local')->path($source->path))->getText();

            return mb_strlen(trim($text)) >= 40;
        } catch (\Throwable) {
            return false;
        }
    }
}
