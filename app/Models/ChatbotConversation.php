<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotConversation extends Model
{
    protected $primaryKey = 'conversation_id';

    protected $fillable = [
        'account_id',
        'title',
        'is_pinned',
        'last_message_at',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id', 'conversation_id')
            ->orderBy('sent_at')
            ->orderBy('message_id');
    }
}