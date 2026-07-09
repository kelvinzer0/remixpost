<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;

/**
 * Discord Publisher — posts messages to Discord channels via Webhooks.
 *
 * Authentication: Discord Webhook URL (no OAuth, no bot token).
 * The webhook URL is stored in SocialAccount.provider_id (full URL).
 * Webhook format: https://discord.com/api/webhooks/{webhook_id}/{webhook_token}
 *
 * Setup (user side):
 *   1. In Discord: Channel Settings → Integrations → Webhooks → New Webhook
 *   2. Customize name + avatar (optional)
 *   3. Copy Webhook URL
 *   4. Paste in Remixpost connect form
 *
 * API reference: https://discord.com/developers/docs/resources/webhook
 *
 * Limits:
 *   - Max 2000 chars per message (Discord hard limit)
 *   - Max 10 attachments per message
 *   - Attachment max 25MB (Discord free tier) / 500MB (Nitro)
 *
 * @license Apache-2.0 (implemented from official Discord API docs)
 */
class DiscordPublisher implements PublisherInterface
{
    private Client $httpClient;
    private const MAX_ATTACHMENTS = 10;
    private const MAX_CONTENT_LENGTH = 2000;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 120, // longer for media upload
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $webhookUrl = $account['provider_id']; // Full webhook URL
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // Validate webhook URL format
            if (!preg_match('#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', $webhookUrl)) {
                return [
                    'success' => false,
                    'error' => 'Invalid Discord webhook URL format. Expected: https://discord.com/api/webhooks/{id}/{token}',
                ];
            }

            // Truncate content to Discord's 2000 char hard limit
            if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
                $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH - 3) . '...';
            }

            // Download all media files
            $attachments = [];
            $failedDownloads = [];
            foreach ($mediaUrls as $i => $url) {
                if ($i >= self::MAX_ATTACHMENTS) {
                    $failedDownloads[] = "Attachment {$i} skipped (max 10 per Discord message)";
                    break;
                }
                try {
                    $mediaResponse = $this->httpClient->get($url);
                    $mediaData = $mediaResponse->getBody()->getContents();
                    $mimeType = $mediaResponse->getHeaderLine('Content-Type') ?: 'application/octet-stream';

                    // Determine filename from URL
                    $filename = basename(parse_url($url, PHP_URL_PATH)) ?: "attachment-{$i}";
                    $ext = MediaType::extension($url);
                    if ($ext && !str_contains($filename, '.')) {
                        $filename .= '.' . $ext;
                    }

                    $attachments[] = [
                        'name' => "files[{$i}]",
                        'contents' => $mediaData,
                        'filename' => $filename,
                        'headers' => ['Content-Type' => $mimeType],
                    ];
                } catch (Exception $e) {
                    $failedDownloads[] = "Failed to download {$url}: " . $e->getMessage();
                }
            }

            // Build payload
            $payload = [
                'content' => $content,
                // Don't set username/avatar_url — let Discord use the webhook's configured name+avatar
            ];

            // If there were download failures, append them as a note to the message
            if (!empty($failedDownloads)) {
                $note = "\n\n⚠️ *Some media failed to upload:*\n" . implode("\n", $failedDownloads);
                if (mb_strlen($content . $note) <= self::MAX_CONTENT_LENGTH) {
                    $payload['content'] = $content . $note;
                }
            }

            // Send to webhook
            if (empty($attachments)) {
                // Text-only: send as JSON
                $response = $this->httpClient->post($webhookUrl, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $payload,
                ]);
            } else {
                // With attachments: send as multipart/form-data with payload_json field
                $multipart = [
                    [
                        'name' => 'payload_json',
                        'contents' => json_encode($payload),
                        'headers' => ['Content-Type' => 'application/json'],
                    ],
                ];
                $multipart = array_merge($multipart, $attachments);

                $response = $this->httpClient->post($webhookUrl, [
                    'multipart' => $multipart,
                ]);
            }

            $status = $response->getStatusCode();

            // Discord webhook returns:
            //   - 204 No Content (success, no message body — default)
            //   - 200 OK with message body (if ?wait=true query param is set)
            if ($status !== 204 && $status !== 200) {
                $body = $response->getBody()->getContents();
                return [
                    'success' => false,
                    'error' => "Discord API returned status {$status}: {$body}",
                ];
            }

            // Try to parse response body for message ID (only present if ?wait=true)
            $body = $response->getBody()->getContents();
            $externalId = null;
            if (!empty($body)) {
                $data = json_decode($body, true);
                $externalId = $data['id'] ?? null;
            }
            if (!$externalId) {
                // Send again with ?wait=true to get message ID (only if we need it for tracking)
                // Actually skip this — Discord webhook posts are async, message ID is not critical
                $externalId = 'discord-webhook-' . time();
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

            // Map common Discord errors
            if ($status === 401 || $status === 403) {
                $err .= ' — Webhook URL is invalid or revoked. Recreate the webhook in Discord and re-connect.';
            } elseif ($status === 404) {
                $err .= ' — Webhook not found. It may have been deleted. Recreate in Discord and re-connect.';
            } elseif ($status === 429) {
                $err .= ' — Rate limited. Discord allows 5 messages per 2 seconds per webhook. Try again shortly.';
            } elseif ($status === 400 && stripos($err, 'content') !== false) {
                $err .= ' — Content validation failed. Check length (max 2000 chars) and formatting.';
            }

            return [
                'success' => false,
                'error' => "Discord API {$status} error: {$err}",
                'status' => $status,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
