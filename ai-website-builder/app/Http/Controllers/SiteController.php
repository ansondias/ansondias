<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSite;
use App\Models\Fact;
use App\Models\Site;
use App\Models\Source;
use App\Services\Ingest\UrlFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    public const FILE_RULES = 'file|max:10240|mimes:pdf,txt,csv,md,docx,html,htm,jpg,jpeg,png,webp,gif';

    public function index()
    {
        return view('sites.index', ['sites' => Site::latest()->withCount(['questions as open_questions_count' => fn ($q) => $q->where('status', 'open')])->get()]);
    }

    public function create()
    {
        return view('sites.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => 'required|string|max:150',
            'city' => 'nullable|string|max:100',
            'google_url' => 'nullable|url|max:2000',
            'website_url' => 'nullable|url|max:2000',
            'social_links' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:20000',
            'files' => 'nullable|array|max:12',
            'files.*' => self::FILE_RULES,
        ]);

        $site = Site::create(['business_name' => trim($data['business_name']), 'city' => trim((string) ($data['city'] ?? '')) ?: null]);

        $links = collect(preg_split('/[\s,]+/', (string) ($data['social_links'] ?? '')))
            ->map(fn ($l) => trim($l))->filter()
            ->map(fn ($l) => preg_match('#^https?://#i', $l) ? $l : 'https://'.$l)
            ->merge(array_filter([$data['google_url'] ?? null, $data['website_url'] ?? null]))
            ->unique()->values();

        foreach ($links as $url) {
            $this->addLink($site, $url);
        }
        foreach ($request->file('files', []) as $file) {
            $this->addFile($site, $file);
        }
        if (filled($data['notes'] ?? null)) {
            $site->sources()->create(['kind' => 'pasted', 'label' => 'Your notes', 'status' => 'pending', 'content' => $data['notes']]);
        }

        ProcessSite::dispatch($site->id);

        return redirect()->route('sites.show', $site);
    }

    public function show(Site $site)
    {
        $site->load(['sources', 'sections.revisions']);

        return view('sites.show', [
            'site' => $site,
            'questions' => $site->openQuestions(),
            'facts' => $site->facts()->with('source')->where('status', '!=', Fact::REJECTED)->orderBy('key')->orderBy('id')->get(),
            'rejectedCount' => $site->facts()->where('status', Fact::REJECTED)->count(),
            'aiErrors' => $site->aiRuns()->where('status', 'error')->latest('id')->take(3)->get(),
        ]);
    }

    public function status(Site $site)
    {
        return response()->json([
            'stage' => $site->stage,
            'detail' => $site->stage_detail,
            'error' => $site->last_error,
            'open_questions' => $site->questions()->where('status', 'open')->count(),
            'updated' => $site->updated_at?->timestamp,
        ]);
    }

    public function retry(Request $request, Site $site): RedirectResponse
    {
        $useAi = ! $request->boolean('without_ai');
        $site->moveTo('queued', $useAi ? 'Waiting to start' : 'Building without AI');
        ProcessSite::dispatch($site->id, $useAi);

        return back();
    }

    /** Throw away all sections and write the site again from the current facts. */
    public function rebuild(Site $site): RedirectResponse
    {
        $site->sections()->delete();
        $site->moveTo('queued', 'Rewriting your website');
        ProcessSite::dispatch($site->id);

        return back();
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => 'nullable|in:classic,bold',
            'business_name' => 'nullable|string|max:150',
        ]);
        $site->update(array_filter($data));

        return back()->with('ok', 'Saved.');
    }

    public function addSource(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'nullable|url|max:2000',
            'notes' => 'nullable|string|max:20000',
            'files' => 'nullable|array|max:12',
            'files.*' => self::FILE_RULES,
        ]);
        if (! empty($data['url'])) {
            $this->addLink($site, $data['url']);
        }
        foreach ($request->file('files', []) as $file) {
            $this->addFile($site, $file);
        }
        if (filled($data['notes'] ?? null)) {
            $site->sources()->create(['kind' => 'pasted', 'label' => 'Your notes', 'status' => 'pending', 'content' => $data['notes']]);
        }
        $site->moveTo('queued', 'Reading the new source');
        ProcessSite::dispatch($site->id);

        return back()->with('ok', 'Added. Reading it now.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $site->delete();

        return redirect()->route('sites.index')->with('ok', 'Deleted.');
    }

    public function preview(Site $site)
    {
        return view('public.site', ['site' => $site, 'sections' => $site->sections()->where('visible', true)->get(), 'preview' => true]);
    }

    private function addLink(Site $site, string $url): void
    {
        $kind = UrlFetcher::classify($url);
        $host = UrlFetcher::host($url);
        $label = match ($kind) {
            'google_business' => 'Google Business listing',
            'social' => Str::headline(Str::before($host, '.')).' page',
            default => 'Website ('.$host.')',
        };
        $source = $site->sources()->create(['kind' => $kind, 'label' => $label, 'url' => $url, 'status' => 'pending']);

        // The owner gave us this link, so the link itself is a fact we can show.
        $site->facts()->create([
            'source_id' => $source->id,
            'key' => $kind === 'website' ? 'website' : 'social_link',
            'value' => $url,
            'evidence' => 'Link you provided',
            'status' => Fact::CONFIRMED,
        ]);
    }

    private function addFile(Site $site, UploadedFile $file): Source
    {
        $path = $file->store("sites/{$site->id}", 'local');

        return $site->sources()->create([
            'kind' => 'upload',
            'label' => Str::limit($file->getClientOriginalName(), 80),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'status' => 'pending',
        ]);
    }
}
