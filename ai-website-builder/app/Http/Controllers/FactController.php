<?php

namespace App\Http\Controllers;

use App\Models\Fact;
use App\Models\Site;
use App\Services\Builder\SiteBuilder;
use App\Services\Facts\FactReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The owner can add, correct or remove any fact. Their word is final. */
class FactController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(Fact::KEYS))],
            'value' => 'required|string|max:1000',
            'price' => 'nullable|string|max:100',
        ]);
        $site->facts()->create([
            'source_id' => $this->ownerSource($site)->id,
            'key' => $data['key'],
            'value' => trim($data['value']),
            'meta' => filled($data['price'] ?? null) ? ['price' => trim($data['price'])] : null,
            'evidence' => 'You told us',
            'status' => Fact::CONFIRMED,
        ]);

        return $this->refresh($site, 'Fact added.');
    }

    public function update(Request $request, Fact $fact): RedirectResponse
    {
        $data = $request->validate(['value' => 'required|string|max:1000', 'price' => 'nullable|string|max:100']);
        $fact->update([
            'value' => trim($data['value']),
            'meta' => filled($data['price'] ?? null) ? ['price' => trim($data['price'])] : $fact->meta,
            'source_id' => $this->ownerSource($fact->site)->id,
            'evidence' => 'Edited by you',
            'status' => Fact::CONFIRMED,
        ]);

        return $this->refresh($fact->site, 'Fact updated.');
    }

    public function destroy(Fact $fact): RedirectResponse
    {
        $fact->update(['status' => Fact::REJECTED]);

        return $this->refresh($fact->site, 'Fact removed from your site.');
    }

    private function refresh(Site $site, string $message): RedirectResponse
    {
        app(FactReconciler::class)->reconcile($site);
        if ($site->sections()->exists()) {
            app(SiteBuilder::class)->refreshFactSections($site);
        }

        return redirect()->to(route('sites.show', $site).'#facts')->with('ok', $message);
    }

    private function ownerSource(Site $site)
    {
        return $site->sources()->firstOrCreate(['kind' => 'owner'], ['label' => 'You', 'status' => 'read', 'facts_extracted' => true]);
    }
}
