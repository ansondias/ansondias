<?php

namespace App\Services;

use App\Models\Fact;
use App\Models\Question;
use App\Models\Site;
use App\Models\Source;
use App\Services\Research\WebResearcher;
use Illuminate\Support\Str;

/** Applies the owner's answer. The owner is the final authority on every fact. */
class QuestionAnswerer
{
    public function __construct(private readonly WebResearcher $researcher) {}

    public function answer(Question $question, ?string $choice, ?string $custom): void
    {
        $site = $question->site;
        $custom = $custom !== null ? trim($custom) : null;
        $custom = $custom === '' ? null : $custom;
        $option = collect($question->options ?? [])->firstWhere('value', $choice);

        switch ($question->type) {
            case 'identity':
                $search = $site->sources()->where('kind', 'web_search')->latest('id')->first();
                $candidates = $search?->meta['candidates'] ?? [];
                $candidate = ($choice !== null && $choice !== 'none' && isset($candidates[(int) $choice])) ? $candidates[(int) $choice] : null;
                $this->researcher->applyIdentity($site, $candidate, $search);
                break;

            case 'conflict':
            case 'confirm':
                $related = $this->relatedFacts($question);
                if ($custom !== null) {
                    $related->each(fn (Fact $f) => $f->update(['status' => Fact::REJECTED]));
                    $this->ownerFact($site, $question->field, $custom, $related->first());
                } else {
                    $chosen = collect($option['facts'] ?? []);
                    $related->each(fn (Fact $f) => $f->update(['status' => $chosen->contains($f->id) ? Fact::CONFIRMED : Fact::REJECTED]));
                }
                break;

            case 'missing':
                if ($custom !== null) {
                    $lines = in_array($question->field, ['service'], true)
                        ? preg_split('/\R|,(?![^(]*\))/', $custom)
                        : [$custom];
                    foreach (array_filter(array_map('trim', $lines)) as $line) {
                        $this->ownerFact($site, $this->guessContactKey($question->field, $line), $line);
                    }
                }
                break;

            case 'blocked_source':
                if ($custom !== null) {
                    $original = Source::find((int) Str::after((string) $question->field, 'source:'));
                    $site->sources()->create([
                        'kind' => 'pasted',
                        'label' => 'Pasted from '.($original?->label ?? 'a blocked link'),
                        'url' => $original?->url,
                        'status' => 'pending',
                        'content' => Str::limit($custom, 20000, ''),
                    ]);
                }
                break;
        }

        $question->update([
            'status' => ($choice === null && $custom === null) ? 'skipped' : 'answered',
            'answer' => $custom ?? ($option['label'] ?? $choice),
        ]);
    }

    public function skip(Question $question): void
    {
        if (in_array($question->type, ['conflict', 'confirm'], true)) {
            // Not sure? Then we do not publish any of the competing values.
            $this->relatedFacts($question)->each(fn (Fact $f) => $f->update(['status' => Fact::REJECTED]));
        }
        if ($question->type === 'identity') {
            $this->researcher->applyIdentity($question->site, null);
        }
        $question->update(['status' => 'skipped']);
    }

    private function relatedFacts(Question $question)
    {
        $ids = collect($question->options ?? [])->pluck('facts')->flatten()->filter()->unique();

        return Fact::whereIn('id', $ids)->where('site_id', $question->site_id)->get();
    }

    private function ownerFact(Site $site, string $key, string $value, ?Fact $like = null): Fact
    {
        $owner = $site->sources()->firstOrCreate(
            ['kind' => 'owner'],
            ['label' => 'You', 'status' => 'read', 'facts_extracted' => true],
        );
        $meta = null;
        if ($key === 'price_item') {
            // "Haircut ₹250" typed as a custom answer to a price conflict: the value is the price.
            $meta = ['price' => $value];
            $value = $like?->value ?? $value;
        }

        return $site->facts()->create([
            'source_id' => $owner->id,
            'key' => $key,
            'value' => $value,
            'meta' => $meta,
            'evidence' => 'You told us',
            'status' => Fact::CONFIRMED,
        ]);
    }

    /** "How can customers contact you?" may be answered with an email or a phone number. */
    private function guessContactKey(string $field, string $value): string
    {
        if ($field !== 'phone') {
            return $field === 'address' ? 'address' : $field;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        return preg_match('/whats\s?app|wa\.me/i', $value) ? 'whatsapp' : 'phone';
    }
}
