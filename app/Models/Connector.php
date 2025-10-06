<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Connector extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'org_id', 'type', 'label', 'encrypted_tokens', 'status', 'last_synced_at'
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'connector_id');
    }
}


