<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'owner_id', 'settings', 'plan'
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });

        static::created(function ($model) {
            // Auto-assign new organization to Free tier
            \App\Services\SubscriptionService::assignFreeTier($model->id);
        });
    }

    public function users()
    {
        return $this->hasMany(User::class, 'org_id');
    }

    public function connectors()
    {
        return $this->hasMany(Connector::class, 'org_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'org_id');
    }
    
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}


