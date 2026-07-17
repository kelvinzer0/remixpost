<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $fillable = ['user_id', 'name', 'token', 'last_used_at'];

    protected $hidden = ['token'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a new API key token (prefixed with 'rk_' for remixpost key).
     */
    public static function generateToken(): string
    {
        return 'rk_' . bin2hex(random_bytes(32));
    }
}
