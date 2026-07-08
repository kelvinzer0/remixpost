<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * LinkedIn Publisher — posts to personal feed or Company Pages via API v2.
 *
 * Authentication: OAuth 2.0 with access_token. Scope w_member_social for personal,
 * rw_organization + r_organization_social for Company Pages.
 *
 * API endpoints used:
 *   - POST https://api.linkedin.com/v2/ugcPosts (create post)
 *   - POST https://api.linkedin.com/v2/assets?action=registerUpload (register media upload)
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
            'timeout' => 30,
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

            // Handle media
            if (!empty($mediaUrls)) {
                $media = [];
                foreach ($mediaUrls as $url) {
                    $assetUrn = $this->uploadMedia($url, $accessToken, $authorUrn);
                    if ($assetUrn) {
                        $media[] = [
                            'status' => 'READY',
                            'description' => ['text' => ''],
                            'media' => $assetUrn,
                            'mediaType' => $this->detectMediaType($url),
                        ];
                    }
                }
                if (!empty($media)) {
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'IMAGE';
                    $ugcPost['specificContent']['com.linkedin.ugc.ShareContent']['media'] = $media;
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
     */
    private function uploadMedia(string $url, string $accessToken, string $authorUrn): ?string
    {
        try {
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
                            'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
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

            // Step 2: Download image and upload to LinkedIn's upload URL
            $imageResponse = $this->httpClient->get($url);
            $imageData = $imageResponse->getBody()->getContents();

            $this->httpClient->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => $imageData,
            ]);

            return $assetUrn;
        } catch (Exception $e) {
            return null;
        }
    }

    private function detectMediaType(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['mp4', 'mov', 'avi', 'webm'])) {
            return 'VIDEO';
        }

        return 'IMAGE';
    }
}
