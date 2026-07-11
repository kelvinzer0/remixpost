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

            // Pinterest via Buffer only accepts IMAGE assets (no video pins supported).
            // If user attaches a video for Pinterest, auto-generate a thumbnail from
            // the video using ffmpeg and send it as the image asset. Pinterest will
            // display the thumbnail as a static pin with the post caption.
            // (Pinterest's own API supports "Idea Pins" with video, but Buffer's
            // GraphQL PinterestPostMetadataInput only accepts image assets.)
            if ($channelService === 'pinterest') {
                $mediaUrls = $this->convertVideosToThumbnails($mediaUrls);
            }

            // Build assets array from media URLs
            $assetsGraphQL = $this->buildAssetsGraphQL($mediaUrls);

            $metadataGraphQL = $this->buildMetadataGraphQL($channelService, $tags, $firstComment, $metadata);

            // Build mutation input — note: GraphQL input fields use camelCase
            $inputLines = [
                'text: ' . json_encode($content),
                'channelId: ' . json_encode($channelId),
                'schedulingType: automatic',
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
                        'schedulingType: automatic',
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
            return [
                'success' => true,
                'external_id' => $postId,
                'info' => "Post created in Buffer (status: {$status}, mode: {$mode}). Buffer will publish to {$metadata['channel_service']} at the scheduled time.",
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
    private function buildMetadataGraphQL(string $channelService, array $tags, ?string $firstComment, array $accountMetadata): string
    {
        $parts = [];

        // First comment — supported by FB, IG, LinkedIn
        // NOTE: For Instagram, firstComment is merged into the Instagram metadata
        // block below (together with type + shouldShareToFeed) — don't add it
        // separately here or GraphQL will see two instagram blocks.
        if ($firstComment && in_array($channelService, ['facebook', 'linkedin'])) {
            $parts[] = "{$channelService}: { firstComment: " . json_encode($firstComment) . " }";
        }

        // Pinterest board + title (destination link not supported by Buffer PinterestPostMetadataInput)
        if ($channelService === 'pinterest') {
            $pinFields = [];
            if (!empty($accountMetadata['pinterest_board_id'])) {
                $pinFields[] = 'boardServiceId: ' . json_encode($accountMetadata['pinterest_board_id']);
            }
            if (!empty($accountMetadata['pinterest_title'])) {
                $pinFields[] = 'title: ' . json_encode(mb_substr($accountMetadata['pinterest_title'], 0, 100));
            }
            // Note: 'link' field is NOT supported by Buffer's PinterestPostMetadataInput.
            // Destination link is not available via Buffer API for Pinterest.
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
        //     ...
        //   }
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
     * Convert video URLs to thumbnail image URLs (for Pinterest).
     *
     * Buffer's Pinterest integration only accepts image assets — it does NOT
     * support video pins. When a user attaches a video to publish to Pinterest,
     * we extract a frame from the video using ffmpeg and use that as the pin
     * image. The video itself cannot be posted to Pinterest via Buffer.
     *
     * Behavior:
     *   - Image URLs: pass through unchanged
     *   - Video URLs: generate thumbnail (1280px wide JPEG, frame at ~1s mark),
     *     save to storage/app/public/compressed/, return public URL
     *   - If thumbnail generation fails: log warning and SKIP that media
     *     (sending nothing is better than sending a video URL that Buffer will
     *      reject with "Pinterest posts require at least one image")
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
