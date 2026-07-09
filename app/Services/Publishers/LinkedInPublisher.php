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
            $authorUrn = $account['provider_id'];
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];
            $firstComment = $post['first_comment'] ?? null;

            // Append tags as #hashtags to commentary
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $content = rtrim($content) . "\n\n" . $tagStr;
            }

            // Ensure author URN format
            if (!str_starts_with($authorUrn, 'urn:')) {
                $authorUrn = 'urn:li:person:' . $authorUrn;
            }

            // Categorize media
            $imageUrls = [];
            $videoUrls = [];
            $documentUrls = [];
            foreach ($mediaUrls as $url) {
                if (MediaType::fromUrl($url) === 'video') {
                    $videoUrls[] = $url;
                } elseif (MediaType::isPdfUrl($url)) {
                    $documentUrls[] = $url;
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

            // Handle media — LinkedIn only allows one media category per post.
            // Priority: documents > videos > images (docs are rarer + most specific)
            if (!empty($documentUrls)) {
                // PDF document post — LinkedIn renders PDFs as a swipeable
                // carousel of pages (1:1 aspect ratio per page).
                // Only ONE document per post (LinkedIn API limitation).
                $url = $documentUrls[0];
                $result = $this->uploadDocument($url, $accessToken, $authorUrn);
                if (!$result['success']) {
                    return [
                        'success' => false,
                        'error' => 'LinkedIn document upload failed: ' . $result['error'],
                    ];
                }
                $postPayload['content'] = ['media' => ['id' => $result['assetUrn']]];
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
            } elseif (!empty($imageUrls)) {
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

            // Post first comment if provided (LinkedIn supports comments on posts)
            $info = null;
            if ($firstComment) {
                $info = $this->postFirstComment($externalId, $firstComment, $accessToken);
            }

            return [
                'success' => true,
                'external_id' => $externalId,
                'info' => $info,
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
    /**
     * Upload PDF document via REST Documents API.
     *
     * LinkedIn supports PDF uploads as "documents" — rendered in feed as a
     * swipeable carousel of pages (each page shown at 1:1 aspect ratio).
     * Limitations:
     *   - PDF only (no DOCX/PPT/etc)
     *   - Max 100MB file size
     *   - Max 300 pages
     *
     * Flow (similar to images):
     *   1. POST /rest/documents?action=initializeUpload { initializeUploadRequest: { owner } }
     *      → returns { value: { document: urn, uploadUrl } }
     *   2. Single PUT to uploadUrl with PDF bytes
     *   3. Poll GET /rest/documents/{urn} until status=AVAILABLE
     */
    private function uploadDocument(string $url, string $accessToken, string $authorUrn): array
    {
        try {
            // Download PDF bytes
            $mediaResponse = $this->httpClient->get($url);
            $mediaData = $mediaResponse->getBody()->getContents();
            $mediaMime = $mediaResponse->getHeaderLine('Content-Type') ?: 'application/pdf';

            // LinkedIn strictly requires application/pdf for documents
            if (!str_contains($mediaMime, 'pdf')) {
                $mediaMime = 'application/pdf';
            }

            // Initialize upload
            $initResp = $this->httpClient->post('https://api.linkedin.com/rest/documents?action=initializeUpload', [
                'headers' => $this->apiHeaders($accessToken),
                'json' => [
                    'initializeUploadRequest' => [
                        'owner' => $authorUrn,
                    ],
                ],
            ]);
            $initBody = json_decode($initResp->getBody()->getContents(), true);
            $assetUrn = $initBody['value']['document'] ?? null;
            $uploadUrl = $initBody['value']['uploadUrl'] ?? null;

            if (!$assetUrn || !$uploadUrl) {
                return ['success' => false, 'assetUrn' => null, 'error' => 'document initializeUpload did not return document URN or upload URL'];
            }

            // Single PUT (documents don't need chunking like videos)
            $this->httpClient->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => $mediaMime,
                    'Content-Length' => strlen($mediaData),
                ],
                'body' => $mediaData,
            ]);

            // Poll until AVAILABLE
            $this->waitForMediaReady($assetUrn, $accessToken, 'documents');

            return ['success' => true, 'assetUrn' => $assetUrn, 'error' => null];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $body = $resp ? json_decode($resp->getBody()->getContents(), true) : null;
            $err = $body['message'] ?? $e->getMessage();
            // LinkedIn rejects non-PDF with: "File type is not supported"
            if (stripos($err, 'not supported') !== false) {
                $err .= ' — LinkedIn only supports PDF documents.';
            }
            return ['success' => false, 'assetUrn' => null, 'error' => $err];
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
     * Post a comment on a LinkedIn post (for first_comment feature).
     * Uses POST /rest/comments with socialDetail URN.
     */
    private function postFirstComment(string $postUrn, string $text, string $accessToken): ?string
    {
        try {
            // Wait 3s to ensure post is fully indexed
            sleep(3);

            $response = $this->httpClient->post('https://api.linkedin.com/rest/comments', [
                'headers' => $this->apiHeaders($accessToken),
                'json' => [
                    'actor' => 'urn:li:person:' . basename($postUrn), // best-effort
                    'message' => ['text' => $text],
                    'socialDetail' => $postUrn,
                ],
            ]);
            return 'First comment posted';
        } catch (Exception $e) {
            return 'First comment failed (non-critical): ' . $e->getMessage();
        }
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
