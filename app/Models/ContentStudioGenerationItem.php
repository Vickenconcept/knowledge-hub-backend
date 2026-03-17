<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContentStudioGenerationItem extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'generation_id',
        'org_id',
        'user_id',
        'sort_order',
        'item_type',
        'title',
        'content',
        'cta',
        'image_url',
        'image_prompt',
        'source_document_ids',
        'metadata',
    ];

    protected $casts = [
        'source_document_ids' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function generation()
    {
        return $this->belongsTo(ContentStudioGeneration::class, 'generation_id');
    }
}
