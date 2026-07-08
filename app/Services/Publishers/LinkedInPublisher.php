<?php

namespace App\Services\Publishers;

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
 *
 * Supported media:
 *   - Images: registerUpload with recipe urn:li:digitalmediaRecipe:feedshare-image
 *   - Videos: registerUpload with recipe urn:li:digitalmediaRecipe:feedshare-video
 *   - shareMediaCategory: IMAGE for image-only, VIDEO for video-only, NONE for text-only
 *
 * Reference: https://learn.microsoft.com/en-us/linkedin/marketing/integrations/community-management/shares/ugc-post-api
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class LinkedInPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 120,
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
                if ($this->isVideoUrl($url)) {
                    $videoUrls[] = $url;
                } else {
                    $imageUrls[] = $url;
                }
            }

            // Handle media — prefer images if mixed (LinkedIn only allows one category per post)
            if (!empty($imageUrls)) {
                $media = [];
                foreach ($imageUrls as $url) {
                    $assetUrn = $this->uploadMedia($url, $accessToken, $authorUrn, 'image');
                    if ($assetUrn) {
                        $media[] = [
                            'status' => 'READY',
                            'description' => ['text' => ''],
                            'media' => $assetUrn,
                            'mediaType' => 'IMAGE',
                        ];
                    }
                }
                if (!empty($media)) {
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'IMAGE';
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['media'] = $media;
                }
            } elseif (!empty($videoUrls)) {
                // Video post — single video only
                $url = $videoUrls[0];
                $assetUrn = $this->uploadMedia($url, $accessToken, $authorUrn, 'video');
                if ($assetUrn) {
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'VIDEO';
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
                        'status' => 'READY',
                        'description' => ['text' => mb_substr($content, 0, 300)],
                        'media' => $assetUrn,
                        'mediaType' => 'VIDEO',
                    ]];
                }
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
     */
    private function uploadMedia(string $url, string $accessToken, string $authorUrn, string $type = 'image'): ?string
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
                return null;
            }

            // Step 2: Download media and upload to LinkedIn's upload URL
            $mediaResponse = $this->httpClient->get($url);
            $mediaData = $mediaResponse->getBody()->getContents();
            $mediaMime = $mediaResponse->getHeaderLine('Content-Type') ?: (
                $type === 'video' ? 'video/mp4' : 'application/octet-stream'
            );

            $this->httpClient->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => $mediaMime,
                ],
                'body' => $mediaData,
            ]);

            return $assetUrn;
        } catch (Exception $e) {
            return null;
        }
    }

    private function isVideoUrl(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v']);
    }
}
