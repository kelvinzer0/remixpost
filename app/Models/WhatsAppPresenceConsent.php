<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppPresenceConsent extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_presence_consents';

    protected $fillable = [
        'user_id',
        'social_account_id',
        'jid',
        'display_name',
        'phone',
        'consent_method',
        'consent_given_at',
        'is_active',
        'consent_expires_at',
        'notes',
    ];

    protected $casts = [
        'consent_given_at' => 'datetime',
        'consent_expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function samples()
    {
        return $this->hasMany(WhatsAppPresenceSample::class, 'consent_id');
    }

    /**
     * Scope: only consents that should be tracked right now.
     * - is_active = true
     * - consent not expired (or no expiry set)
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('consent_expires_at')
                    ->orWhere('consent_expires_at', '>', now());
            });
    }

    /**
     * Convert a phone number (6281234567890) to a WhatsApp JID.
     */
    public static function phoneToJid(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        return $phone . '@s.whatsapp.net';
    }
}
