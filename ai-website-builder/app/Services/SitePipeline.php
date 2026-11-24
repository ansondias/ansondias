<?php

namespace App\Services;

use App\Models\Site;
use App\Models\Source;
use App\Services\Ai\AiUnavailableException;
use App\Services\Builder\SiteBuilder;
use App\Services\Facts\FactExtractor;
use App\Services\Facts\FactReconciler;
use App\Services\Ingest\SourceIngestor;
use App\Services\Research\WebResearcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The whole build, as resumable steps. Every step saves its result before the next one starts,
 * so if the AI goes down halfway the owner loses nothing and "Retry" picks up where it stopped.
 *
 *   read sources -> extract facts -> web search & pick the right business -> read its website
 *   -> compare sources (ask about disagreements) -> build sections -> ask about missing basics
 */
class SitePipeline
{
    public function __construct(
        private readonly SourceIngestor $ingestor,
        private readonly FactExtractor $extractor,
        private readonly WebResearcher $researcher,
        private readonly FactReconciler $reconciler,
        private readonly SiteBuilder $builder,
    ) {}

    public function run(Site $site, bool $useAi = true): void
    {
        @set_time_limit(600);
        $site->forceFill(['last_error' => null])->save();

        try {
            $this->readAndExtract($site, $useAi);

            if (! $site->research_done) {
                if ($useAi && $this->researcher->canSearch()) {
                    $site->moveTo('processing', 'Searching the web for your business');
                    $this->researcher->research($site);
                    $this->readAndExtract($site, $useAi); // e.g. the website we just discovered
                } else {
                    $site->forceFill(['research_done' => true, 'identity_resolved' => true])->save();
                }
            }

            if (! $site->identity_resolved) {
                $site->moveTo('awaiting_answers', 'Tell us which business is yours');

                return;
            }

            $site->moveTo('processing', 'Comparing your sources');
            $this->reconciler->reconcile($site);

            if ($site->hasBlockingQuestions()) {
                $site->moveTo('awaiting_answers', 'Some sources disagree - please choose');

                return;
            }

            if ($site->sections()->doesntExist()) {
                $site->moveTo('processing', $useAi ? 'Writing your website' : 'Laying out your website');
                $this->builder->build($site, $useAi);
            } else {
                $this->builder->refreshFactSections($site);
            }

            $this->askAboutMissingBasics($site);
            $site->moveTo('ready');
        } catch (AiUnavailableException $e) {
            $site->forceFill([
                'stage' => 'failed',
                'stage_detail' => 'The AI service is unavailable',
                'last_error' => $e->getMessage(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('Site pipeline failed', ['site' => $site->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $site->forceFill([
                'stage' => 'failed',
                'stage_detail' => 'Something went wrong',
                'last_error' => $e->getMessage(),
            ])->save();
        }
    }

    private function readAndExtract(Site $site, bool $useAi): void
    {
        foreach ($site->sources()->where('status', 'pending')->get() as $source) {
            $site->moveTo('processing', 'Reading '.$source->label);
            $this->ingestor->ingest($source, $useAi);
        }

        $toExtract = $site->sources()->where('facts_extracted', false)->whereIn('status', ['read', 'blocked'])->get();
        foreach ($toExtract as $source) {
            /** @var Source $source */
            $site->moveTo('processing', 'Finding facts in '.$source->label);
            $this->extractor->extract($site, $source, $useAi);
        }
    }

    /** Non-blocking prompts for the basics a website needs, so we ask instead of guessing. */
    private function askAboutMissingBasics(Site $site): void
    {
        $have = $site->facts()->whereIn('status', ['confirmed', 'conflict'])->pluck('key')->unique()->all();
        $wanted = [
            'phone' => ['missing' => ! array_intersect(['phone', 'whatsapp', 'email'], $have), 'prompt' => 'We could not find a phone number, WhatsApp or email. How should customers contact you?'],
            'address' => ['missing' => ! in_array('address', $have, true), 'prompt' => 'What is your address (or the area you serve)?'],
            'hours' => ['missing' => ! in_array('hours', $have, true), 'prompt' => 'What are your opening hours? (e.g. Mon-Sat 10am-8pm, Sunday closed)'],
            'service' => ['missing' => ! in_array('service', $have, true), 'prompt' => 'What are your main products or services? One per line.'],
        ];
        foreach ($wanted as $field => $q) {
            if ($q['missing']) {
                $site->questions()->firstOrCreate(['dedupe_key' => "missing:$field"], [
                    'type' => 'missing', 'field' => $field, 'prompt' => $q['prompt'], 'allow_custom' => true, 'blocking' => false,
                ]);
            }
        }
    }
}
