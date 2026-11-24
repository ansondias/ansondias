<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fact extends Model
{
    public const CONFIRMED = 'confirmed';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    /** Fields where a business normally has exactly one value; disagreement means we must ask. */
    public const SINGLE_VALUED = ['phone', 'email', 'address', 'hours', 'whatsapp', 'website', 'founded_year'];

    /** The closed vocabulary the extractor may use. Anything else is discarded. */
    public const KEYS = [
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'address' => 'Address',
        'hours' => 'Opening hours',
        'website' => 'Website',
        'social_link' => 'Social profile',
        'category' => 'Type of business',
        'about' => 'About statement',
        'service' => 'Product / service',
        'price_item' => 'Price',
        'highlight' => 'Highlight / feature',
        'area_served' => 'Area served',
        'payment_method' => 'Payment method',
        'founded_year' => 'Founded',
        'owner_name' => 'Owner / team',
    ];

    protected $fillable = ['site_id', 'source_id', 'key', 'value', 'meta', 'evidence', 'status'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function label(): string
    {
        return self::KEYS[$this->key] ?? $this->key;
    }

    public function sourceLabel(): string
    {
        return $this->source?->label ?? 'You';
    }

    public function display(): string
    {
        if ($this->key === 'price_item' && ! empty($this->meta['price'])) {
            return $this->value.' — '.$this->meta['price'];
        }

        return $this->value;
    }
}
