<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostMetric extends Model
{
    protected $fillable = [
        'post_id', 'social_account_id', 'external_post_id',
        'likes', 'comments', 'shares', 'views', 'impressions', 'clicks', 'saves',
        'raw_metrics', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_metrics' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function getEngagementRateAttribute(): float
    {
        $engagement = $this->likes + $this->comments + $this->shares + $this->saves;
        if ($this->impressions > 0) {
            return round(($engagement / $this->impressions) * 100, 2);
        }
        if ($this->views > 0) {
            return round(($engagement / $this->views) * 100, 2);
        }
        return 0.0;
    }

    public function getTotalEngagementAttribute(): int
    {
        return $this->likes + $this->comments + $this->shares + $this->saves;
    }
}
