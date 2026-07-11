<?php

namespace App\Services\Publishers;

use App\Models\SocialAccount;
use App\Services\MediaType;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Buffer Publisher — aggregator that routes posts to Buffer channels.
 *
 * Buffer's GraphQL API creates posts on connected social accounts
 * (Facebook, Instagram, Twitter/X, LinkedIn, Pinterest, TikTok, YouTube,
 * Mastodon, Threads, Bluesky, Google Business).
 *
 * Authentication: OAuth 2.0 + PKCE (mandatory).
 * - Access tokens expire in 3600s (1 hour)
 * - Refresh tokens are SINGLE-USE and ROTATING — must save the new
 *   refresh_token returned by every refresh call, never reuse old one
 *   (reuse revokes all tokens for that grant)
 *
 * Each connected Buffer channel = one SocialAccount row (provider='buffer').
 * The same Buffer access_token is shared across all channels owned by the
 * same user (we use the access_token of whichever account the publish job
 * is for; refresh logic updates ALL buffer accounts for the same user to
 * avoid race conditions).
 *
 * API reference:
 *   - Auth: https://auth.buffer.com/auth (authorize), /token (exchange + refresh)
 *   - GraphQL: POST https://api.buffer.com with {query, variables}
 *   - All responses are HTTP 200 (except 429 rate limit). Errors live in
 *     the response body: either data.{mutation}.{message} (typed) or top-level
 *     errors[] array (system errors like UNAUTHORIZED).
 *
 * @license Apache-2.0 (implemented from Buffer API docs, not derived from third-party code)
 */
class BufferPublisher implements PublisherInterface
{
    /**
     * Publish a post to a single Buffer channel.
     *
     * The account passed in must be a 'buffer' provider account. Its
     * metadata must contain: organization_id, channel_id, channel_service,
     * channel_name. The access_token is shared across all the user's
     * Buffer channels, but stored per-row — refresh logic updates all rows
     * for the same user to stay in sync.
     */
    public function publish(array $post, array $account): array
    {
        try {
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $scheduledAt = $post['scheduled_at'] ?? null;
            $tags = $post['tags'] ?? [];
            $firstComment = $post['first_comment'] ?? null;
            $altText = $post['alt_text'] ?? null;

            // Refresh access token if needed (Buffer tokens last 1 hour)
            $accessToken = $this->ensureFreshAccessToken($account);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'Buffer access token expired and could not be refreshed. Disconnect and re-connect the Buffer account.',
                ];
            }

            // Read channel info from metadata
            $metadata = is_string($account['metadata'] ?? null)
                ? json_decode($account['metadata'], true) ?? []
                : ($account['metadata'] ?? []);
            $channelId = $metadata['channel_id'] ?? $account['provider_id'];

            // Apply per-post overrides (user can pick different board/IG mode per post)
            // Overrides are stored as { "accountId": { "pinterest_board_id": "xxx" } }
            $accountId = (string) ($post['account_id'] ?? $account['id'] ?? '');
            $overrides = $post['account_overrides'] ?? [];
            if (!empty($overrides[$accountId])) {
                $metadata = array_merge($metadata, $overrides[$accountId]);
            }

            if (!$channelId) {
                return [
                    'success' => false,
                    'error' => 'Buffer channel_id missing from account metadata. Re-connect the account.',
                ];
            }

            // Build GraphQL mutation
            // Mode logic:
            //   - If scheduled_at is in the future → mode=customScheduled, dueAt=ISO 8601 UTC
            //   - If scheduled_at is in the past or now → mode=shareNow (post immediately)
            $mode = 'shareNow';
            $dueAt = null;
            if (!empty($scheduledAt)) {
                try {
                    $scheduled = \Carbon\Carbon::parse($scheduledAt);
                    if ($scheduled->isFuture()) {
                        $mode = 'customScheduled';
                        $dueAt = $scheduled->toIso8601String();
                    }
                } catch (Exception $e) {
                    // If parse fails, fall back to shareNow
                }
            }

            // Build per-channel metadata (tags, first comment, Pinterest board, IG post type, etc.)
            $channelService = $metadata['channel_service'] ?? 'unknown';

            // Instagram Reel/Story only support 1 media item per post.
            // If IG type is reel or story with multiple media, trim to first item.
            // (Post type supports carousel — multiple images OK.)
            if ($channelService === 'instagram') {
                $igType = $metadata['instagram_post_type'] ?? 'post';
                if (in_array($igType, ['reel', 'story']) && count($mediaUrls) > 1) {
                    $mediaUrls = [$mediaUrls[0]];
                    $assetsGraphQL = $this->buildAssetsGraphQL($mediaUrls);
                }
            }

            // Pinterest via Buffer: VIDEO IS NOT SUPPORTED by Buffer's public API,
            // but GIF IS supported (confirmed via Buffer docs + live API test).
            //
            // Buffer docs (https://developers.buffer.com/reference.html):
            //   "Note: 'video' is not supported via public API."
            //   "Please use image, gif, or document types."
            //
            // Strategy: convert video → animated GIF via ffmpeg.
            // Pinterest displays animated GIFs as playable animated pins
            // (preserves motion context — unlike static JPEG thumbnails).
            //
            // Live API test (2026-07-11):
            //   - Image-only pin (JPEG) → ✅ SUCCESS
            //   - GIF pin (1.2MB, 3s, 360px, 8fps, 128 colors) → ✅ SUCCESS
            //     Pin URL: https://www.pinterest.com/pin/1146166174349429951
            //     Confirmed animated GIF on Pinterest:
            //     https://i.pinimg.com/originals/df/7c/2b/df7c2b93f4d37483d8a8b9450ec0a9f7.gif
            //   - Video pin (any) → ❌ FAIL ("could not fetch image" / "video not supported")
            //   - GIF too large (5.9MB, 5s, 480px, 10fps) → ❌ FAIL ("something went wrong")
            //
            // GIF generation params (verified working):
            //   - Duration: 3 seconds (first 3s of video — captures motion context)
            //   - Width: 360px (preserve aspect ratio, mobile-friendly)
            //   - FPS: 8 (smooth enough for GIF, keeps file size down)
            //   - Colors: 128 (palettegen max_colors — good quality/size balance)
            //   - Dither: bayer bayer_scale=5 (good for photographic content)
            //   - Output: ~1-2MB (well under Pinterest's processing limit)
            //
            // Fallback: if GIF generation fails (e.g., video < 3s, ffmpeg error),
            // fall back to static JPEG thumbnail (frame at 1s) — better than failing.
            //
            // To publish REAL video pins, user must connect Pinterest directly
            // (PinterestPublisher.php supports video via Pinterest API v5
            // /v5/media upload flow) — but that requires Pinterest API approval.
            if ($channelService === 'pinterest') {
                $mediaUrls = $this->convertVideosToGifs($mediaUrls);
            }

            // Build assets array from media URLs
            $assetsGraphQL = $this->buildAssetsGraphQL($mediaUrls);

            // Scheduling type logic (verified via Buffer GraphQL introspection 2026-07-12):
            //   SchedulingType enum: 'automatic' | 'notification'
            //   - 'automatic' = Buffer auto-publishes via API
            //   - 'notification' = Buffer sends push notification to user's mobile app,
            //     user opens Buffer app → edits post (adds music, stickers, effects)
            //     → publishes natively. Required for Instagram Reels/Stories with
            //     music + TikTok with sounds/effects.
            //   Ref: https://support.buffer.com/article/658-using-notification-publishing
            //        https://support.buffer.com/article/933-adding-music-stickers-and-other-effects-to-instagram-and-tiktok-posts
            //
            // Auto-detect: Instagram & TikTok via Buffer default to 'notification'
            // because Buffer API cannot attach music/stickers/effects. User must
            // edit in Buffer mobile app to add those before publishing natively.
            // Other channels (Facebook, LinkedIn, Twitter, etc.) use 'automatic'.
            //
            // Per-post override: user can force 'automatic' or 'notification' via
            // account_overrides[channelId].scheduling_type (useful for IG feed
            // posts that don't need music → 'automatic' is faster).
            $schedulingType = 'automatic'; // default
            if (in_array($channelService, ['instagram', 'tiktok'], true)) {
                $schedulingType = 'notification';
            }

            // Check per-post override (accountId = account['id'])
            $accountId = (string) ($post['account_id'] ?? $account['id'] ?? '');
            $overrides = $post['account_overrides'] ?? [];
            if (!empty($overrides[$accountId]['scheduling_type'])) {
                $overrideType = $overrides[$accountId]['scheduling_type'];
                if (in_array($overrideType, ['automatic', 'notification'], true)) {
                    $schedulingType = $overrideType;
                }
            }

            // Build metadata GraphQL (needs schedulingType + overrides for stickerFields)
            $metadataGraphQL = $this->buildMetadataGraphQL($channelService, $tags, $firstComment, $metadata, $schedulingType, $overrides, $accountId);

            // Notification mode requires dueAt (scheduled time) — Buffer sends
            // notification at that time. Cannot use shareNow with notification.
            if ($schedulingType === 'notification' && $mode === 'shareNow') {
                // Force customScheduled with dueAt = now + 5 min (minimum future time)
                $mode = 'customScheduled';
                $dueAt = now()->addMinutes(5)->toIso8601String();
            }

            // Build mutation input — note: GraphQL input fields use camelCase
            $inputLines = [
                'text: ' . json_encode($content),
                'channelId: ' . json_encode($channelId),
                'schedulingType: ' . $schedulingType,
                'mode: ' . $mode,
            ];
            if ($dueAt) {
                $inputLines[] = 'dueAt: ' . json_encode($dueAt);
            }
            if (!empty($assetsGraphQL)) {
                $inputLines[] = 'assets: [' . implode(', ', $assetsGraphQL) . ']';
            }
            if ($metadataGraphQL) {
                $inputLines[] = 'metadata: { ' . $metadataGraphQL . ' }';
            }

            $mutation = 'mutation {
  createPost(input: {
    ' . implode(",\n    ", $inputLines) . '
  }) {
    ... on PostActionSuccess { post { id status dueAt } }
    ... on MutationError { message }
  }
}';

            // For notification mode, check if user has active mobile device registered.
            // If not, the notification won't be delivered — warn the user in info message.
            $hasActiveDevice = null; // null = unknown, true/false after check
            if ($schedulingType === 'notification') {
                $deviceQuery = 'query { channel(input: { id: ' . json_encode($channelId) . ' }) { hasActiveMemberDevice } }';
                $deviceResp = Http::withToken($accessToken)->post(config('services.buffer.api_url'), ['query' => $deviceQuery]);
                $deviceBody = $deviceResp->json();
                $hasActiveDevice = $deviceBody['data']['channel']['hasActiveMemberDevice'] ?? null;
            }

            // Send to Buffer GraphQL API
            $response = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), [
                    'query' => $mutation,
                ]);

            $body = $response->json();

            // Top-level errors (UNAUTHORIZED, FORBIDDEN, etc.)
            if (isset($body['errors']) && !empty($body['errors'])) {
                $errMsg = $body['errors'][0]['message'] ?? 'Unknown Buffer API error';
                $errCode = $body['errors'][0]['extensions']['code'] ?? 'UNKNOWN';

                if ($errCode === 'UNAUTHORIZED') {
                    $errMsg .= ' — Token may have been revoked. Re-connect Buffer.';
                } elseif ($errCode === 'RATE_LIMIT_EXCEEDED') {
                    $retryAfter = $body['errors'][0]['extensions']['retryAfter'] ?? 900;
                    $errMsg .= " — Rate limited, retry after {$retryAfter}s.";
                }

                return [
                    'success' => false,
                    'error' => "Buffer API error ({$errCode}): {$errMsg}",
                    'response' => $body,
                ];
            }

            // Typed mutation error (data.createPost.message)
            $createPost = $body['data']['createPost'] ?? null;
            if ($createPost && isset($createPost['message']) && !isset($createPost['post'])) {
                $errMsg = $createPost['message'];

                // Retry without firstComment if the error is about paid plan
                // (firstComment requires Buffer paid plan — free plan can't use it)
                if (str_contains(strtolower($errMsg), 'first comment') && str_contains(strtolower($errMsg), 'paid plan')) {
                    // Rebuild mutation without firstComment
                    $metadataGraphQLRetry = $this->buildMetadataGraphQL($channelService, $tags, null, $metadata);
                    $inputLinesRetry = [
                        'text: ' . json_encode($content),
                        'channelId: ' . json_encode($channelId),
                        'schedulingType: ' . $schedulingType,
                        'mode: ' . $mode,
                    ];
                    if ($dueAt) {
                        $inputLinesRetry[] = 'dueAt: ' . json_encode($dueAt);
                    }
                    if (!empty($assetsGraphQL)) {
                        $inputLinesRetry[] = 'assets: [' . implode(', ', $assetsGraphQL) . ']';
                    }
                    if ($metadataGraphQLRetry) {
                        $inputLinesRetry[] = 'metadata: { ' . $metadataGraphQLRetry . ' }';
                    }

                    $mutationRetry = 'mutation {
  createPost(input: {
    ' . implode(",\n    ", $inputLinesRetry) . '
  }) {
    ... on PostActionSuccess { post { id status dueAt } }
    ... on MutationError { message }
  }
}';

                    $responseRetry = Http::withToken($accessToken)
                        ->post(config('services.buffer.api_url'), [
                            'query' => $mutationRetry,
                        ]);

                    $bodyRetry = $responseRetry->json();

                    if (isset($bodyRetry['errors']) && !empty($bodyRetry['errors'])) {
                        return [
                            'success' => false,
                            'error' => "Buffer API error (retry without firstComment): " . ($bodyRetry['errors'][0]['message'] ?? 'Unknown'),
                            'response' => $bodyRetry,
                        ];
                    }

                    $createPostRetry = $bodyRetry['data']['createPost'] ?? null;
                    if ($createPostRetry && isset($createPostRetry['post'])) {
                        $postId = $createPostRetry['post']['id'] ?? null;
                        $status = $createPostRetry['post']['status'] ?? 'scheduled';
                        return [
                            'success' => true,
                            'external_id' => $postId,
                            'info' => 'Post created in Buffer (status: ' . $status . ', mode: ' . $mode . '). First comment skipped — requires paid plan.',
                        ];
                    }

                    return [
                        'success' => false,
                        'error' => 'Buffer retry (without firstComment) also failed: ' . ($createPostRetry['message'] ?? 'Unknown'),
                        'response' => $bodyRetry,
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'Buffer createPost failed: ' . $errMsg,
                    'response' => $body,
                ];
            }

            // Success
            $postId = $createPost['post']['id'] ?? null;
            if (!$postId) {
                return [
                    'success' => false,
                    'error' => 'Buffer did not return post ID',
                    'response' => $body,
                ];
            }

            $status = $createPost['post']['status'] ?? 'scheduled';
            $channelSvc = $metadata['channel_service'] ?? 'unknown';

            // Build info message — notification mode needs special instructions
            if ($schedulingType === 'notification') {
                $info = "Post scheduled in Buffer with **Notify Me** mode (status: {$status}). "
                    . "At scheduled time, Buffer will send a push notification to your mobile.\n\n"
                    . "📱 FLOW (important — does NOT open TikTok/IG directly):\n"
                    . "1. Notification arrives on your phone\n"
                    . "2. Tap notification → Buffer mobile app opens (NOT TikTok/IG)\n"
                    . "3. In Buffer app, tap 'Finish in {$channelSvc}' / 'Finish your post'\n"
                    . "4. TikTok/IG app opens with media + caption pre-loaded\n"
                    . "5. Add music, stickers, effects, sounds → tap Publish\n\n"
                    . "This 2-step flow (Buffer app → TikTok/IG app) is required because "
                    . "Buffer API cannot attach music/stickers directly. The handoff to "
                    . "TikTok/IG native app enables full creative control.";

                // Warn if no active mobile device registered
                if ($hasActiveDevice === false) {
                    $info .= "\n\n⚠️ WARNING: No active mobile device detected for this Buffer account. "
                        . "You will NOT receive the push notification! "
                        . "To fix:\n"
                        . "1. Install Buffer mobile app (iOS App Store / Google Play)\n"
                        . "2. Login with the SAME email used to connect this Buffer account\n"
                        . "3. Enable push notifications for Buffer app in phone settings\n"
                        . "4. ALSO install TikTok/IG app (needed for step 4 above)\n"
                        . "After login, device will be registered automatically.";
                } else {
                    $info .= "\n\n✓ Mobile device detected. Make sure TikTok/IG app is also "
                        . "installed (needed for the handoff in step 4).";
                }
            } else {
                $info = "Post created in Buffer (status: {$status}, mode: {$mode}). "
                    . "Buffer will auto-publish to {$channelSvc} at the scheduled time.";
            }

            return [
                'success' => true,
                'external_id' => $postId,
                'info' => $info,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build GraphQL asset input array from media URLs.
     * Each asset is one of: { image: { url } } | { video: { url } } | { document: {...} }
     *
     * Note: Buffer requires PUBLIC HTTPS URLs (no signed URLs). Media must
     * stay reachable until post publish time (which for scheduled posts can
     * be hours or days later).
     */
    /**
     * Build per-channel metadata GraphQL input.
     *
     * Buffer supports per-service metadata for:
     *   - Pinterest: boardServiceId (required for pins)
     *   - Instagram: postType (post, reel, story)
     *   - Twitter/Threads/Mastodon/Bluesky: thread replies
     *   - Facebook/LinkedIn/Instagram: firstComment
     *
     * @return string GraphQL fragment (without outer braces), or empty string
     */
    private function buildMetadataGraphQL(string $channelService, array $tags, ?string $firstComment, array $accountMetadata, string $schedulingType = 'automatic', array $overrides = [], string $accountId = ''): string
    {
        $parts = [];

        // First comment — supported by FB, IG, LinkedIn
        // NOTE: For Instagram, firstComment is merged into the Instagram metadata
        // block below (together with type + shouldShareToFeed) — don't add it
        // separately here or GraphQL will see two instagram blocks.
        if ($firstComment && in_array($channelService, ['facebook', 'linkedin'])) {
            $parts[] = "{$channelService}: { firstComment: " . json_encode($firstComment) . " }";
        }

        // Pinterest board + title + destination link
        // Buffer GraphQL schema (verified via introspection 2026-07-11):
        //   PinterestPostMetadataInput {
        //     boardServiceId: String  (required on create)
        //     title: String           (max 100 chars)
        //     url: String             (destination link — IS supported)
        //   }
        if ($channelService === 'pinterest') {
            $pinFields = [];
            if (!empty($accountMetadata['pinterest_board_id'])) {
                $pinFields[] = 'boardServiceId: ' . json_encode($accountMetadata['pinterest_board_id']);
            }
            if (!empty($accountMetadata['pinterest_title'])) {
                $pinFields[] = 'title: ' . json_encode(mb_substr($accountMetadata['pinterest_title'], 0, 100));
            }
            // Destination link — supported by Buffer PinterestPostMetadataInput.url
            // (previously thought unsupported — verified via GraphQL introspection)
            if (!empty($accountMetadata['pinterest_link'])) {
                $pinFields[] = 'url: ' . json_encode($accountMetadata['pinterest_link']);
            }
            if (!empty($pinFields)) {
                $parts[] = 'pinterest: { ' . implode(', ', $pinFields) . ' }';
            }
        }

        // Instagram post type (post/reel/story) — from channel metadata or per-post override
        // Buffer GraphQL schema (verified via introspection):
        //   InstagramPostMetadataInput {
        //     type: PostType!              (REQUIRED — enum: post, reel, story, short, carousel, etc.)
        //     shouldShareToFeed: Boolean!  (REQUIRED — whether to also post to IG feed)
        //     firstComment: String         (optional — BUT requires Buffer PAID plan)
        //     link: String                 (optional)
        //     stickerFields: InstagramStickerFieldsInput  (optional — for notification publishing)
        //     geolocation: InstagramGeolocationInput     (optional)
        //     isAiGenerated: Boolean       (optional)
        //   }
        //
        // InstagramStickerFieldsInput (verified via introspection + live API test):
        //   text: String     — Text for the Story or Reel
        //   music: String    — Placeholder text for the post's music
        //                      (e.g. "Add viral sound: Sephelia - Bliss")
        //   products: String — Placeholder text for the post's linked products
        //                      (e.g. "Tag product: Mie Jebew Special")
        //   topics: String   — Placeholder text for the post's topics (Reels only)
        //   other: String    — Additional field for any other post content
        //
        // These are REMINDER texts shown in Buffer mobile app when the notification
        // fires — user reads them before editing in IG native app.
        // Only works with schedulingType: notification (Notify Me mode).
        //
        // PostType enum values are LOWERCASE: post, reel, story (NOT POST/REEL/STORY)
        if ($channelService === 'instagram') {
            $postType = $accountMetadata['instagram_post_type'] ?? null;
            if ($postType) {
                $igParts = ["type: {$postType}"];  // lowercase — enum values
                $igParts[] = "shouldShareToFeed: true";  // REQUIRED field — share to IG feed
                // firstComment is optional but requires Buffer paid plan.
                // Only include if explicitly set — free plan users will get error if included.
                // We include it and handle the error gracefully in the response handler.
                if (!empty($firstComment)) {
                    $igParts[] = "firstComment: " . json_encode($firstComment);
                }

                // Sticker fields (music, products, topics reminders) — only when
                // schedulingType is notification (Notify Me mode). These are reminder
                // texts that Buffer shows in the mobile app notification, so the user
                // knows what music/products to add when editing in IG native app.
                // Read from account_overrides[accountId].sticker_fields
                $stickerFields = $overrides[$accountId]['sticker_fields'] ?? null;
                if ($schedulingType === 'notification' && $stickerFields) {
                    $stickerParts = [];
                    if (!empty($stickerFields['music'])) {
                        $stickerParts[] = 'music: ' . json_encode(mb_substr($stickerFields['music'], 0, 200));
                    }
                    if (!empty($stickerFields['products'])) {
                        $stickerParts[] = 'products: ' . json_encode(mb_substr($stickerFields['products'], 0, 200));
                    }
                    if (!empty($stickerFields['topics'])) {
                        $stickerParts[] = 'topics: ' . json_encode(mb_substr($stickerFields['topics'], 0, 200));
                    }
                    if (!empty($stickerFields['text'])) {
                        $stickerParts[] = 'text: ' . json_encode(mb_substr($stickerFields['text'], 0, 500));
                    }
                    if (!empty($stickerFields['other'])) {
                        $stickerParts[] = 'other: ' . json_encode(mb_substr($stickerFields['other'], 0, 500));
                    }
                    if (!empty($stickerParts)) {
                        $igParts[] = 'stickerFields: { ' . implode(', ', $stickerParts) . ' }';
                    }
                }

                $parts[] = "instagram: { " . implode(', ', $igParts) . " }";
            }
        }

        // Twitter threads — if firstComment provided, treat as thread reply
        if ($firstComment && $channelService === 'twitter') {
            $parts[] = "twitter: { thread: [{ text: " . json_encode($firstComment) . " }] }";
        }

        return implode(', ', $parts);
    }

    private function buildAssetsGraphQL(array $mediaUrls): array
    {
        $assets = [];
        foreach ($mediaUrls as $url) {
            $type = MediaType::fromUrl($url);
            $urlJson = json_encode($url);

            if ($type === 'image') {
                $assets[] = "{ image: { url: {$urlJson} } }";
            } elseif ($type === 'video') {
                $assets[] = "{ video: { url: {$urlJson} } }";
            }
            // Skip documents — Buffer requires title + thumbnail which we don't have
        }
        return $assets;
    }

    /**
     * Convert video URLs to animated GIF URLs (for Pinterest via Buffer).
     *
     * Buffer's public API does NOT support video publishing (confirmed via
     * official docs: "video is not supported via public API"), but it DOES
     * support GIF (Buffer docs: "Please use image, gif, or document types").
     *
     * When a user attaches a video to publish to Pinterest via Buffer, we
     * convert the first 3 seconds of the video to an animated GIF using
     * ffmpeg. Pinterest displays animated GIFs as playable animated pins —
     * preserving the motion context of the original video (unlike static
     * JPEG thumbnails which lose all motion information).
     *
     * Live test confirmed (2026-07-11):
     *   - 3s, 360px, 8fps, 128 colors, 1.2MB GIF → ✅ SUCCESS on Pinterest
     *   - Pinterest served as animated pin at i.pinimg.com/originals/.../*.gif
     *
     * Behavior:
     *   - Image URLs: pass through unchanged (including existing .gif files)
     *   - Video URLs: convert to animated GIF (3s, 360px, 8fps, 128 colors)
     *   - If GIF generation fails: fall back to static JPEG thumbnail
     *   - If thumbnail also fails: skip media (Buffer will reject, but at
     *     least we don't send a video URL that definitely won't work)
     *
     * @param array $mediaUrls  Original media URLs (mix of images/videos)
     * @return array            Media URLs with videos replaced by GIFs
     */
    private function convertVideosToGifs(array $mediaUrls): array
    {
        $result = [];
        foreach ($mediaUrls as $url) {
            $type = MediaType::fromUrl($url);
            if ($type !== 'video') {
                $result[] = $url;
                continue;
            }

            // Try GIF first (preserves motion context)
            $gifUrl = $this->generateVideoGif($url);
            if ($gifUrl) {
                Log::info('Pinterest: generated animated GIF from video', [
                    'video_url' => $url,
                    'gif_url' => $gifUrl,
                ]);
                $result[] = $gifUrl;
                continue;
            }

            // Fallback: static JPEG thumbnail (loses motion but at least publishes)
            Log::warning('Pinterest: GIF generation failed, falling back to JPEG thumbnail', [
                'video_url' => $url,
            ]);
            $thumbUrl = $this->generateVideoThumbnail($url);
            if ($thumbUrl) {
                $result[] = $thumbUrl;
            } else {
                Log::warning('Pinterest: thumbnail also failed, skipping media', [
                    'video_url' => $url,
                ]);
                // Skip — don't add the video URL because Buffer will reject it
            }
        }
        return $result;
    }

    /**
     * Convert video URLs to thumbnail image URLs (for Pinterest via Buffer).
     *
     * DEPRECATED: use convertVideosToGifs() instead — GIF preserves motion
     * context, JPEG thumbnail does not. This method is kept as fallback
     * when GIF generation fails.
     *
     * @param array $mediaUrls  Original media URLs (mix of images/videos)
     * @return array            Media URLs with videos replaced by thumbnails
     */
    private function convertVideosToThumbnails(array $mediaUrls): array
    {
        $result = [];
        foreach ($mediaUrls as $url) {
            $type = MediaType::fromUrl($url);
            if ($type !== 'video') {
                $result[] = $url;
                continue;
            }

            $thumbUrl = $this->generateVideoThumbnail($url);
            if ($thumbUrl) {
                Log::info('Pinterest: generated thumbnail from video', [
                    'video_url' => $url,
                    'thumbnail_url' => $thumbUrl,
                ]);
                $result[] = $thumbUrl;
            } else {
                Log::warning('Pinterest: failed to generate video thumbnail, skipping media', [
                    'video_url' => $url,
                ]);
                // Skip — don't add the video URL because Buffer will reject it
            }
        }
        return $result;
    }

    /**
     * Generate an animated GIF from a video URL using ffmpeg.
     *
     * Strategy:
     *   1. Resolve to local file path (or download to temp file)
     *   2. Take first 3 seconds of video (captures motion context)
     *   3. Scale to 360px wide, maintain aspect ratio
     *   4. Sample at 8 fps (smooth enough for GIF, keeps size down)
     *   5. Generate optimized 128-color palette (palettegen)
     *   6. Apply palette with bayer dithering (good for photographic content)
     *   7. Save as .gif in storage/app/public/compressed/
     *   8. Return public URL
     *
     * Output: ~1-2MB animated GIF (well under Pinterest's processing limit)
     *
     * Verified working via live API test (2026-07-11):
     *   - Pinterest accepted 1.2MB GIF, served as animated pin
     *   - Pin URL: https://www.pinterest.com/pin/1146166174349429951
     *   - GIF URL: https://i.pinimg.com/originals/df/7c/2b/df7c2b93f4d37483d8a8b9450ec0a9f7.gif
     *
     * @param string $videoUrl  URL or local path of video
     * @return string|null      Public URL of GIF, or null on failure
     */
    private function generateVideoGif(string $videoUrl): ?string
    {
        $ffmpeg = \App\Services\MediaCompressionService::findFfmpegPublic()
            ?? $this->findFfmpegLocal();
        if (!$ffmpeg) {
            Log::warning('ffmpeg not found, cannot generate video GIF');
            return null;
        }

        // Try to resolve to local file path (avoid network download)
        $localPath = $this->resolveLocalVideoPath($videoUrl);
        $tempInput = null;

        if (!$localPath) {
            // Download to temp file
            $tempInput = tempnam(sys_get_temp_dir(), 'vid_in_');
            try {
                $response = Http::get($videoUrl);
                if (!$response->ok()) {
                    Log::warning('Failed to download video for GIF', ['url' => $videoUrl]);
                    @unlink($tempInput);
                    return null;
                }
                file_put_contents($tempInput, $response->body());
                $localPath = $tempInput;
            } catch (\Exception $e) {
                Log::warning('Exception downloading video for GIF: ' . $e->getMessage());
                @unlink($tempInput);
                return null;
            }
        }

        // Ensure compressed directory exists
        \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('compressed');

        $filename = 'gif_' . time() . '_' . uniqid() . '.gif';
        $outputPath = storage_path('app/public/compressed/' . $filename);

        // Generate animated GIF using two-pass palette method:
        //   1. palettegen — analyze video, generate optimal 128-color palette
        //   2. paletteuse — apply palette with bayer dithering to final GIF
        //
        // Filter graph explanation:
        //   fps=8                    — sample 8 frames per second
        //   scale=360:-1:flags=lanczos — scale to 360px wide, auto height, lanczos resampling
        //   split[s0][s1]            — split into 2 streams (one for palette, one for output)
        //   [s0]palettegen=max_colors=128[p]  — generate 128-color palette from stream 0
        //   [s1][p]paletteuse=dither=bayer:bayer_scale=5  — apply palette to stream 1
        //
        // -t 3 limits output to 3 seconds (after scaling, before split)
        // -loop 0 makes GIF loop infinitely
        // -gifflags -offsetting disables per-frame offset optimization (smaller files)
        $filter = "fps=8,scale=360:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=128[p];[s1][p]paletteuse=dither=bayer:bayer_scale=5";
        $cmd = sprintf(
            '%s -t 3 -i %s -lavfi "%s" -loop 0 -gifflags -offsetting -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($localPath),
            $filter,
            escapeshellarg($outputPath)
        );

        $output = shell_exec($cmd);

        // Cleanup temp input if we downloaded it
        if ($tempInput) {
            @unlink($tempInput);
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::warning('ffmpeg GIF generation failed', [
                'video_url' => $videoUrl,
                'ffmpeg_output' => substr($output ?? '', -500),
            ]);
            return null;
        }

        $gifSize = filesize($outputPath);

        // If GIF is too large (>4MB), try regenerating with more aggressive settings
        // (Pinterest processing fails on very large GIFs — tested 5.9MB failed)
        if ($gifSize > 4 * 1024 * 1024) {
            Log::info('GIF too large, regenerating with smaller dimensions', [
                'original_size_mb' => round($gifSize / 1024 / 1024, 2),
            ]);
            @unlink($outputPath);

            // Try with 240px width and 6 fps
            $filterSmaller = "fps=6,scale=240:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=96[p];[s1][p]paletteuse=dither=bayer:bayer_scale=5";
            $cmdSmaller = sprintf(
                '%s -t 3 -i %s -lavfi "%s" -loop 0 -gifflags -offsetting -y %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localPath),
                $filterSmaller,
                escapeshellarg($outputPath)
            );
            shell_exec($cmdSmaller);

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                Log::warning('ffmpeg GIF regeneration (smaller) also failed');
                return null;
            }

            $gifSize = filesize($outputPath);
            Log::info('GIF regenerated at smaller size', [
                'new_size_mb' => round($gifSize / 1024 / 1024, 2),
            ]);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url('compressed/' . $filename);
    }

    /**
     * Generate a thumbnail JPEG from a video URL using ffmpeg.
     *
     * Strategy:
     *   1. Resolve to local file path (or download to temp file)
     *   2. Extract frame at 00:00:01 (1 second in) — avoids black intro frames
     *   3. Scale to max 1280px wide, maintain aspect ratio
     *   4. Save as JPEG quality 90 in storage/app/public/compressed/
     *   5. Return public URL
     *
     * @param string $videoUrl  URL or local path of video
     * @return string|null      Public URL of thumbnail, or null on failure
     */
    private function generateVideoThumbnail(string $videoUrl): ?string
    {
        $ffmpeg = \App\Services\MediaCompressionService::findFfmpegPublic()
            ?? $this->findFfmpegLocal();
        if (!$ffmpeg) {
            Log::warning('ffmpeg not found, cannot generate video thumbnail');
            return null;
        }

        // Try to resolve to local file path (avoid network download)
        $localPath = $this->resolveLocalVideoPath($videoUrl);
        $tempInput = null;

        if (!$localPath) {
            // Download to temp file
            $tempInput = tempnam(sys_get_temp_dir(), 'vid_in_');
            try {
                $response = Http::get($videoUrl);
                if (!$response->ok()) {
                    Log::warning('Failed to download video for thumbnail', ['url' => $videoUrl]);
                    @unlink($tempInput);
                    return null;
                }
                file_put_contents($tempInput, $response->body());
                $localPath = $tempInput;
            } catch (\Exception $e) {
                Log::warning('Exception downloading video for thumbnail: ' . $e->getMessage());
                @unlink($tempInput);
                return null;
            }
        }

        // Ensure compressed directory exists
        \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('compressed');

        $filename = 'thumb_' . time() . '_' . uniqid() . '.jpg';
        $outputPath = storage_path('app/public/compressed/' . $filename);

        // Extract frame at 1 second, scale to max 1280 wide maintaining aspect ratio
        // -ss before -i = fast seek (keyframe-based, near-instant)
        // scale='min(1280,iw)':-2 = cap width at 1280, auto-calc height (even number)
        $cmd = sprintf(
            '%s -ss 00:00:01 -i %s -frames:v 1 -vf "scale=\'min(1280,iw)\':-2" -q:v 2 -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($localPath),
            escapeshellarg($outputPath)
        );

        $output = shell_exec($cmd);

        // Cleanup temp input if we downloaded it
        if ($tempInput) {
            @unlink($tempInput);
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            // Try fallback: extract first frame (some short videos < 1s)
            $cmdFallback = sprintf(
                '%s -i %s -frames:v 1 -vf "scale=\'min(1280,iw)\':-2" -q:v 2 -y %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localPath),
                escapeshellarg($outputPath)
            );
            shell_exec($cmdFallback);

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                Log::warning('ffmpeg thumbnail generation failed', [
                    'video_url' => $videoUrl,
                    'ffmpeg_output' => substr($output ?? '', -500),
                ]);
                return null;
            }
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url('compressed/' . $filename);
    }

    /**
     * Resolve public storage URL to local file path (for video files).
     * Same logic as MediaCompressionService::resolveLocalPath but exposed here
     * to avoid tight coupling.
     */
    private function resolveLocalVideoPath(string $url): ?string
    {
        $appUrl = config('app.url');
        if (!str_starts_with($url, $appUrl)) {
            return null;
        }

        $storagePrefix = rtrim($appUrl, '/') . '/storage/';
        if (!str_starts_with($url, $storagePrefix)) {
            return null;
        }

        $relativePath = substr($url, strlen($storagePrefix));
        $localPath = storage_path('app/public/' . $relativePath);

        return file_exists($localPath) ? $localPath : null;
    }

    /**
     * Find ffmpeg binary (fallback if MediaCompressionService doesn't expose it).
     */
    private function findFfmpegLocal(): ?string
    {
        $candidates = ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
        foreach ($candidates as $c) {
            $result = shell_exec("which $c 2>/dev/null");
            if ($result) {
                return trim($result);
            }
            if (file_exists($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Ensure the access token is fresh. Buffer tokens expire in 1 hour.
     * Refresh tokens are SINGLE-USE — must update the stored refresh_token
     * on every refresh.
     *
     * Because Buffer tokens are user-wide (not per-channel), we update ALL
     * of the user's Buffer accounts in one shot to keep them in sync and
     * prevent race conditions where two channels try to refresh at once.
     */
    private function ensureFreshAccessToken(array $account): ?string
    {
        $accessToken = $account['access_token'];
        $refreshToken = $account['refresh_token'] ?? null;
        $expiresAt = $account['expires_at'] ?? null;

        // If no expires_at, assume token is still valid (legacy accounts)
        if (!$expiresAt) {
            return $accessToken;
        }

        // Parse expires_at
        if (is_string($expiresAt)) {
            try {
                $expiresAt = \Carbon\Carbon::parse($expiresAt);
            } catch (Exception $e) {
                return $accessToken;
            }
        }

        // If token still has > 5 min left, use as-is
        if ($expiresAt->isFuture() && $expiresAt->diffInMinutes(now()) > 5) {
            return $accessToken;
        }

        // Need to refresh
        if (!$refreshToken) {
            Log::error('Buffer token expired but no refresh_token available', [
                'account_id' => $account['id'] ?? null,
            ]);
            return null;
        }

        try {
            $clientId = config('services.buffer.client_id');
            $tokenUrl = config('services.buffer.token_url');

            // Buffer public client uses PKCE — refresh request: client_id + refresh_token only
            // (no client_secret for public clients)
            $response = Http::asForm()->post($tokenUrl, [
                'client_id' => $clientId,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

            if (!$response->ok()) {
                Log::error('Buffer token refresh failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $newAccessToken = $data['access_token'] ?? null;
            $newRefreshToken = $data['refresh_token'] ?? null;
            $newExpiresIn = $data['expires_in'] ?? 3600;

            if (!$newAccessToken || !$newRefreshToken) {
                Log::error('Buffer refresh response missing tokens', ['response' => $data]);
                return null;
            }

            // Update ALL of the user's Buffer accounts with the new tokens.
            // Buffer tokens are user-wide (not per-channel), so all rows for
            // the same user share the same access_token + refresh_token.
            if (!empty($account['user_id'])) {
                SocialAccount::where('user_id', $account['user_id'])
                    ->where('provider', 'buffer')
                    ->update([
                        'access_token' => $newAccessToken,
                        'refresh_token' => $newRefreshToken,
                        'expires_at' => now()->addSeconds($newExpiresIn),
                    ]);
                Log::info('Buffer access token refreshed (all user channels updated)', [
                    'user_id' => $account['user_id'],
                    'expires_in' => $newExpiresIn,
                ]);
            }

            return $newAccessToken;
        } catch (Exception $e) {
            Log::error('Buffer token refresh exception: ' . $e->getMessage());
            return null;
        }
    }
}
