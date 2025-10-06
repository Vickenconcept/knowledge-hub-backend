<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QueryLog extends Model
{
    use HasFactory;

    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'org_id', 'user_id', 'query_text', 'top_k', 'result_chunk_ids', 'model_used', 'cost_estimate', 'created_at'
    ];

    protected $casts = [
        'result_chunk_ids' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }
}


