<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionRevision extends Model
{
    protected $fillable = ['section_id', 'content', 'origin', 'reason'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
