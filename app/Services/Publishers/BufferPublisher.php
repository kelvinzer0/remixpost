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

            // Build assets array from media URLs
            $assetsGraphQL = $this->buildAssetsGraphQL($mediaUrls);

            // Build per-channel metadata (tags, first comment, Pinterest board, IG post type, etc.)
            $channelService = $metadata['channel_service'] ?? 'unknown';
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
                return [
                    'success' => false,
                    'error' => 'Buffer createPost failed: ' . $createPost['message'],
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
        if ($firstComment && in_array($channelService, ['facebook', 'instagram', 'linkedin'])) {
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
        // Stored as 'instagram_post_type' in overrides, but Buffer GraphQL expects 'type' field
        // inside InstagramPostMetadataInput.
        if ($channelService === 'instagram') {
            $postType = $accountMetadata['instagram_post_type'] ?? null;
            if ($postType) {
                $igParts = ["type: {$postType}"];
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
