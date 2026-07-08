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
            default => throw new InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }
}
