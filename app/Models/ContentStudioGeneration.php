<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContentStudioGeneration extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'user_id',
        'title',
        'query',
        'format',
        'tone',
        'channel',
        'max_outputs',
        'source_document_ids',
        'source_tags',
        'outputs_count',
        'images_count',
        'status',
        'metadata',
    ];

    protected $casts = [
        'source_document_ids' => 'array',
        'source_tags' => 'array',
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

    public function items()
    {
        return $this->hasMany(ContentStudioGenerationItem::class, 'generation_id');
    }
}
