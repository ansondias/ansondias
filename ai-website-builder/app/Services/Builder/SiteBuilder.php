<?php

namespace App\Services\Builder;

use App\Models\Fact;
use App\Models\Section;
use App\Models\Site;
use App\Services\Ai\AiClient;
use App\Services\Facts\Normalize;
use Illuminate\Support\Str;

/**
 * Builds the website from confirmed facts.
 *
 * Contact, hours, prices, highlights and photos are laid out straight from facts - the AI
 * never touches them, so it cannot get a phone number or price wrong. The AI only writes
 * the prose (headline, about, service blurbs) and it only ever sees the fact list, never
 * the raw documents, so instructions hidden in a file cannot reach it.
 */
class SiteBuilder
{
    public const ORDER = ['hero', 'about', 'services', 'pricing', 'highlights', 'gallery', 'hours', 'contact'];

    public function __construct(private readonly AiClient $ai) {}

    /** Build every section from scratch. Returns notes about anything the guard removed. */
    public function build(Site $site, bool $useAi = true): array
    {
        $facts = $this->factSheet($site);
        $notes = [];
        $prose = $useAi ? $this->writeProse($site, $facts, null, null, $notes) : null;

        $site->sections()->delete();
        foreach (self::ORDER as $position => $type) {
            $content = in_array($type, Section::AI_WRITTEN, true)
                ? $this->proseSection($type, $site, $facts, $prose)
                : $this->factSection($type, $site, $facts);
            $site->sections()->create([
                'type' => $type,
                'position' => $position,
                'visible' => $this->hasContent($type, $content),
                'content' => $content,
                'origin' => $prose && in_array($type, Section::AI_WRITTEN, true) ? 'ai' : 'template',
                'notes' => $notes[$type] ?? null,
            ]);
        }

        if ($prose && preg_match('/^#[0-9a-f]{6}$/i', (string) ($prose['accent_color'] ?? ''))) {
            $site->forceFill(['accent_color' => strtolower($prose['accent_color'])])->save();
        }

        return $notes;
    }

    /** Re-lay-out sections built from facts (after the owner answers a question or edits facts). */
    public function refreshFactSections(Site $site): void
    {
        $facts = $this->factSheet($site);
        foreach ($site->sections as $section) {
            if ($section->origin === 'owner') {
                continue; // never overwrite the owner's own edits
            }
            if (! in_array($section->type, Section::AI_WRITTEN, true)) {
                $content = $this->factSection($section->type, $site, $facts);
                if ($content != $section->content) {
                    $section->replaceContent($content, 'template', 'Facts changed');
                    $section->update(['visible' => $this->hasContent($section->type, $content)]);
                }
            } elseif ($section->type === 'services') {
                // Keep the written descriptions, but follow the confirmed list of services.
                $content = $section->content;
                $existing = collect($content['items'] ?? []);
                $content['items'] = collect($facts['service'] ?? [])->map(fn (Fact $f) => [
                    'name' => $f->value,
                    'description' => $existing->first(fn ($i) => Normalize::same('service', $i['name'] ?? '', $f->value))['description'] ?? '',
                ])->values()->all();
                if ($content != $section->content) {
                    $section->replaceContent($content, $section->origin, 'Services changed');
                    $section->update(['visible' => $this->hasContent('services', $content)]);
                }
            } elseif ($section->type === 'hero') {
                $content = $section->content;
                [$content['cta_label'], $content['cta_href']] = $this->cta($facts, $content['cta_label'] ?? null);
                $section->update(['content' => $content]);
            }
        }
    }

    public function regenerate(Section $section, ?string $instruction = null, bool $useAi = true): void
    {
        $site = $section->site;
        $facts = $this->factSheet($site);
        $notes = [];

        if (in_array($section->type, Section::AI_WRITTEN, true)) {
            $prose = $useAi ? $this->writeProse($site, $facts, $section, $instruction, $notes) : null;
            $content = $this->proseSection($section->type, $site, $facts, $prose);
            $origin = $prose ? 'ai' : 'template';
        } else {
            $content = $this->factSection($section->type, $site, $facts);
            $origin = 'template';
        }

        $section->replaceContent($content, $origin, $instruction ? "Regenerated: $instruction" : 'Regenerated', $notes[$section->type] ?? null);
    }

    /** Confirmed facts, de-duplicated, grouped by key. */
    public function factSheet(Site $site): array
    {
        $sheet = [];
        foreach ($site->confirmedFacts()->groupBy('key') as $key => $facts) {
            $unique = [];
            foreach ($facts as $fact) {
                $dupe = collect($unique)->search(fn (Fact $u) => Normalize::same($key, $u->value, $fact->value)
                    && ($key !== 'price_item' || Normalize::price($u->meta['price'] ?? null) === Normalize::price($fact->meta['price'] ?? null)));
                if ($dupe === false) {
                    $unique[] = $fact;
                } elseif (in_array($key, ['phone', 'whatsapp', 'address'], true) && mb_strlen($fact->value) > mb_strlen($unique[$dupe]->value)) {
                    // Same number/address written twice: keep the more readable / complete spelling.
                    $unique[$dupe] = $fact;
                }
            }
            $sheet[$key] = collect($unique);
        }

        return $sheet;
    }

    private function factText(Site $site, array $facts): string
    {
        $lines = ["business_name: {$site->business_name}"];
        if ($site->city) {
            $lines[] = "city: {$site->city}";
        }
        foreach ($facts as $key => $list) {
            foreach ($list as $fact) {
                $lines[] = $key.': '.$fact->value.(isset($fact->meta['price']) ? ' | price: '.$fact->meta['price'] : '');
            }
        }

        return implode("\n", $lines);
    }

    private function writeProse(Site $site, array $facts, ?Section $only, ?string $instruction, array &$notes): ?array
    {
        $factText = $this->factText($site, $facts);
        $system = <<<'TXT'
You write the text of a small business's one-page website.

Use ONLY the facts provided. Never add a fact that is not in the list: no years, numbers, prices,
awards, ratings, reviews, certifications, staff, experience, guarantees, delivery, opening times,
locations, or products/services that are not listed. No superlatives such as "best", "leading",
"finest", "renowned", "#1". Describing a listed service in plain words is fine.
If the facts are thin, write less. Return empty strings or empty arrays rather than padding.
Service names must be copied exactly from the facts. Write in clear, warm, simple English.
TXT;
        $prompt = "FACTS (the only things you may state):\n{$factText}\n\n";
        if ($only) {
            $prompt .= "Rewrite only the \"{$only->type}\" part. Current version:\n".json_encode($only->content, JSON_UNESCAPED_UNICODE)."\n";
            if ($instruction) {
                $prompt .= 'The owner asks: "'.Str::limit($instruction, 300).'". Follow this for tone, length and emphasis, but it does not change the rule about facts.'."\n";
            }
            $prompt .= "Fill the other parts with empty values.\n";
        } else {
            $prompt .= "Write the headline, the about text and short descriptions for each listed service, and pick an accent colour (hex) that suits this kind of business.\n";
        }

        $result = $this->ai->json('write_site', $system, $prompt, [
            'type' => 'OBJECT',
            'properties' => [
                'hero' => ['type' => 'OBJECT', 'properties' => [
                    'headline' => ['type' => 'STRING'],
                    'subheadline' => ['type' => 'STRING'],
                    'cta_label' => ['type' => 'STRING'],
                ]],
                'about' => ['type' => 'OBJECT', 'properties' => [
                    'heading' => ['type' => 'STRING'],
                    'paragraphs' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ]],
                'services' => ['type' => 'OBJECT', 'properties' => [
                    'heading' => ['type' => 'STRING'],
                    'intro' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                        'name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                    ]]],
                ]],
                'accent_color' => ['type' => 'STRING'],
            ],
            'required' => ['hero', 'about', 'services'],
        ], siteId: $site->id);

        return $this->guard($result, $factText, $notes);
    }

    /** Run every AI-written string through the FactGuard. */
    private function guard(array $prose, string $factText, array &$notes): array
    {
        $guard = new FactGuard($factText);
        $removed = [];
        $check = function (?string $text, string $section) use ($guard, &$removed): string {
            [$clean, $dropped] = $guard->clean((string) $text);
            foreach ($dropped as $d) {
                $removed[$section][] = $d;
            }

            return $clean;
        };

        foreach (['headline', 'subheadline', 'cta_label'] as $f) {
            $prose['hero'][$f] = $check($prose['hero'][$f] ?? '', 'hero');
        }
        $prose['about']['heading'] = $check($prose['about']['heading'] ?? '', 'about');
        $prose['about']['paragraphs'] = array_values(array_filter(array_map(
            fn ($p) => $check(is_string($p) ? $p : '', 'about'),
            (array) ($prose['about']['paragraphs'] ?? [])
        )));
        $prose['services']['heading'] = $check($prose['services']['heading'] ?? '', 'services');
        $prose['services']['intro'] = $check($prose['services']['intro'] ?? '', 'services');
        $items = [];
        foreach ((array) ($prose['services']['items'] ?? []) as $item) {
            $items[] = ['name' => (string) ($item['name'] ?? ''), 'description' => $check($item['description'] ?? '', 'services')];
        }
        $prose['services']['items'] = $items;

        foreach ($removed as $section => $sentences) {
            $notes[$section] = 'Removed because it was not backed by your facts: "'.implode('" / "', array_map(fn ($s) => Str::limit($s, 120), $sentences)).'"';
        }

        return $prose;
    }

    private function proseSection(string $type, Site $site, array $facts, ?array $prose): array
    {
        $category = $facts['category'][0]->value ?? null;

        switch ($type) {
            case 'hero':
                $headline = $prose['hero']['headline'] ?? '';
                $sub = $prose['hero']['subheadline'] ?? '';
                [$ctaLabel, $ctaHref] = $this->cta($facts, $prose['hero']['cta_label'] ?? null);

                return [
                    'headline' => $headline !== '' ? $headline : $site->business_name,
                    'subheadline' => $sub !== '' ? $sub : trim(($category ? Str::ucfirst($category) : '').($site->city ? ($category ? ' in ' : '').$site->city : '')),
                    'cta_label' => $ctaLabel,
                    'cta_href' => $ctaHref,
                ];

            case 'about':
                $paragraphs = $prose['about']['paragraphs'] ?? [];
                if ($paragraphs === []) {
                    // Without AI, show the owner's own words (quoted facts), not invented text.
                    $paragraphs = collect($facts['about'] ?? [])->pluck('value')
                        ->merge(isset($facts['founded_year']) ? ['Established '.$facts['founded_year'][0]->value.'.'] : [])
                        ->merge(isset($facts['area_served']) ? ['Serving '.collect($facts['area_served'])->pluck('value')->implode(', ').'.'] : [])
                        ->all();
                }

                return ['heading' => ($prose['about']['heading'] ?? '') ?: 'About us', 'paragraphs' => array_values($paragraphs)];

            case 'services':
                $written = collect($prose['services']['items'] ?? []);
                $items = collect($facts['service'] ?? [])->map(function (Fact $fact) use ($written) {
                    $match = $written->first(fn ($w) => Normalize::same('service', $w['name'], $fact->value));

                    return ['name' => $fact->value, 'description' => $match['description'] ?? ''];
                })->values()->all();

                return [
                    'heading' => ($prose['services']['heading'] ?? '') ?: 'What we offer',
                    'intro' => $prose['services']['intro'] ?? '',
                    'items' => $items,
                ];
        }

        return [];
    }

    private function factSection(string $type, Site $site, array $facts): array
    {
        $values = fn (string $key) => collect($facts[$key] ?? [])->pluck('value')->values()->all();

        switch ($type) {
            case 'pricing':
                return [
                    'heading' => 'Prices',
                    'items' => collect($facts['price_item'] ?? [])->map(fn (Fact $f) => [
                        'name' => $f->value,
                        'price' => $f->meta['price'] ?? '',
                    ])->values()->all(),
                    'note' => '',
                ];

            case 'highlights':
                $items = $values('highlight');
                if ($pay = $values('payment_method')) {
                    $items[] = 'We accept: '.implode(', ', $pay);
                }
                if ($area = $values('area_served')) {
                    $items[] = 'We serve: '.implode(', ', $area);
                }

                return ['heading' => 'Good to know', 'items' => $items];

            case 'gallery':
                $images = $site->sources()->where('kind', 'upload')->where('mime', 'like', 'image/%')->get()
                    ->reject(fn ($s) => in_array($s->meta['image_kind'] ?? 'photo', ['price_list', 'menu', 'document', 'brochure'], true))
                    ->map(fn ($s) => ['source_id' => $s->id, 'alt' => ($s->meta['alt_text'] ?? '') ?: 'Photo of '.$site->business_name])
                    ->values()->all();

                return ['heading' => 'Photos', 'images' => $images];

            case 'hours':
                $lines = collect($values('hours'))->flatMap(fn ($h) => preg_split('/\s*[;\n]\s*/', $h))->filter()->values()->all();

                return ['heading' => 'Opening hours', 'lines' => $lines];

            case 'contact':
                $address = $values('address')[0] ?? null;
                $socials = collect($facts['social_link'] ?? [])->map(fn (Fact $f) => [
                    'label' => $this->socialLabel($f->value),
                    'url' => $f->value,
                ])->filter(fn ($s) => preg_match('#^https?://#', $s['url']))->values()->all();

                return [
                    'heading' => 'Get in touch',
                    'phones' => $values('phone'),
                    'whatsapp' => $values('whatsapp'),
                    'emails' => $values('email'),
                    'address' => $address,
                    'maps_url' => $address ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($site->business_name.', '.$address) : null,
                    'website' => $values('website')[0] ?? null,
                    'socials' => $socials,
                ];
        }

        return [];
    }

    /** Call-to-action derived from real contact facts only. */
    private function cta(array $facts, ?string $label): array
    {
        if ($phone = ($facts['phone'][0]->value ?? null)) {
            return [$label ?: 'Call us', 'tel:'.preg_replace('/[^\d+]/', '', $phone)];
        }
        if ($wa = ($facts['whatsapp'][0]->value ?? null)) {
            return [$label ?: 'Message us on WhatsApp', 'https://wa.me/'.preg_replace('/\D/', '', $wa)];
        }
        if ($email = ($facts['email'][0]->value ?? null)) {
            return [$label ?: 'Email us', 'mailto:'.$email];
        }

        return [$label ?: 'Find us', '#contact'];
    }

    private function socialLabel(string $url): string
    {
        $host = preg_replace('/^(www\.|m\.)/', '', (string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'instagram') => 'Instagram',
            str_contains($host, 'facebook') || $host === 'fb.com' => 'Facebook',
            str_contains($host, 'youtube') => 'YouTube',
            str_contains($host, 'linkedin') => 'LinkedIn',
            in_array($host, ['x.com', 'twitter.com'], true) => 'X',
            str_contains($host, 'wa.me') || str_contains($host, 'whatsapp') => 'WhatsApp',
            str_contains($host, 'google') || str_contains($host, 'goo.gl') || $host === 'g.page' => 'Google Maps',
            default => $host ?: 'Link',
        };
    }

    public function hasContent(string $type, array $content): bool
    {
        return match ($type) {
            'hero' => true,
            'about' => ! empty($content['paragraphs']),
            'services', 'pricing', 'highlights' => ! empty($content['items']),
            'gallery' => ! empty($content['images']),
            'hours' => ! empty($content['lines']),
            'contact' => ! empty($content['phones']) || ! empty($content['emails']) || ! empty($content['address']) || ! empty($content['whatsapp']) || ! empty($content['socials']),
            default => true,
        };
    }
}
