<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\SectionRevision;
use App\Services\Ai\AiUnavailableException;
use App\Services\Builder\SiteBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function update(Request $request, Section $section, SiteBuilder $builder): RedirectResponse
    {
        $content = $this->contentFromForm($section, $request);
        $section->replaceContent($content, 'owner', 'Edited by you');
        $section->update(['visible' => $request->boolean('visible', true) && $builder->hasContent($section->type, $content)]);

        return $this->back($section, 'Section saved.');
    }

    public function regenerate(Request $request, Section $section, SiteBuilder $builder): RedirectResponse
    {
        $instruction = $request->validate(['instruction' => 'nullable|string|max:300'])['instruction'] ?? null;
        try {
            $builder->regenerate($section, $instruction);
        } catch (AiUnavailableException $e) {
            return $this->back($section, null, 'The AI is unavailable right now ('.$e->getMessage().'). Your section was not changed - edit it by hand or try again later.');
        }
        $section->update(['visible' => $builder->hasContent($section->type, $section->fresh()->content)]);

        return $this->back($section, 'Section regenerated. The previous version is in its history.');
    }

    public function toggle(Section $section): RedirectResponse
    {
        $section->update(['visible' => ! $section->visible]);

        return $this->back($section, $section->visible ? 'Section shown.' : 'Section hidden.');
    }

    public function move(Request $request, Section $section): RedirectResponse
    {
        $sections = $section->site->sections()->get()->values();
        $index = $sections->search(fn ($s) => $s->id === $section->id);
        $swap = $request->input('direction') === 'up' ? $index - 1 : $index + 1;
        if (isset($sections[$swap])) {
            $other = $sections[$swap];
            [$a, $b] = [$section->position, $other->position];
            if ($a === $b) {
                $b = $a + ($swap > $index ? 1 : -1);
            }
            $section->update(['position' => $b]);
            $other->update(['position' => $a]);
        }

        return $this->back($section);
    }

    public function restore(SectionRevision $revision): RedirectResponse
    {
        $section = $revision->section;
        $section->replaceContent($revision->content, $revision->origin, 'Restored an earlier version');

        return $this->back($section, 'Earlier version restored.');
    }

    private function back(Section $section, ?string $ok = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->to(route('sites.show', $section->site_id).'#section-'.$section->id);

        return $error ? $redirect->with('error', $error) : ($ok ? $redirect->with('ok', $ok) : $redirect);
    }

    /** Turn the simple textarea-based editing form back into section content. */
    private function contentFromForm(Section $section, Request $request): array
    {
        $lines = fn (string $field) => collect(preg_split('/\R/', (string) $request->input($field, '')))->map(fn ($l) => trim($l))->filter()->values()->all();
        $pairs = fn (string $field, string $a, string $b) => collect($lines($field))->map(function ($line) use ($a, $b) {
            [$first, $second] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

            return [$a => $first, $b => $second];
        })->all();
        $text = fn (string $field) => trim((string) $request->input($field, ''));
        $content = $section->content;
        $content['heading'] = $text('heading') ?: ($content['heading'] ?? '');

        switch ($section->type) {
            case 'hero':
                $content = [
                    'headline' => $text('headline') ?: $section->site->business_name,
                    'subheadline' => $text('subheadline'),
                    'cta_label' => $text('cta_label'),
                    'cta_href' => $content['cta_href'] ?? '#contact',
                ];
                break;
            case 'about':
                $content['paragraphs'] = collect(preg_split('/\R\s*\R/', $text('paragraphs')))->map(fn ($p) => trim($p))->filter()->values()->all();
                break;
            case 'services':
                $content['intro'] = $text('intro');
                $content['items'] = $pairs('items', 'name', 'description');
                break;
            case 'pricing':
                $content['items'] = $pairs('items', 'name', 'price');
                $content['note'] = $text('note');
                break;
            case 'highlights':
                $content['items'] = $lines('items');
                break;
            case 'hours':
                $content['lines'] = $lines('lines');
                break;
            case 'gallery':
                $remove = (array) $request->input('remove', []);
                $alts = (array) $request->input('alt', []);
                $content['images'] = collect($content['images'] ?? [])
                    ->reject(fn ($img) => in_array((string) $img['source_id'], $remove, true))
                    ->map(fn ($img) => ['source_id' => $img['source_id'], 'alt' => trim($alts[$img['source_id']] ?? $img['alt'])])
                    ->values()->all();
                break;
            case 'contact':
                $address = $text('address');
                $content = [
                    'heading' => $content['heading'],
                    'phones' => $lines('phones'),
                    'whatsapp' => $lines('whatsapp'),
                    'emails' => $lines('emails'),
                    'address' => $address ?: null,
                    'maps_url' => $address ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($section->site->business_name.', '.$address) : null,
                    'website' => $text('website') ?: null,
                    'socials' => collect($pairs('socials', 'label', 'url'))->filter(fn ($s) => preg_match('#^https?://#', $s['url']))->values()->all(),
                ];
                break;
        }

        return $content;
    }
}
