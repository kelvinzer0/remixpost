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
 *  - requires_media: bool      — at least one media item is mandatory
 *  - media_type: string|null   — 'image' | 'video' | null (any)
 *  - allows_text_only: bool    — true if posting text without media is allowed
 *  - max_content_length: int|null — provider-specific character limit (null = no limit)
 *  - label: string             — human-readable name
 *  - color: string             — tailwind bg-* class for avatar/badge
 *  - notes: string             — extra guidance for user
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
                'max_content_length' => 280,
                'notes' => 'Text-only OK. Max 280 characters for free accounts.',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'color' => 'bg-blue-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'max_content_length' => 63206,
                'notes' => 'Text-only OK. Image/video optional.',
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'color' => 'bg-blue-700',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'max_content_length' => 3000,
                'notes' => 'Text-only OK. Image/video optional.',
            ],
            'instagram' => [
                'label' => 'Instagram',
                'color' => 'bg-pink-500',
                'requires_media' => true,
                'media_type' => null, // image OR video
                'allows_text_only' => false,
                'max_content_length' => 2200,
                'notes' => 'REQUIRES at least one image or video. Text-only posts not supported by Instagram API.',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'color' => 'bg-red-600',
                'requires_media' => true,
                'media_type' => 'video',
                'allows_text_only' => false,
                'max_content_length' => 5000,
                'notes' => 'REQUIRES a video file. Content text becomes video description. Image-only posts not supported.',
            ],
            'tiktok' => [
                'label' => 'TikTok',
                'color' => 'bg-black',
                'requires_media' => true,
                'media_type' => 'video',
                'allows_text_only' => false,
                'max_content_length' => 2200,
                'notes' => 'REQUIRES a video file. Content text becomes video caption.',
            ],
            'pinterest' => [
                'label' => 'Pinterest',
                'color' => 'bg-red-700',
                'requires_media' => true,
                'media_type' => 'image',
                'allows_text_only' => false,
                'max_content_length' => 500,
                'notes' => 'REQUIRES at least one image. Video pins use a different flow and are not supported yet. A board must be configured on the account.',
            ],
            'mastodon' => [
                'label' => 'Mastodon',
                'color' => 'bg-purple-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'max_content_length' => 500,
                'notes' => 'Text-only OK. Most instances limit to 500 characters.',
            ],
            'telegram' => [
                'label' => 'Telegram',
                'color' => 'bg-blue-500',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'max_content_length' => 4096,
                'notes' => 'Text-only OK. Media becomes photo with caption.',
            ],
            'email' => [
                'label' => 'Email',
                'color' => 'bg-gray-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'max_content_length' => null,
                'notes' => 'Text-only OK. First image (if any) is embedded inline.',
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
     *
     * @param  array  $providers  e.g. ['pinterest', 'linkedin']
     * @param  string $content
     * @param  array  $mediaUrls  e.g. ['https://.../foo.jpg']
     * @return array  ['pinterest' => 'Pinterest requires at least one image...', ...]
     */
    public static function validate(array $providers, string $content, array $mediaUrls): array
    {
        $errors = [];

        foreach ($providers as $provider) {
            $req = self::for($provider);
            if (!$req) {
                continue;
            }

            // Media requirement
            if ($req['requires_media'] && empty($mediaUrls)) {
                $typeLabel = $req['media_type'] ? ucfirst($req['media_type']) : 'Image or video';
                $errors[$provider] = "{$req['label']} requires at least one {$typeLabel}. Add media or remove this account.";
                continue;
            }

            // Media type-specific check (e.g. YouTube needs video, Pinterest needs image)
            if ($req['requires_media'] && $req['media_type']) {
                $hasCorrectType = false;
                foreach ($mediaUrls as $url) {
                    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    if ($req['media_type'] === 'video' && in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'])) {
                        $hasCorrectType = true;
                        break;
                    }
                    if ($req['media_type'] === 'image' && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
                        $hasCorrectType = true;
                        break;
                    }
                }
                if (!$hasCorrectType) {
                    $errors[$provider] = "{$req['label']} requires at least one {$req['media_type']} file. Current media does not match.";
                    continue;
                }
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
