<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use App\Services\VideoAnalyzer;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Publisher — uploads videos to YouTube via Data API v3.
 *
 * Authentication: Google OAuth 2.0 (user context). Scope: youtube.upload.
 * Access tokens expire after 1 hour, so this publisher automatically refreshes
 * them using the stored refresh_token + Google OAuth endpoint.
 *
 * API endpoints:
 *   - POST https://oauth2.googleapis.com/token (refresh access token)
 *   - POST https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable
 *     (initiate resumable upload session, returns upload URL)
 *   - PUT {upload_url} (upload video data — streamed, not loaded into memory)
 *   - GET  https://www.googleapis.com/youtube/v3/channels?part=contentDetails&mine=true
 *     (used to validate the access token)
 *
 * Upload modes (stored in social_accounts.metadata.upload_mode):
 *   - 'video': regular upload. CategoryId 22 (People & Blogs).
 *   - 'short': YouTube Shorts. Auto-appends '#shorts' hashtag to description,
 *              sets tags ['shorts'], and uses categoryId 24 (Entertainment).
 *              Note: YouTube auto-detects Shorts based on aspect ratio (9:16)
 *              + duration (≤60s), but explicit #shorts hashtag improves visibility.
 *
 * Reference: https://developers.google.com/youtube/v3/docs/videos/insert
 *
 * @license Apache-2.0 (implemented from official API docs)
 */
class YouTubePublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 600, // 10 min for large video uploads
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // YouTube requires a video file
            $videoUrl = null;
            foreach ($mediaUrls as $url) {
                if (MediaType::fromUrl($url) === 'video') {
                    $videoUrl = $url;
                    break;
                }
            }

            if (!$videoUrl) {
                return [
                    'success' => false,
                    'error' => 'YouTube requires at least one video file in the post.',
                ];
            }

            // Read upload mode from metadata (defaults to 'video' for backward compat)
            $metadata = is_string($account['metadata'] ?? null)
                ? json_decode($account['metadata'], true) ?? []
                : ($account['metadata'] ?? []);
            $uploadMode = $metadata['upload_mode'] ?? 'video';

            // Refresh access token if expired (Google tokens last ~1 hour)
            $accessToken = $this->ensureFreshAccessToken($account);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'YouTube access token expired and could not be refreshed. Disconnect and re-connect the YouTube channel.',
                ];
            }

            // Build title + description based on upload mode
            $title = mb_substr($content, 0, 100);
            $description = mb_substr($content, 0, 4900);

            // Tags from post + mode-specific tags
            $postTags = $post['tags'] ?? [];
            $tags = $postTags;
            $categoryId = '22'; // People & Blogs (default)

            if ($uploadMode === 'short') {
                // Append #shorts hashtag for YouTube Shorts visibility
                if (!str_contains($description, '#shorts')) {
                    $description = rtrim($description) . "\n\n#shorts";
                }
                $tags = array_merge(['shorts', 'short', 'youtube shorts'], $postTags);
                $categoryId = '24'; // Entertainment (commonly used for Shorts)

                // Pre-upload validation: check if video meets Shorts criteria.
                // YouTube auto-classifies Shorts based on:
                //   1. Aspect ratio 9:16 (vertical) or 1:1 (square)
                //   2. Duration ≤ 60 seconds
                // #shorts hashtag and categoryId do NOT force YouTube to treat
                // a horizontal or long video as a Short.
                $analysis = VideoAnalyzer::analyze($videoUrl);
                $shortsCheck = VideoAnalyzer::meetsShortsCriteria($analysis);

                if ($shortsCheck['meets_criteria'] === false) {
                    // Video doesn't meet Shorts criteria — log warning but still
                    // upload (user may want to proceed anyway). The warning will
                    // appear in the post result so the user knows.
                    $warning = 'YouTube Shorts criteria not met: ' . implode('; ', $shortsCheck['reasons'])
                        . '. Video will be uploaded but YouTube may classify it as a regular video, not a Short.';
                    Log::warning('YouTube Shorts validation failed', [
                        'video_url' => $videoUrl,
                        'reasons' => $shortsCheck['reasons'],
                    ]);
                } elseif ($shortsCheck['meets_criteria'] === true) {
                    $info = sprintf(
                        'Video meets Shorts criteria: %dx%d (%s), %.1fs duration',
                        $analysis['width'],
                        $analysis['height'],
                        $analysis['aspect_ratio'],
                        $analysis['duration']
                    );
                    Log::info('YouTube Shorts validation passed', ['info' => $info]);
                }
            }

            // Step 1: Initiate resumable upload
            $metadataBody = [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                    'tags' => $tags,
                    'categoryId' => $categoryId,
                ],
                'status' => [
                    'privacyStatus' => 'public',
                    'selfDeclaredMadeForKids' => false,
                    'embeddable' => true,
                    // YouTube Shorts work best when license is 'youtube' (standard)
                ],
            ];

            $initResponse = $this->httpClient->post(
                'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                        'X-Upload-Content-Type' => 'video/*',
                    ],
                    'json' => $metadataBody,
                ]
            );

            $uploadUrl = $initResponse->getHeaderLine('Location');
            if (empty($uploadUrl)) {
                return ['success' => false, 'error' => 'YouTube did not return upload URL'];
            }

            // Step 2: Stream video file directly to YouTube (avoid loading into memory)
            // This prevents OOM on large video files (>50MB).
            $videoResponse = $this->httpClient->get($videoUrl, ['stream' => true]);
            $videoStream = $videoResponse->getBody();
            $videoMime = $videoResponse->getHeaderLine('Content-Type') ?: 'video/mp4';
            $videoSize = (int) ($videoResponse->getHeaderLine('Content-Length') ?: 0);

            $uploadHeaders = [
                'Content-Type' => $videoMime,
            ];
            if ($videoSize > 0) {
                $uploadHeaders['Content-Length'] = (string) $videoSize;
            }

            $uploadResponse = $this->httpClient->put($uploadUrl, [
                'headers' => $uploadHeaders,
                'body' => $videoStream, // Guzzle will stream this
            ]);

            $body = json_decode($uploadResponse->getBody()->getContents(), true);

            if (!isset($body['id'])) {
                $err = $body['error']['message'] ?? 'YouTube did not return video ID';
                return ['success' => false, 'error' => $err, 'response' => $body];
            }

            $videoUrl2 = $uploadMode === 'short'
                ? 'https://www.youtube.com/shorts/' . $body['id']
                : 'https://www.youtube.com/watch?v=' . $body['id'];

            $result = [
                'success' => true,
                'external_id' => $body['id'],
                'url' => $videoUrl2,
            ];

            // Attach warning/info for Shorts mode so the user understands
            // why a video may not appear in the Shorts feed immediately.
            if ($uploadMode === 'short') {
                if (isset($shortsCheck)) {
                    if ($shortsCheck['meets_criteria'] === false) {
                        $result['warning'] = $warning ?? 'Video may not qualify as a Short.';
                    } elseif ($shortsCheck['meets_criteria'] === true) {
                        $result['info'] = 'Video meets Shorts criteria. '
                            . 'YouTube takes 24-48h to process and classify it as a Short. '
                            . 'Until then it appears as a regular video. '
                            . 'Visit https://www.youtube.com/@yourchannel/shorts to verify.';
                    } else {
                        $result['info'] = 'Could not pre-validate video for Shorts criteria (ffprobe not installed). '
                            . 'YouTube will auto-classify based on aspect ratio (9:16 vertical) and duration (≤60s).';
                    }
                }
            }

            return $result;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $body = $resp ? json_decode($resp->getBody()->getContents(), true) : null;
            $status = $resp ? $resp->getStatusCode() : null;
            $err = $body['error']['message'] ?? $e->getMessage();

            // Map common errors to actionable messages
            if ($status === 401) {
                $err .= ' — YouTube access token is invalid or expired. Try refreshing the connection.';
            } elseif ($status === 403 && stripos($err, 'quota') !== false) {
                $err .= ' — Daily YouTube upload quota exceeded. Try again tomorrow.';
            } elseif ($status === 400 && stripos($err, 'invalidSnippet') !== false) {
                $err .= ' — Video metadata is invalid. Check title length (max 100 chars) and description.';
            }

            return [
                'success' => false,
                'error' => "YouTube API {$status} error: {$err}",
                'status' => $status,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh Google OAuth access token if it's expired or about to expire.
     * Google access tokens last ~3600s (1 hour). We refresh 5 min before expiry.
     *
     * Returns the valid access token (refreshed if needed), or null on failure.
     * Also updates the SocialAccount in the database if token was refreshed.
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

        // Parse expires_at (could be string or Carbon instance)
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
            Log::error('YouTube token expired but no refresh_token available', [
                'account_id' => $account['id'] ?? null,
            ]);
            return null;
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.youtube.client_id'),
                'client_secret' => config('services.youtube.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (!$response->ok()) {
                Log::error('YouTube token refresh failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $newAccessToken = $data['access_token'] ?? null;
            $newExpiresIn = $data['expires_in'] ?? 3600;

            if (!$newAccessToken) {
                return null;
            }

            // Update the database with refreshed token
            if (!empty($account['id'])) {
                \App\Models\SocialAccount::where('id', $account['id'])->update([
                    'access_token' => $newAccessToken,
                    'expires_at' => now()->addSeconds($newExpiresIn),
                ]);
                Log::info('YouTube access token refreshed', [
                    'account_id' => $account['id'],
                    'expires_in' => $newExpiresIn,
                ]);
            }

            return $newAccessToken;
        } catch (Exception $e) {
            Log::error('YouTube token refresh exception: ' . $e->getMessage());
            return null;
        }
    }
}
