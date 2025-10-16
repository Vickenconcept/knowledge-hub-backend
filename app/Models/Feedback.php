<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'message_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the conversation that owns the feedback
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the message that owns the feedback
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the user that gave the feedback
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get only positive feedback
     */
    public function scopePositive($query)
    {
        return $query->where('rating', 'up');
    }

    /**
     * Scope to get only negative feedback
     */
    public function scopeNegative($query)
    {
        return $query->where('rating', 'down');
    }

    /**
     * Get feedback with comments
     */
    public function scopeWithComments($query)
    {
        return $query->whereNotNull('comment');
    }

    /**
     * Check if feedback is positive
     */
    public function isPositive(): bool
    {
        return $this->rating === 'up';
    }

    /**
     * Check if feedback is negative
     */
    public function isNegative(): bool
    {
        return $this->rating === 'down';
    }
}