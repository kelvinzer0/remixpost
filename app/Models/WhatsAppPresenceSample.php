<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppPresenceSample extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_presence_samples';

    protected $fillable = [
        'consent_id',
        'social_account_id',
        'jid',
        'status',
        'last_seen_at',
        'sampled_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'sampled_at' => 'datetime',
    ];

    public function consent()
    {
        return $this->belongsTo(WhatsAppPresenceConsent::class, 'consent_id');
    }

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
