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
}
