<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * LinkedIn Publisher — posts to personal feed or Company Pages via REST API.
 *
 * Authentication: OAuth 2.0 with access_token. Scope w_member_social for personal,
 * rw_organization_admin + w_organization_social + r_organization_social for Company Pages.
 *
 * API endpoints used (REST API, NOT legacy v2 Assets API):
 *   - POST https://api.linkedin.com/rest/videos?action=initializeUpload (register video upload)
 *   - POST https://api.linkedin.com/rest/images?action=initializeUpload (register image upload)
 *   - PUT  {uploadUrl} (chunked 2MB PUTs for video, single PUT for image)
 *   - POST https://api.linkedin.com/rest/videos?action=finalizeUpload (finalize video upload)
 *   - GET  https://api.linkedin.com/rest/videos/{urn} (poll video status until AVAILABLE)
 *   - GET  https://api.linkedin.com/rest/images/{urn} (poll image status until AVAILABLE)
 *   - POST https://api.linkedin.com/rest/posts (create the post)
 *
 * Required headers on every REST call:
 *   - Content-Type: application/json
 *   - X-Restli-Protocol-Version: 2.0.0
 *   - LinkedIn-Version: 202601  ← mandatory, omitting causes 403/400
 *   - Authorization: Bearer {token}
 *
 * Reference:
 *   https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/videos-api
 *   https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api
 *   https://learn.microsoft.com/en-us/linkedin/marketing/integrations/community-management/shares/posts-api
 *
 * Pattern adapted from Postiz (github.com/gitroomhq/postiz-app).
 *
 * @license Apache-2.0 (pattern adapted from Postiz open-source project)
 */
class LinkedInPublisher implements PublisherInterface
{
    private Client $httpClient;
    private const LINKEDIN_VERSION = '202601';
    private const CHUNK_SIZE = 2 * 1024 * 1024; // 2 MB — LinkedIn Videos API max chunk size

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 600, // 10 min for large video upload + status polling
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $authorUrn = $account['provider_id']; // urn:li:person:{id} or urn:li:organization:{id}
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // Ensure author URN format
            if (!str_starts_with($authorUrn, 'urn:')) {
                $authorUrn = 'urn:li:person:' . $authorUrn;
            }

            // Categorize media
            $imageUrls = [];
            $videoUrls = [];
            foreach ($mediaUrls as $url) {
                if (MediaType::fromUrl($url) === 'video') {
                    $videoUrls[] = $url;
                } else {
                    $imageUrls[] = $url;
                }
            }

            // Build the post payload base (REST Posts API)
            $postPayload = [
                'author' => $authorUrn,
                'commentary' => $this->fixText($content),
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ];

            // Handle media — prefer images if mixed (LinkedIn only allows one category per post)
            if (!empty($imageUrls)) {
                $mediaIds = [];
                foreach ($imageUrls as $url) {
                    $result = $this->uploadImage($url, $accessToken, $authorUrn);
                    if ($result['success']) {
                        $mediaIds[] = $result['assetUrn'];
                    }
                }
                if (!empty($mediaIds)) {
                    if (count($mediaIds) === 1) {
                        $postPayload['content'] = ['media' => ['id' => $mediaIds[0]]];
                    } else {
                        $postPayload['content'] = [
                            'multiImage' => ['images' => array_map(fn($id) => ['id' => $id], $mediaIds)],
                        ];
                    }
                }
            } elseif (!empty($videoUrls)) {
                // Video post — single video only (LinkedIn limitation)
                $url = $videoUrls[0];
                $result = $this->uploadVideo($url, $accessToken, $authorUrn);
                if (!$result['success']) {
                    return [
                        'success' => false,
                        'error' => 'LinkedIn video upload failed: ' . $result['error'],
                    ];
                }
                $postPayload['content'] = ['media' => ['id' => $result['assetUrn']]];
            }

            // Create the post via REST Posts API
            $response = $this->httpClient->post('https://api.linkedin.com/rest/posts', [
                'headers' => $this->apiHeaders($accessToken),
                'json' => $postPayload,
            ]);

            // REST Posts API returns 201 on success, post ID in x-restli-id response header
            $externalId = $response->getHeaderLine('x-restli-id');
            if (!$externalId) {
                // Fallback: try to parse from body
                $body = json_decode($response->getBody()->getContents(), true);
                $externalId = $body['id'] ?? $body['activity'] ?? null;
            }

            if (!$externalId) {
                $body = json_decode($response->getBody()->getContents(), true);
                return ['success' => false, 'error' => 'LinkedIn did not return post ID', 'response' => $body];
            }

            return [
                'success' => true,
                'external_id' => $externalId,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $body = $resp ? json_decode($resp->getBody()->getContents(), true) : null;
            $status = $resp ? $resp->getStatusCode() : null;
            $err = $body['message'] ?? $e->getMessage();
            return [
                'success' => false,
                'error' => "LinkedIn API {$status} error: {$err}",
                'status' => $status,
                'response' => $body,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload image via REST Images API.
     * Flow: initializeUpload → single PUT to uploadUrl → poll until AVAILABLE.
     */
    private function uploadImage(string $url, string $accessToken, string $authorUrn): array
    {
        try {
            // Download image bytes
            $mediaResponse = $this->httpClient->get($url);
            $mediaData = $mediaResponse->getBody()->getContents();
            $mediaMime = $mediaResponse->getHeaderLine('Content-Type') ?: 'application/octet-stream';

            // Initialize upload
            $initResp = $this->httpClient->post('https://api.linkedin.com/rest/images?action=initializeUpload', [
                'headers' => $this->apiHeaders($accessToken),
                'json' => [
                    'initializeUploadRequest' => [
                        'owner' => $authorUrn,
                    ],
                ],
            ]);
            $initBody = json_decode($initResp->getBody()->getContents(), true);
            $assetUrn = $initBody['value']['image'] ?? null;
            $uploadUrl = $initBody['value']['uploadUrl'] ?? null;

            if (!$assetUrn || !$uploadUrl) {
                return ['success' => false, 'assetUrn' => null, 'error' => 'initializeUpload did not return image URN or upload URL'];
            }

            // Single PUT (images don't need chunking)
            $this->httpClient->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => $mediaMime,
                    'Content-Length' => strlen($mediaData),
                ],
                'body' => $mediaData,
            ]);

            // Poll until AVAILABLE
            $this->waitForMediaReady($assetUrn, $accessToken, 'images');

            return ['success' => true, 'assetUrn' => $assetUrn, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'assetUrn' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload video via REST Videos API.
     *
     * Flow (adapted from Postiz):
     *   1. initializeUpload with owner + fileSizeBytes + uploadCaptions=false + uploadThumbnail=false
     *   2. Chunked PUT: split video into 2MB parts, PUT each to uploadUrl,
     *      capture ETag response header from each chunk
     *   3. finalizeUpload with video URN + uploadedPartIds (the ETags)
     *   4. Poll GET /rest/videos/{urn} until status=AVAILABLE
     */
    private function uploadVideo(string $url, string $accessToken, string $authorUrn): array
    {
        try {
            // Download video bytes
            $mediaResponse = $this->httpClient->get($url);
            $mediaData = $mediaResponse->getBody()->getContents();
            $fileSize = strlen($mediaData);

            if ($fileSize === 0) {
                return ['success' => false, 'assetUrn' => null, 'error' => 'Downloaded video is empty'];
            }

            // Step 1: initializeUpload
            $initResp = $this->httpClient->post('https://api.linkedin.com/rest/videos?action=initializeUpload', [
                'headers' => $this->apiHeaders($accessToken),
                'json' => [
                    'initializeUploadRequest' => [
                        'owner' => $authorUrn,
                        'fileSizeBytes' => $fileSize,
                        'uploadCaptions' => false,
                        'uploadThumbnail' => false,
                    ],
                ],
            ]);
            $initBody = json_decode($initResp->getBody()->getContents(), true);
            $assetUrn = $initBody['value']['video'] ?? null;
            $uploadInstructions = $initBody['value']['uploadInstructions'] ?? [];
            $uploadUrl = $uploadInstructions[0]['uploadUrl'] ?? null;

            if (!$assetUrn || !$uploadUrl) {
                $err = 'initializeUpload did not return video URN or upload instructions';
                Log::error('LinkedIn video initializeUpload failed', ['response' => $initBody]);
                return ['success' => false, 'assetUrn' => null, 'error' => $err];
            }

            // Step 2: Chunked PUT — split video into 2MB parts, capture ETag from each response
            $etags = [];
            $chunks = str_split($mediaData, self::CHUNK_SIZE);
            if ($chunks === false) {
                $chunks = [$mediaData];
            }

            foreach ($chunks as $i => $chunk) {
                $chunkResp = $this->httpClient->put($uploadUrl, [
                    'headers' => [
                        'X-Restli-Protocol-Version' => '2.0.0',
                        'LinkedIn-Version' => self::LINKEDIN_VERSION,
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $chunk,
                ]);
                $etag = $chunkResp->getHeaderLine('ETag');
                if (!$etag) {
                    $etag = $chunkResp->getHeaderLine('etag');
                }
                if ($etag) {
                    $etags[] = $etag;
                }
            }

            // Step 3: finalizeUpload
            $finalizeResp = $this->httpClient->post('https://api.linkedin.com/rest/videos?action=finalizeUpload', [
                'headers' => $this->apiHeaders($accessToken),
                'json' => [
                    'finalizeUploadRequest' => [
                        'video' => $assetUrn,
                        'uploadToken' => '',
                        'uploadedPartIds' => $etags,
                    ],
                ],
            ]);

            // finalizeUpload returns 200 on success; body may be empty
            if ($finalizeResp->getStatusCode() !== 200 && $finalizeResp->getStatusCode() !== 204) {
                $body = json_decode($finalizeResp->getBody()->getContents(), true);
                return ['success' => false, 'assetUrn' => null, 'error' => 'finalizeUpload failed: ' . ($body['message'] ?? 'Unknown')];
            }

            // Step 4: Poll until AVAILABLE
            $this->waitForMediaReady($assetUrn, $accessToken, 'videos');

            return ['success' => true, 'assetUrn' => $assetUrn, 'error' => null];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $body = $resp ? json_decode($resp->getBody()->getContents(), true) : null;
            return ['success' => false, 'assetUrn' => null, 'error' => $body['message'] ?? $e->getMessage()];
        } catch (Exception $e) {
            return ['success' => false, 'assetUrn' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Poll GET /rest/{type}/{urn} until status=AVAILABLE.
     * Max 20 attempts × 30s = 10 min (matches Postiz).
     */
    private function waitForMediaReady(string $urn, string $accessToken, string $type = 'videos'): void
    {
        $encodedUrn = urlencode($urn);
        $maxAttempts = 20;
        $intervalSec = 30;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->get(
                    "https://api.linkedin.com/rest/{$type}/{$encodedUrn}",
                    ['headers' => $this->apiHeaders($accessToken)]
                );
                $body = json_decode($response->getBody()->getContents(), true);
                $status = $body['status'] ?? null;

                if ($status === 'AVAILABLE') {
                    return;
                }
                if ($status === 'PROCESSING_FAILED') {
                    $reason = $body['processingFailureReason'] ?? 'Unknown';
                    throw new Exception("LinkedIn {$type} processing failed: {$reason}");
                }
                // PROCESSING, WAITING_UPLOAD — keep polling
            } catch (Exception $e) {
                // Transient network error — wait and retry
                Log::warning('LinkedIn asset status poll failed (will retry)', [
                    'urn' => $urn,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);
            }
            sleep($intervalSec);
        }

        throw new Exception("Timed out waiting for LinkedIn {$type} to become AVAILABLE (10 min max)");
    }

    /**
     * Standard headers for every LinkedIn REST API call.
     * LinkedIn-Version is mandatory — omitting causes 403/400.
     */
    private function apiHeaders(string $accessToken): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version' => self::LINKEDIN_VERSION,
            'Authorization' => 'Bearer ' . $accessToken,
        ];
    }

    /**
     * Escape LinkedIn markdown metacharacters in commentary (adapted from Postiz).
     * Prevents LinkedIn from interpreting < > # ~ _ | [ ] * ( ) { } @ as formatting.
     */
    private function fixText(string $text): string
    {
        // Escape backslash first
        $text = str_replace('\\', '\\\\', $text);
        // Escape each metacharacter
        $metachars = ['<', '>', '#', '~', '_', '|', '[', ']', '*', '(', ')', '{', '}', '@'];
        foreach ($metachars as $c) {
            $text = str_replace($c, '\\' . $c, $text);
        }
        return $text;
    }
}
