<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use App\Services\Ai\GeminiClient;
use App\Services\Research\GooglePlaces;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiClient::class, fn () => new GeminiClient(
            apiKey: config('services.gemini.key'),
            model: config('services.gemini.model'),
            baseUrl: config('services.gemini.base_url'),
            timeout: config('services.gemini.timeout'),
            retries: config('services.gemini.retries'),
        ));

        $this->app->bind(GooglePlaces::class, fn () => new GooglePlaces(config('services.google_places.key')));
    }

    public function boot(): void
    {
        //
    }
}
