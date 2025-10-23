<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserConnectorPermission extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'connector_id', 'permission_level'
    ];

    protected $casts = [
        'permission_level' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function connector()
    {
        return $this->belongsTo(Connector::class);
    }

    // Permission level hierarchy
    public function canRead()
    {
        return in_array($this->permission_level, ['read', 'write', 'admin']);
    }

    public function canWrite()
    {
        return in_array($this->permission_level, ['write', 'admin']);
    }

    public function canAdmin()
    {
        return $this->permission_level === 'admin';
    }
}
