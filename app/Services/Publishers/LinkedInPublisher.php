<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;

/**
 * LinkedIn Publisher — posts to personal feed or Company Pages via API v2.
 *
 * Authentication: OAuth 2.0 with access_token. Scope w_member_social for personal,
 * rw_organization_admin + w_organization_social + r_organization_social for Company Pages.
 *
 * API endpoints used:
 *   - POST https://api.linkedin.com/v2/ugcPosts (create post)
 *   - POST https://api.linkedin.com/v2/assets?action=registerUpload (register media upload)
 *   - GET  https://api.linkedin.com/v2/assets/{asset-id} (poll asset status for videos)
 *
 * Supported media:
 *   - Images: registerUpload with recipe urn:li:digitalmediaRecipe:feedshare-image
 *   - Videos: registerUpload with recipe urn:li:digitalmediaRecipe:feedshare-video
 *     Then poll asset status until AVAILABLE before posting ugcPosts
 *   - shareMediaCategory: IMAGE for image-only, VIDEO for video-only, NONE for text-only
 *
 * Reference:
 *   https://learn.microsoft.com/en-us/linkedin/marketing/integrations/community-management/shares/ugc-post-api
 *   https://learn.microsoft.com/en-us/linkedin/marketing/integrations/community-management/shares/video-upload-api
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class LinkedInPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 300, // 5 min for large video uploads + status polling
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

            $ugcPost = [
                'author' => $authorUrn,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => [
                            'text' => $content,
                        ],
                        'shareMediaCategory' => 'NONE',
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ];

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

            // Handle media — prefer images if mixed (LinkedIn only allows one category per post)
            if (!empty($imageUrls)) {
                $media = [];
                foreach ($imageUrls as $url) {
                    $result = $this->uploadMedia($url, $accessToken, $authorUrn, 'image');
                    if ($result['success']) {
                        $media[] = [
                            'status' => 'READY',
                            'description' => ['text' => ''],
                            'media' => $result['assetUrn'],
                            'mediaType' => 'IMAGE',
                        ];
                    } else {
                        // Log warning, continue with remaining images
                        \Illuminate\Support\Facades\Log::warning('LinkedIn image upload failed', [
                            'url' => $url,
                            'error' => $result['error'],
                        ]);
                    }
                }
                if (!empty($media)) {
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'IMAGE';
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['media'] = $media;
                }
            } elseif (!empty($videoUrls)) {
                // Video post — single video only (LinkedIn limitation)
                $url = $videoUrls[0];
                $result = $this->uploadMedia($url, $accessToken, $authorUrn, 'video');
                if (!$result['success']) {
                    return [
                        'success' => false,
                        'error' => 'LinkedIn video upload failed: ' . $result['error'],
                    ];
                }

                // For video, MUST wait until asset status is AVAILABLE before ugcPost
                $assetUrn = $result['assetUrn'];
                $assetStatus = $this->waitForAssetReady($assetUrn, $accessToken);
                if (!$assetStatus['ready']) {
                    return [
                        'success' => false,
                        'error' => 'LinkedIn video processing failed or timed out: ' . ($assetStatus['error'] ?? 'Unknown'),
                    ];
                }

                $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'VIDEO';
                $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
                    'status' => 'READY',
                    'description' => ['text' => mb_substr($content, 0, 300)],
                    'media' => $assetUrn,
                    'mediaType' => 'VIDEO',
                ]];
            }

            $response = $this->httpClient->post('https://api.linkedin.com/v2/ugcPosts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'json' => $ugcPost,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            // LinkedIn returns the post URN in 'id' or 'activity' field
            $externalId = $body['id'] ?? $body['activity'] ?? null;

            if (!$externalId) {
                return ['success' => false, 'error' => 'LinkedIn did not return post ID', 'response' => $body];
            }

            return [
                'success' => true,
                'external_id' => $externalId,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload media to LinkedIn using registerUpload flow.
     * Uses feedshare-image recipe for images, feedshare-video recipe for videos.
     * Returns ['success' => bool, 'assetUrn' => string|null, 'error' => string|null].
     */
    private function uploadMedia(string $url, string $accessToken, string $authorUrn, string $type = 'image'): array
    {
        try {
            $recipe = $type === 'video'
                ? 'urn:li:digitalmediaRecipe:feedshare-video'
                : 'urn:li:digitalmediaRecipe:feedshare-image';

            // Step 1: Register upload to get upload URL
            $registerResponse = $this->httpClient->post(
                'https://api.linkedin.com/v2/assets?action=registerUpload',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'registerUploadRequest' => [
                            'recipes' => [$recipe],
                            'owner' => $authorUrn,
                            'serviceRelationships' => [
                                [
                                    'relationshipType' => 'PROJECTION',
                                    'identifier' => 'urn:li:userGeneratedContent',
                                ],
                            ],
                        ],
                    ],
                ]
            );

            $registerBody = json_decode($registerResponse->getBody()->getContents(), true);
            $assetUrn = $registerBody['value']['asset'] ?? null;
            $uploadUrl = $registerBody['value']['uploadMechanism']
                ['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? null;

            if (!$assetUrn || !$uploadUrl) {
                return ['success' => false, 'assetUrn' => null, 'error' => 'registerUpload did not return asset or upload URL'];
            }

            // Step 2: Download media and upload to LinkedIn's upload URL
            $mediaResponse = $this->httpClient->get($url);
            $mediaData = $mediaResponse->getBody()->getContents();
            $mediaMime = $mediaResponse->getHeaderLine('Content-Type') ?: (
                $type === 'video' ? 'video/mp4' : 'application/octet-stream'
            );

            // LinkedIn's upload URL expects binary data PUT.
            // IMPORTANT: do NOT send Authorization header to the upload URL — it uses presigned URL auth
            $this->httpClient->put($uploadUrl, [
                'headers' => [
                    'Content-Type' => $mediaMime,
                    'Content-Length' => strlen($mediaData),
                ],
                'body' => $mediaData,
            ]);

            return ['success' => true, 'assetUrn' => $assetUrn, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'assetUrn' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Poll LinkedIn asset status until AVAILABLE (for videos).
     * For images, asset is immediately available so this returns quickly.
     *
     * Returns ['ready' => bool, 'error' => string|null].
     */
    private function waitForAssetReady(string $assetUrn, string $accessToken): array
    {
        // Extract asset ID from URN (e.g., urn:li:digitalmediaAsset:C5F10AAE8I89000...)
        $assetId = strrpos($assetUrn, ':') !== false
            ? substr($assetUrn, strrpos($assetUrn, ':') + 1)
            : $assetUrn;

        $maxAttempts = 60; // 60 attempts × 5s = 5 minutes max
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->httpClient->get(
                    "https://api.linkedin.com/v2/assets/{$assetId}",
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                            'X-Restli-Protocol-Version' => '2.0.0',
                        ],
                    ]
                );

                $body = json_decode($response->getBody()->getContents(), true);
                $status = $this->getAssetStatus($body);

                if ($status === 'AVAILABLE') {
                    return ['ready' => true, 'error' => null];
                }
                if (in_array($status, ['FAILED', 'CANCELLED'])) {
                    return ['ready' => false, 'error' => "Asset status: {$status}"];
                }

                // Still processing — wait and retry
                sleep(5);
            } catch (Exception $e) {
                // Transient error — wait and retry
                sleep(5);
            }
        }

        return ['ready' => false, 'error' => 'Timeout waiting for asset to become AVAILABLE'];
    }

    /**
     * Extract asset status from LinkedIn API response.
     * Status field can be in different locations depending on recipe.
     */
    private function getAssetStatus(array $body): ?string
    {
        // For videos: recipes[0].status
        if (isset($body['recipes'][0]['status'])) {
            return $body['recipes'][0]['status'];
        }
        // Fallback: top-level status field
        return $body['status'] ?? null;
    }
}
