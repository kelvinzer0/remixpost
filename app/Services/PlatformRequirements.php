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
 *  - supports_image: bool        — true if image upload is supported
 *  - supports_video: bool        — true if video upload is supported
 *  - supports_pdf: bool          — true if PDF document upload is supported
 *  - supports_tags: bool         — true if hashtags/tags can be sent natively
 *  - supports_first_comment: bool — true if first comment auto-post is supported
 *  - max_content_length: int|null — provider-specific character limit
 *  - max_media_size_mb: int|null — max file size per media item in MB
 *  - max_media_count: int|null   — max number of media items per post
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
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => false,
                'max_content_length' => 280,
                'max_media_size_mb' => 5,
                'max_media_count' => 4,
                'notes' => 'Text-only OK. Max 280 chars. Up to 4 images or 1 video. Tags appended to text.',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'color' => 'bg-blue-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => true,
                'max_content_length' => 63206,
                'max_media_size_mb' => 100,
                'max_media_count' => 10,
                'notes' => 'Text-only OK. Multiple images or single video. First comment supported.',
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'color' => 'bg-blue-700',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => true,
                'supports_tags' => true,
                'supports_first_comment' => true,
                'max_content_length' => 3000,
                'max_media_size_mb' => 100,
                'max_media_count' => 9,
                'notes' => 'Text-only OK. Images OR video OR PDF (carousel, 1:1, max 300 pages). Tags + first comment supported.',
            ],
            'instagram' => [
                'label' => 'Instagram',
                'color' => 'bg-pink-500',
                'requires_media' => true,
                'media_type' => null,
                'allows_text_only' => false,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => true,
                'max_content_length' => 2200,
                'max_media_size_mb' => 100,
                'max_media_count' => 10,
                'notes' => 'REQUIRES media. Tags appended to caption. First comment supported (great for hashtags).',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'color' => 'bg-red-600',
                'requires_media' => true,
                'media_type' => 'video',
                'allows_text_only' => false,
                'supports_image' => false,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => true,
                'max_content_length' => 5000,
                'max_media_size_mb' => 256,
                'max_media_count' => 1,
                'notes' => 'REQUIRES video. Tags sent as video tags. Content becomes description.',
            ],
            'tiktok' => [
                'label' => 'TikTok',
                'color' => 'bg-black',
                'requires_media' => true,
                'media_type' => 'video',
                'allows_text_only' => false,
                'supports_image' => false,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => false,
                'max_content_length' => 2200,
                'max_media_size_mb' => 287,
                'max_media_count' => 1,
                'notes' => 'REQUIRES video. Tags appended to caption.',
            ],
            'pinterest' => [
                'label' => 'Pinterest',
                'color' => 'bg-red-700',
                'requires_media' => true,
                'media_type' => null,
                'allows_text_only' => false,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => false,
                'supports_first_comment' => false,
                'max_content_length' => 500,
                'max_media_size_mb' => 100,
                'max_media_count' => 1,
                'notes' => 'REQUIRES image or video. Single media per pin. Board configured on account.',
            ],
            'mastodon' => [
                'label' => 'Mastodon',
                'color' => 'bg-purple-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => false,
                'max_content_length' => 500,
                'max_media_size_mb' => 40,
                'max_media_count' => 4,
                'notes' => 'Text-only OK. Tags as #hashtags in text. Max 500 chars.',
            ],
            'telegram' => [
                'label' => 'Telegram',
                'color' => 'bg-blue-500',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => false,
                'max_content_length' => 4096,
                'max_media_size_mb' => 50,
                'max_media_count' => 10,
                'notes' => 'Text-only OK. Tags as #hashtags in text. Media via upload (max 50MB) or URL (max 5MB for images).',
            ],
            'email' => [
                'label' => 'Email',
                'color' => 'bg-gray-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => true,
                'supports_tags' => false,
                'supports_first_comment' => false,
                'max_content_length' => null,
                'max_media_size_mb' => null,
                'max_media_count' => null,
                'notes' => 'Text-only OK. Images inline; videos/PDFs as clickable links.',
            ],
            'discord' => [
                'label' => 'Discord',
                'color' => 'bg-indigo-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => false,
                'supports_first_comment' => false,
                'max_content_length' => 2000,
                'max_media_size_mb' => 25,
                'max_media_count' => 10,
                'notes' => 'Text-only OK. Posts via webhook. Max 2000 chars. Up to 10 attachments (25MB each).',
            ],
            'buffer' => [
                'label' => 'Buffer',
                'color' => 'bg-blue-900',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => false,
                'supports_tags' => true,
                'supports_first_comment' => true,
                'max_content_length' => null,
                'max_media_size_mb' => null,
                'max_media_count' => 10,
                'notes' => 'Aggregator — routes to Buffer channel. Tags + first comment sent via Buffer metadata. Media must be public HTTPS URL.',
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'color' => 'bg-green-600',
                'requires_media' => false,
                'media_type' => null,
                'allows_text_only' => true,
                'supports_image' => true,
                'supports_video' => true,
                'supports_pdf' => true,
                'supports_tags' => true,
                'supports_first_comment' => false,
                'max_content_length' => 65536,
                'max_media_size_mb' => 64,
                'max_media_count' => 1,
                'notes' => 'Via Evolution API (Baileys). Pilih target saat buat post: User / Group / Channel / Story. Story butuh media.',
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
