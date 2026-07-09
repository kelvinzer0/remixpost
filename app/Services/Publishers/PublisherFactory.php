<?php

namespace App\Services\Publishers;

use InvalidArgumentException;

class PublisherFactory
{
    public static function make(string $provider): PublisherInterface
    {
        return match ($provider) {
            'twitter' => new TwitterPublisher(),
            'facebook' => new FacebookPublisher(),
            'instagram' => new InstagramPublisher(),
            'linkedin' => new LinkedInPublisher(),
            'mastodon' => new MastodonPublisher(),
            'telegram' => new TelegramPublisher(),
            'pinterest' => new PinterestPublisher(),
            'youtube' => new YouTubePublisher(),
            'tiktok' => new TikTokPublisher(),
            'email' => new EmailPublisher(),
            'discord' => new DiscordPublisher(),
            'buffer' => new BufferPublisher(),
            'whatsapp' => new WhatsAppPublisher(),
            default => throw new InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }
}
