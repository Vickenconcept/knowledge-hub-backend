<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ConversationSummary extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'org_id',
        'summary',
        'key_topics',
        'entities_mentioned',
        'decisions_made',
        'message_count',
        'turn_start',
        'turn_end',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'key_topics' => 'array',
        'entities_mentioned' => 'array',
        'decisions_made' => 'array',
        'message_count' => 'integer',
        'turn_start' => 'integer',
        'turn_end' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

