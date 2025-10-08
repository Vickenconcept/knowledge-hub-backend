<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CostTracking extends Model
{
    use HasUuids;

    const UPDATED_AT = null; // Only track created_at

    protected $table = 'cost_tracking';

    protected $fillable = [
        'org_id',
        'user_id',
        'operation_type',
        'model_used',
        'provider',
        'tokens_input',
        'tokens_output',
        'total_tokens',
        'cost_usd',
        'document_id',
        'conversation_id',
        'ingest_job_id',
        'query_text',
    ];

    protected $casts = [
        'tokens_input' => 'integer',
        'tokens_output' => 'integer',
        'total_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

