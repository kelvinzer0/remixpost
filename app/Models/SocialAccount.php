<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'name',
        'username',
        'avatar',
        'access_token',
        'refresh_token',
        'expires_at',
        'is_active',
        'metadata',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'post_social_account');
    }

    /**
     * Get a single key from metadata with default fallback.
     * Used by publishers to read provider-specific config.
     */
    public function getMeta(string $key, $default = null)
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Accessor: has_refresh_token
     * Exposed as has_refresh_token in JSON serialization even though
     * refresh_token itself is hidden. Used by UI to show 'Reconnect required'
     * badge for YouTube accounts that lack a refresh_token.
     */
    public function getHasRefreshTokenAttribute(): bool
    {
        return !empty($this->refresh_token);
    }

    /**
     * Append computed attributes to array serialization.
     */
    protected $appends = ['has_refresh_token'];
}
