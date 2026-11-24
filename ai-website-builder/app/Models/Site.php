<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    protected $fillable = [
        'business_name', 'city', 'slug', 'stage', 'stage_detail', 'last_error',
        'theme', 'accent_color', 'identity', 'identity_resolved', 'research_done', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'identity' => 'array',
            'identity_resolved' => 'boolean',
            'research_done' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            if (! $site->slug) {
                $site->slug = static::uniqueSlug(trim($site->business_name.' '.$site->city));
            }
        });
    }

    public static function uniqueSlug(string $text): string
    {
        $base = Str::slug($text) ?: 'site';
        $slug = $base;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position');
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public function confirmedFacts()
    {
        return $this->facts()->where('status', Fact::CONFIRMED)->with('source')->orderBy('id')->get();
    }

    public function openQuestions()
    {
        return $this->questions()->where('status', 'open')->orderByDesc('blocking')->orderBy('id')->get();
    }

    public function hasBlockingQuestions(): bool
    {
        return $this->questions()->where('status', 'open')->where('blocking', true)->exists();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isBusy(): bool
    {
        return in_array($this->stage, ['queued', 'processing'], true);
    }

    public function moveTo(string $stage, ?string $detail = null): void
    {
        $this->forceFill(['stage' => $stage, 'stage_detail' => $detail])->save();
    }
}
