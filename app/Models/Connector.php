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
        'id', 'org_id', 'type', 'label', 'workspace_name', 'workspace_id', 'connection_scope', 
        'is_primary', 'workspace_metadata', 'encrypted_tokens', 'metadata', 'status', 'last_synced_at'
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
        'workspace_metadata' => 'array',
        'is_primary' => 'boolean',
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

    public function chunks()
    {
        return $this->hasManyThrough(Chunk::class, Document::class, 'connector_id', 'document_id');
    }

    public function userPermissions()
    {
        return $this->hasMany(UserConnectorPermission::class, 'connector_id');
    }

    // Workspace-aware methods
    public function isPersonal()
    {
        return $this->connection_scope === 'personal';
    }

    public function isOrganization()
    {
        return $this->connection_scope === 'organization';
    }

    public function getWorkspaceDisplayName()
    {
        return $this->workspace_name ?: $this->label;
    }

    public function getScopeIcon()
    {
        return $this->isPersonal() ? '👤' : '🏢';
    }

    public function getScopeLabel()
    {
        return $this->isPersonal() ? 'Personal' : 'Organization';
    }

    // Check if user has access to this connector
    public function userHasAccess($userId, $permissionLevel = 'read')
    {
        if ($this->isOrganization()) {
            return true; // All org members have access to org connectors
        }

        // For personal connectors, check user permissions
        $permission = $this->userPermissions()
            ->where('user_id', $userId)
            ->where('permission_level', '>=', $permissionLevel)
            ->first();

        return $permission !== null;
    }

    // Get the primary user for this connector (for document attribution)
    public function getPrimaryUser()
    {
        if ($this->isPersonal()) {
            // For personal connectors, get the admin user
            $adminPermission = $this->userPermissions()
                ->where('permission_level', 'admin')
                ->first();
            return $adminPermission ? $adminPermission->user_id : null;
        }

        // For organization connectors, we need to get the user who created it
        // This is a bit tricky since org connectors don't have direct user attribution
        // We'll use the first user with admin permission, or fall back to the first user in the org
        $adminPermission = $this->userPermissions()
            ->where('permission_level', 'admin')
            ->first();
        
        if ($adminPermission) {
            return $adminPermission->user_id;
        }

        // Fallback: get the first user in the organization
        $firstUser = \App\Models\User::where('org_id', $this->org_id)->first();
        return $firstUser ? $firstUser->id : null;
    }
}


