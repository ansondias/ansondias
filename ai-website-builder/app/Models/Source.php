<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    /** How much we trust each kind of source when two of them disagree (higher wins as default). */
    public const TRUST = [
        'owner' => 100,
        'pasted' => 90,
        'upload' => 80,
        'google_business' => 70,
        'website' => 60,
        'social' => 50,
        'web_search' => 30,
    ];

    protected $fillable = [
        'site_id', 'kind', 'label', 'url', 'path', 'mime', 'original_name', 'status',
        'content', 'error', 'injection_findings', 'facts_extracted', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'injection_findings' => 'array',
            'facts_extracted' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function trust(): int
    {
        return self::TRUST[$this->kind] ?? 10;
    }

    public function displayName(): string
    {
        return $this->label;
    }
}
