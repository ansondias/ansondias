<?php

namespace App\Services\Ingest;

class FetchResult
{
    public function __construct(
        public readonly string $status,   // read | blocked | failed
        public readonly string $text = '',
        public readonly ?string $error = null,
        public readonly array $meta = [],
    ) {}
}
