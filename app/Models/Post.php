<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'user_id',
        'content',
        'media_urls',
        'watermark_settings',
        'tags',
        'first_comment',
        'alt_text',
        'linkedin_doc_title',
        'account_overrides',
        'scheduled_at',
        'published_at',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'watermark_settings' => 'array',
            'tags' => 'array',
            'account_overrides' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccounts()
    {
        return $this->belongsToMany(SocialAccount::class, 'post_social_account');
    }

    public function metrics()
    {
        return $this->hasMany(PostMetric::class);
    }
}
