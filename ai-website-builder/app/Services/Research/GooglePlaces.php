<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional: the Places API (New) is the only reliable way to read a Google Business listing,
 * because Maps pages are rendered by JavaScript and cannot be scraped.
 */
class GooglePlaces
{
    public function __construct(private readonly ?string $key) {}

    public function isConfigured(): bool
    {
        return filled($this->key);
    }

    /** @return array<int, array> normalised candidates, or [] on any failure */
    public function search(string $query): array
    {
        if (! $this->isConfigured()) {
            return [];
        }
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $this->key,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.regularOpeningHours.weekdayDescriptions,places.googleMapsUri,places.primaryTypeDisplayName',
            ])->timeout(20)->post('https://places.googleapis.com/v1/places:searchText', [
                'textQuery' => $query,
                'pageSize' => 5,
            ]);
        } catch (Throwable) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('places', []))->map(fn ($p) => [
            'name' => $p['displayName']['text'] ?? '',
            'address' => $p['formattedAddress'] ?? null,
            'phone' => $p['internationalPhoneNumber'] ?? $p['nationalPhoneNumber'] ?? null,
            'website' => $p['websiteUri'] ?? null,
            'category' => $p['primaryTypeDisplayName']['text'] ?? null,
            'hours' => isset($p['regularOpeningHours']['weekdayDescriptions']) ? implode('; ', $p['regularOpeningHours']['weekdayDescriptions']) : null,
            'maps_url' => $p['googleMapsUri'] ?? null,
            'source' => 'Google Places',
        ])->filter(fn ($c) => $c['name'] !== '')->values()->all();
    }

    /** Pull the place name out of a Google Maps link, e.g. /maps/place/Blue+Tokai+Coffee/... */
    public static function nameFromMapsUrl(string $url): ?string
    {
        if (preg_match('#/maps/place/([^/@?]+)#', $url, $m)) {
            return trim(urldecode(str_replace('+', ' ', $m[1])));
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['q'] ?? $query['query'] ?? null;
    }
}
