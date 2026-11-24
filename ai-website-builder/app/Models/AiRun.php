<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    protected $fillable = ['site_id', 'purpose', 'model', 'status', 'latency_ms', 'prompt_chars', 'error'];
}
