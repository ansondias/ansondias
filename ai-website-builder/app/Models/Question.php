<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    protected $fillable = [
        'site_id', 'type', 'field', 'dedupe_key', 'prompt', 'options',
        'allow_custom', 'blocking', 'status', 'answer',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'allow_custom' => 'boolean',
            'blocking' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
