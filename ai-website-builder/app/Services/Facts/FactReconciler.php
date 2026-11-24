<?php

namespace App\Services\Facts;

use App\Models\Fact;
use App\Models\Question;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Compares what every source says. Agreement is confirmed; disagreement becomes a question
 * for the owner. Nothing is decided by "majority vote" - two phone numbers might both be right.
 */
class FactReconciler
{
    public function reconcile(Site $site): void
    {
        $facts = $site->facts()->with('source')->where('status', '!=', Fact::REJECTED)->get();

        foreach (Fact::SINGLE_VALUED as $key) {
            $this->reconcileSingle($site, $facts->where('key', $key)->values(), $key);
        }
        $this->reconcilePrices($site, $facts->where('key', 'price_item')->values());
    }

    private function reconcileSingle(Site $site, Collection $facts, string $key): void
    {
        if ($facts->isEmpty()) {
            return;
        }
        // The owner's own answer always wins.
        $owner = $facts->filter(fn (Fact $f) => $f->source?->kind === 'owner');
        if ($owner->isNotEmpty()) {
            $ownerKeys = $owner->map(fn ($f) => Normalize::key($key, $f->value))->all();
            foreach ($facts as $fact) {
                $keep = $owner->contains($fact) || in_array(Normalize::key($key, $fact->value), $ownerKeys, true);
                $fact->update(['status' => $keep ? Fact::CONFIRMED : Fact::REJECTED]);
            }
            $this->closeFieldQuestions($site, $key);

            return;
        }

        $clusters = $this->cluster($facts, $key);

        if (count($clusters) === 1) {
            $cluster = $clusters[0];
            $onlyWeb = $cluster->every(fn (Fact $f) => $f->source?->kind === 'web_search');
            $dedupe = "confirm:$key:".md5(Normalize::key($key, $cluster->first()->value));
            if ($onlyWeb && $this->wasAnswered($site, $dedupe)) {
                return; // the owner's yes/no already set the status
            }
            if ($onlyWeb) {
                // Found only by web search: plausible, but we do not publish it until the owner says so.
                $cluster->each(fn (Fact $f) => $f->update(['status' => Fact::CONFLICT]));
                $value = $cluster->first()->value;
                $this->ask($site, $dedupe, [
                    'type' => 'confirm',
                    'field' => $key,
                    'prompt' => 'We found this '.strtolower(Fact::KEYS[$key]).' for you on the web: "'.$value.'". Should we show it on your website?',
                    'options' => [
                        ['value' => 'yes', 'label' => 'Yes, that is correct', 'facts' => $cluster->pluck('id')->all()],
                        ['value' => 'no', 'label' => 'No, leave it out', 'facts' => []],
                    ],
                    'allow_custom' => true,
                    'blocking' => false,
                ]);

                return;
            }
            $cluster->each(fn (Fact $f) => $f->update(['status' => Fact::CONFIRMED]));
            $this->closeFieldQuestions($site, $key);

            return;
        }

        // Same set of competing values as a question the owner already answered: keep their choice.
        // A genuinely new value produces a new set, and so a new question.
        $setKey = collect($clusters)->map(fn ($c) => Normalize::key($key, $c->first()->value))->sort()->implode('|');
        $dedupe = "conflict:$key:".md5($setKey);
        if ($this->wasAnswered($site, $dedupe)) {
            return;
        }
        // Any older open question about this field is superseded by this one.
        $site->questions()->where('field', $key)->where('status', 'open')->where('dedupe_key', '!=', $dedupe)->delete();

        $facts->each(fn (Fact $f) => $f->update(['status' => Fact::CONFLICT]));
        $options = [];
        foreach ($clusters as $i => $cluster) {
            $sources = $cluster->map(fn (Fact $f) => $f->sourceLabel())->unique()->implode(', ');
            $options[] = [
                'value' => "c$i",
                'label' => $cluster->first()->value.'  (from '.$sources.')',
                'facts' => $cluster->pluck('id')->all(),
            ];
        }
        if (in_array($key, ['phone', 'email', 'whatsapp'], true)) {
            $options[] = ['value' => 'all', 'label' => 'Show all of them', 'facts' => $facts->pluck('id')->all()];
        }

        $this->ask($site, $dedupe, [
            'type' => 'conflict',
            'field' => $key,
            'prompt' => 'Your sources disagree about your '.strtolower(Fact::KEYS[$key]).'. Which one is correct?',
            'options' => $options,
            'allow_custom' => true,
            'blocking' => true,
        ]);
    }

    private function reconcilePrices(Site $site, Collection $facts): void
    {
        foreach ($facts->groupBy(fn (Fact $f) => Normalize::words($f->value)) as $name => $group) {
            $byPrice = $group->groupBy(fn (Fact $f) => Normalize::price($f->meta['price'] ?? null));
            $dedupe = 'conflict:price:'.md5($name);
            if ($byPrice->count() <= 1 || $this->wasAnswered($site, $dedupe)) {
                continue;
            }
            if ($group->contains(fn (Fact $f) => $f->source?->kind === 'owner')) {
                continue;
            }
            $group->each(fn (Fact $f) => $f->update(['status' => Fact::CONFLICT]));
            $options = $byPrice->values()->map(fn ($cluster, $i) => [
                'value' => "c$i",
                'label' => ($cluster->first()->meta['price'] ?? '?').'  (from '.$cluster->map(fn ($f) => $f->sourceLabel())->unique()->implode(', ').')',
                'facts' => $cluster->pluck('id')->all(),
            ])->all();
            $this->ask($site, $dedupe, [
                'type' => 'conflict',
                'field' => 'price_item',
                'prompt' => 'Two of your sources list different prices for "'.$group->first()->value.'". Which is current?',
                'options' => $options,
                'allow_custom' => true,
                'blocking' => true,
            ]);
        }
    }

    /** @return array<int, Collection<int, Fact>> */
    private function cluster(Collection $facts, string $key): array
    {
        $clusters = [];
        foreach ($facts as $fact) {
            foreach ($clusters as $i => $cluster) {
                if (Normalize::same($key, $cluster->first()->value, $fact->value)) {
                    $clusters[$i]->push($fact);

                    continue 2;
                }
            }
            $clusters[] = collect([$fact]);
        }

        return $clusters;
    }

    private function ask(Site $site, string $dedupe, array $attributes): void
    {
        $existing = $site->questions()->where('dedupe_key', $dedupe)->where('status', 'open')->first();
        if ($existing) {
            $existing->update($attributes);

            return;
        }
        $site->questions()->create($attributes + ['dedupe_key' => $dedupe]);
    }

    private function wasAnswered(Site $site, string $dedupe): bool
    {
        return $site->questions()->where('dedupe_key', $dedupe)->whereIn('status', ['answered', 'skipped'])->exists();
    }

    /** Conflict / confirm questions about a field that no longer has competing values. */
    private function closeFieldQuestions(Site $site, string $key): void
    {
        $site->questions()->where('field', $key)->whereIn('type', ['conflict', 'confirm'])->where('status', 'open')->delete();
    }
}
