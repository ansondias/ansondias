<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SitePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $siteId, public bool $useAi = true) {}

    public function handle(SitePipeline $pipeline): void
    {
        $site = Site::find($this->siteId);
        if ($site) {
            $pipeline->run($site, $this->useAi);
        }
    }
}
