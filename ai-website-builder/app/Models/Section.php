<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    public const TYPES = [
        'hero' => 'Header',
        'about' => 'About',
        'services' => 'Products & services',
        'pricing' => 'Prices',
        'highlights' => 'Good to know',
        'gallery' => 'Photos',
        'hours' => 'Opening hours',
        'contact' => 'Contact',
    ];

    /** Sections whose wording is written by the AI (the rest are laid out straight from facts). */
    public const AI_WRITTEN = ['hero', 'about', 'services'];

    protected $fillable = ['site_id', 'type', 'position', 'visible', 'content', 'origin', 'notes'];

    protected function casts(): array
    {
        return ['content' => 'array', 'visible' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SectionRevision::class)->latest('id');
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    /** Save new content and keep the previous version so it can be restored. */
    public function replaceContent(array $content, string $origin, ?string $reason = null, ?string $notes = null): void
    {
        $this->revisions()->create([
            'content' => $this->content,
            'origin' => $this->origin,
            'reason' => $reason,
        ]);
        $this->forceFill(['content' => $content, 'origin' => $origin, 'notes' => $notes])->save();
    }
}
