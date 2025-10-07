<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'org_id', 'connector_id', 'title', 'source_url', 'mime_type', 'sha256', 'size', 's3_path', 'fetched_at'
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function chunks()
    {
        return $this->hasMany(Chunk::class, 'document_id');
    }

    public function connector()
    {
        return $this->belongsTo(Connector::class, 'connector_id');
    }
}


