<?php

namespace App\Services\Research;

use App\Models\Fact;
use App\Models\Site;
use App\Models\Source;
use App\Services\Ai\AiClient;
use App\Services\Ai\GeminiClient;
use App\Services\Facts\Normalize;
use App\Services\Ingest\UrlFetcher;
use Illuminate\Support\Str;

/**
 * Searches the web for the business and works out which search result is actually the owner's.
 * Several businesses often share a name ("Sai Krupa Sweets" exists in every city), so unless a
 * hard identifier (website, phone, Google listing) matches exactly one result, we ask.
 */
class WebResearcher
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly GooglePlaces $places,
    ) {}

    public function canSearch(): bool
    {
        return $this->places->isConfigured() || $this->ai->isConfigured();
    }

    public function research(Site $site): void
    {
        $query = trim($site->business_name.' '.$site->city);
        $candidates = [];
        $summary = '';
        $links = [];
        $engine = null;

        if ($this->places->isConfigured()) {
            $candidates = $this->places->search($query);
            $engine = 'Google Places';
        }
        if ($candidates === [] && $this->ai->isConfigured()) {
            [$candidates, $summary, $links] = $this->searchWithAi($site);
            $engine = 'Gemini + Google Search';
        }

        $source = $site->sources()->create([
            'kind' => 'web_search',
            'label' => 'Web search',
            'status' => 'read',
            'content' => $summary ?: $this->describe($candidates),
            'facts_extracted' => true,
            'meta' => ['engine' => $engine, 'candidates' => $candidates, 'links' => array_slice($links, 0, 10)],
        ]);

        $site->forceFill(['research_done' => true])->save();

        if ($candidates === []) {
            $site->forceFill(['identity_resolved' => true])->save();

            return;
        }

        $match = $this->matchByIdentifiers($site, $candidates);
        if ($match !== null) {
            $this->applyIdentity($site, $candidates[$match], $source, 'Matched your '.$candidates[$match]['_matched_on']);

            return;
        }

        $options = [];
        foreach ($candidates as $i => $c) {
            $options[] = [
                'value' => (string) $i,
                'label' => collect([$c['name'], $c['address'] ?? null, $c['phone'] ?? null, $c['website'] ?? null])->filter()->implode(' · '),
            ];
        }
        $options[] = ['value' => 'none', 'label' => 'None of these is my business - only use what I gave you'];

        $site->questions()->create([
            'type' => 'identity',
            'field' => 'identity',
            'dedupe_key' => 'identity',
            'prompt' => count($candidates) === 1
                ? "We found one business online called \"{$site->business_name}\". Is this yours?"
                : 'We found '.count($candidates)." businesses online called \"{$site->business_name}\"".($site->city ? " around {$site->city}" : '').'. Which one is yours?',
            'options' => $options,
            'allow_custom' => false,
            'blocking' => true,
        ]);
    }

    /** Store the chosen business's details as (web-sourced) facts and queue its website for reading. */
    public function applyIdentity(Site $site, ?array $candidate, ?Source $searchSource = null, string $reason = 'Chosen by you'): void
    {
        $site->forceFill([
            'identity' => $candidate ? collect($candidate)->except('_matched_on')->all() + ['reason' => $reason] : null,
            'identity_resolved' => true,
        ])->save();

        if (! $candidate) {
            return;
        }

        $searchSource ??= $site->sources()->where('kind', 'web_search')->latest('id')->first();
        $fromPlaces = ($candidate['source'] ?? '') === 'Google Places';
        if ($fromPlaces) {
            $searchSource = $site->sources()->create([
                'kind' => 'google_business',
                'label' => 'Google listing',
                'url' => $candidate['maps_url'] ?? null,
                'status' => 'read',
                'content' => $this->describe([$candidate]),
                'facts_extracted' => true,
                'meta' => ['place' => $candidate],
            ]);
        }

        foreach (['phone' => 'phone', 'address' => 'address', 'website' => 'website', 'category' => 'category', 'hours' => 'hours'] as $field => $key) {
            $value = trim((string) ($candidate[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $site->facts()->create([
                'source_id' => $searchSource?->id,
                'key' => $key,
                'value' => $value,
                'evidence' => $fromPlaces ? 'Google Places listing' : 'Web search result',
                'status' => Fact::CONFIRMED,
            ]);
        }

        $website = $candidate['website'] ?? null;
        if ($website && UrlFetcher::classify($website) === 'website') {
            $known = $site->sources()->whereNotNull('url')->get()
                ->contains(fn (Source $s) => UrlFetcher::host($s->url) === UrlFetcher::host($website));
            if (! $known) {
                $site->sources()->create([
                    'kind' => 'website',
                    'label' => 'Website ('.UrlFetcher::host($website).')',
                    'url' => $website,
                    'status' => 'pending',
                    'meta' => ['discovered' => true],
                ]);
            }
        }
    }

    /** @return int|null index of the single candidate that a hard identifier points to */
    private function matchByIdentifiers(Site $site, array &$candidates): ?int
    {
        $hosts = $site->sources()->whereIn('kind', ['website'])->whereNotNull('url')->pluck('url')->map(fn ($u) => UrlFetcher::host($u))->all();
        $phones = $site->facts()->whereIn('key', ['phone', 'whatsapp'])
            ->whereHas('source', fn ($q) => $q->whereNotIn('kind', ['web_search']))
            ->pluck('value')->map(fn ($p) => Normalize::phoneDigits($p))->filter(fn ($p) => strlen($p) >= 8)->all();
        $mapsNames = $site->sources()->where('kind', 'google_business')->whereNotNull('url')->pluck('url')
            ->map(fn ($u) => GooglePlaces::nameFromMapsUrl($u))->filter()->map(fn ($n) => Normalize::words($n))->all();

        $matches = [];
        foreach ($candidates as $i => $c) {
            if (! empty($c['website']) && in_array(UrlFetcher::host($c['website']), $hosts, true)) {
                $matches[$i] = 'website';
            } elseif (! empty($c['phone']) && in_array(Normalize::phoneDigits($c['phone']), $phones, true)) {
                $matches[$i] = 'phone number';
            } elseif ($mapsNames && in_array(Normalize::words($c['name'] ?? ''), $mapsNames, true) && count($candidates) === 1) {
                $matches[$i] = 'Google listing';
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        $i = array_key_first($matches);
        $candidates[$i]['_matched_on'] = $matches[$i];

        return $i;
    }

    private function searchWithAi(Site $site): array
    {
        $where = $site->city ? " in or near {$site->city}" : '';
        $prompt = <<<TXT
Search the web for the local business "{$site->business_name}"{$where}.
Several different businesses may share this name. List each distinct business you find (at most 5).
For each, give ONLY details that appear in the search results: name, full address, phone, website, category,
and a short "note" saying what tells it apart. Use null for anything you did not find. Do not guess.
Reply with only JSON in this shape:
{"candidates":[{"name":"","address":null,"phone":null,"website":null,"category":null,"note":null}]}
TXT;
        $result = $this->ai->search('web_search', $prompt, $site->id);
        $decoded = GeminiClient::decodeJson($result['text']);
        $raw = is_array($decoded) ? ($decoded['candidates'] ?? (array_is_list($decoded) ? $decoded : [])) : [];

        $candidates = [];
        foreach ($raw as $c) {
            if (! is_array($c) || empty($c['name']) || ! is_string($c['name'])) {
                continue;
            }
            $clean = ['name' => Str::limit($c['name'], 120), 'source' => 'Web search'];
            foreach (['address', 'phone', 'website', 'category', 'note'] as $f) {
                $v = $c[$f] ?? null;
                $clean[$f] = is_string($v) && trim($v) !== '' && strtolower(trim($v)) !== 'null' ? Str::limit(trim($v), 300) : null;
            }
            if ($clean['website'] && ! preg_match('#^https?://#i', $clean['website'])) {
                $clean['website'] = 'https://'.$clean['website'];
            }
            $candidates[] = $clean;
        }

        return [array_slice($candidates, 0, 5), $result['text'], $result['sources']];
    }

    private function describe(array $candidates): string
    {
        return collect($candidates)->map(fn ($c) => collect($c)->except(['_matched_on'])->filter()->map(fn ($v, $k) => ucfirst($k).': '.$v)->implode("\n"))->implode("\n\n");
    }
}
