<?php

namespace App\Services;

/**
 * Single source of truth for per-platform post requirements.
 *
 * Used by:
 *  - PostController (backend validation on store/update)
 *  - Inertia shared data (frontend live validation in Posts/Create.vue and Posts/Edit.vue)
 *
 * Schema for each provider:
 *  - requires_media: bool        — at least one media item is mandatory
 *  - media_type: string|null     — 'image' | 'video' | null (any)
 *  - allows_text_only: bool      — true if posting text without media is allowed
 *  - supports_image: bool        — true if image upload is supported (optional or required)
 *  - supports_video: bool        — true if video upload is supported (optional or required)
 *  - max_content_length: int|null — provider-specific character limit (null = no limit)
 *  - label: string               — human-readable name
 *  - color: string               — tailwind bg-* class for avatar/badge
 *  - notes: string               — extra guidance for user
 */
class PlatformRequirements
{
    public static function all(): array
    {
        return [
            'twitter' => [
                'label' => 'Twitter/X',
                'color' => 'bg-black',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 280,
                'notes' => 'Text-only OK. Max 280 chars for free accounts. Supports up to 4 images or 1 video.',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'color' => 'bg-blue-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 63206,
                'notes' => 'Text-only OK. Supports multiple images or single video.',
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'color' => 'bg-blue-700',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 3000,
                'notes' => 'Text-only OK. Supports multiple images OR single video per post.',
            ],
            'instagram' => [
                'label' => 'Instagram',
                'color' => 'bg-pink-500',
                'requires_media' => true,
                'media_type' => null, // image OR video
                'allows_text_only' => false,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 2200,
                'notes' => 'REQUIRES image or video. Text-only not supported by Instagram API.',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'color' => 'bg-red-600',
                'requires_media' => true,
                'media_type' => 'video',
                'allows_text_only' => false,
                'supports_image' => false,
                'supports_video' => true,
                'max_content_length' => 5000,
                'notes' => 'REQUIRES a video file. Content becomes video description.',
            ],
            'tiktok' => [
                'label' => 'TikTok',
                'color' => 'bg-black',
                'requires_media' => true,
                'media_type' => 'video',
                'allows_text_only' => false,
                'supports_image' => false,
                'supports_video' => true,
                'max_content_length' => 2200,
                'notes' => 'REQUIRES a video file. Content becomes video caption.',
            ],
            'pinterest' => [
                'label' => 'Pinterest',
                'color' => 'bg-red-700',
                'requires_media' => true,
                'media_type' => null, // image OR video
                'allows_text_only' => false,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 500,
                'notes' => 'REQUIRES image or video. Single media per pin. Board must be configured on the account.',
            ],
            'mastodon' => [
                'label' => 'Mastodon',
                'color' => 'bg-purple-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 500,
                'notes' => 'Text-only OK. Most instances limit to 500 chars.',
            ],
            'telegram' => [
                'label' => 'Telegram',
                'color' => 'bg-blue-500',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 4096,
                'notes' => 'Text-only OK. Media becomes photo with caption.',
            ],
            'email' => [
                'label' => 'Email',
                'color' => 'bg-gray-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => null,
                'notes' => 'Text-only OK. Images embedded inline; videos and other media become clickable links.',
            ],
            'discord' => [
                'label' => 'Discord',
                'color' => 'bg-indigo-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'max_content_length' => 2000,
                'notes' => 'Text-only OK. Posts via webhook. Max 2000 chars per message. Supports up to 10 attachments (images + videos).',
            ],
        ];
    }

    public static function for(string $provider): ?array
    {
        return self::all()[$provider] ?? null;
    }

    /**
     * Validate a post payload against the requirements of a set of providers.
     * Returns array of errors keyed by provider name. Empty array = valid.
     */
    public static function validate(array $providers, string $content, array $mediaUrls): array
    {
        $errors = [];

        foreach ($providers as $provider) {
            $req = self::for($provider);
            if (!$req) {
                continue;
            }

            // Categorize current media
            $hasImage = false;
            $hasVideo = false;
            foreach ($mediaUrls as $url) {
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'])) {
                    $hasVideo = true;
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
                    $hasImage = true;
                }
            }
            $hasMedia = !empty($mediaUrls);

            // Media requirement
            if ($req['requires_media'] && !$hasMedia) {
                $typeLabel = $req['media_type'] ? ucfirst($req['media_type']) : 'Image or video';
                $errors[$provider] = "{$req['label']} requires at least one {$typeLabel}. Add media or remove this account.";
                continue;
            }

            // Media type-specific check
            if ($req['requires_media'] && $req['media_type']) {
                $hasCorrectType = ($req['media_type'] === 'video' && $hasVideo)
                    || ($req['media_type'] === 'image' && $hasImage);
                if (!$hasCorrectType) {
                    $errors[$provider] = "{$req['label']} requires at least one {$req['media_type']} file. Current media does not match.";
                    continue;
                }
            }

            // Supported type check — warn if user attached media type the platform doesn't support
            // (e.g. YouTube post with image only)
            if ($hasMedia && !$req['supports_image'] && $hasImage && !$hasVideo) {
                $errors[$provider] = "{$req['label']} does not support image-only posts. Add a video file.";
                continue;
            }
            if ($hasMedia && !$req['supports_video'] && $hasVideo && !$hasImage) {
                $errors[$provider] = "{$req['label']} does not support video posts. Add an image file.";
                continue;
            }

            // Text requirement (only enforced if media is not required)
            if (!$req['allows_text_only'] && trim($content) === '') {
                $errors[$provider] = "{$req['label']} requires caption text.";
                continue;
            }

            // Content length
            if ($req['max_content_length'] && mb_strlen($content) > $req['max_content_length']) {
                $errors[$provider] = "{$req['label']} content exceeds {$req['max_content_length']} characters (current: " . mb_strlen($content) . ").";
                continue;
            }
        }

        return $errors;
    }
}
