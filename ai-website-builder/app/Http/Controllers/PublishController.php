<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class PublishController extends Controller
{
    public function publish(Site $site): RedirectResponse
    {
        if ($site->sections()->doesntExist()) {
            return back()->with('error', 'There is nothing to publish yet.');
        }
        if ($site->hasBlockingQuestions()) {
            return back()->with('error', 'Please answer the open questions first, so nothing wrong goes live.');
        }
        $site->update(['published_at' => now()]);

        return back()->with('ok', 'Published at '.route('public.site', $site->slug));
    }

    public function unpublish(Site $site): RedirectResponse
    {
        $site->update(['published_at' => null]);

        return back()->with('ok', 'Unpublished. The public link no longer works.');
    }

    /** The public page. Only published sites are visible. */
    public function show(string $slug)
    {
        $site = Site::where('slug', $slug)->whereNotNull('published_at')->firstOrFail();

        return view('public.site', ['site' => $site, 'sections' => $site->sections()->where('visible', true)->get(), 'preview' => false]);
    }

    /** Serves uploaded photos (only images, never other uploads). */
    public function media(Source $source)
    {
        abort_unless($source->kind === 'upload' && $source->isImage() && $source->path && Storage::disk('local')->exists($source->path), 404);
        // Photos of unpublished sites are only for the owner (when the builder has a password).
        $password = config('app.builder_password');
        abort_unless($source->site->isPublished() || ! $password || hash_equals((string) $password, (string) request()->getPassword()), 404);

        return response()->file(Storage::disk('local')->path($source->path), [
            'Content-Type' => $source->mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
