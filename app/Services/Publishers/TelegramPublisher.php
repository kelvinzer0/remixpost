<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;

/**
 * Telegram Publisher — sends messages to Telegram channels via Bot API.
 *
 * Authentication: Bot Token (no OAuth flow). The bot must be added as admin
 * to the target channel. The "provider_id" stored in SocialAccount is the
 * channel username (e.g. @mychannel) or chat ID (e.g. -1001234567890).
 *
 * API endpoints:
 *   - POST https://api.telegram.org/bot{TOKEN}/sendMessage      (text-only)
 *   - POST https://api.telegram.org/bot{TOKEN}/sendPhoto         (single image)
 *   - POST https://api.telegram.org/bot{TOKEN}/sendVideo         (single video)
 *   - POST https://api.telegram.org/bot{TOKEN}/sendDocument      (single other file)
 *   - POST https://api.telegram.org/bot{TOKEN}/sendMediaGroup    (multiple media, mixed types OK)
 *
 * Reference: https://core.telegram.org/bots/api
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class TelegramPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 120, // longer for video upload
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $botToken = $account['access_token'];
            $chatId = $account['provider_id'];
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];

            // Append tags as #hashtags to content
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $content = rtrim($content) . "\n\n" . $tagStr;
            }

            $apiBase = "https://api.telegram.org/bot{$botToken}";

            // No media → text-only message
            if (empty($mediaUrls)) {
                return $this->sendMessage($apiBase, $chatId, $content);
            }

            // Single media → use the correct endpoint per type
            if (count($mediaUrls) === 1) {
                return $this->sendSingleMedia($apiBase, $chatId, $content, $mediaUrls[0]);
            }

            // Multiple media → sendMediaGroup (mixed types allowed)
            return $this->sendMediaGroup($apiBase, $chatId, $content, $mediaUrls);

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function sendMessage(string $apiBase, string $chatId, string $text): array
    {
        $response = $this->httpClient->post("{$apiBase}/sendMessage", [
            'form_params' => [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ],
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Send a single media item using the appropriate endpoint based on its type.
     * Falls back to sendDocument for unknown types (e.g., PDF, ZIP).
     */
    private function sendSingleMedia(string $apiBase, string $chatId, string $caption, string $mediaUrl): array
    {
        $type = MediaType::fromUrl($mediaUrl);

        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        switch ($type) {
            case 'image':
                $endpoint = '/sendPhoto';
                $fileField = 'photo';
                break;
            case 'video':
                $endpoint = '/sendVideo';
                $fileField = 'video';
                $params['supports_streaming'] = 'true';
                break;
            default:
                $endpoint = '/sendDocument';
                $fileField = 'document';
                break;
        }

        // Download media file first, then upload as multipart (not URL).
        // Telegram URL download limit is 5MB for images, but file upload
        // limit is 50MB. By downloading + uploading, we avoid the 5MB
        // URL limit and also work with larger files (compression helps too).
        try {
            $mediaResponse = $this->httpClient->get($mediaUrl);
            $mediaData = $mediaResponse->getBody()->getContents();
            $mediaMime = $mediaResponse->getHeaderLine('Content-Type') ?: 'application/octet-stream';
            $ext = MediaType::extension($mediaUrl) ?: 'bin';
            $filename = 'media.' . $ext;

            $multipart = [
                ['name' => 'chat_id', 'contents' => $params['chat_id']],
                ['name' => 'caption', 'contents' => $params['caption'] ?? ''],
                ['name' => 'parse_mode', 'contents' => $params['parse_mode'] ?? 'HTML'],
            ];
            if (isset($params['supports_streaming'])) {
                $multipart[] = ['name' => 'supports_streaming', 'contents' => 'true'];
            }
            $multipart[] = [
                'name' => $fileField,
                'contents' => $mediaData,
                'filename' => $filename,
                'headers' => ['Content-Type' => $mediaMime],
            ];

            $response = $this->httpClient->post("{$apiBase}{$endpoint}", [
                'multipart' => $multipart,
            ]);
        } catch (\Exception $e) {
            // Fallback: try URL method (works for small files <5MB)
            $params[$fileField] = $mediaUrl;
            $response = $this->httpClient->post("{$apiBase}{$endpoint}", [
                'form_params' => $params,
            ]);
        }

        return $this->parseResponse($response);
    }

    /**
     * Send multiple media items as a media group.
     * Telegram allows mixed types in a media group (photos + videos).
     * Documents can only be in a media group if all items are documents.
     * Only the first item can have a caption.
     */
    private function sendMediaGroup(string $apiBase, string $chatId, string $caption, array $mediaUrls): array
    {
        $media = [];
        foreach ($mediaUrls as $i => $url) {
            $type = MediaType::fromUrl($url);
            // Telegram media group only supports 'photo' and 'video' types.
            // Documents get sent as 'photo' won't work — we fall back to 'document'
            // but that means the group MUST be all documents or all photo/video mix.
            $tgType = ($type === 'image') ? 'photo' : (($type === 'video') ? 'video' : 'document');

            $item = [
                'type' => $tgType,
                'media' => $url,
            ];
            if ($i === 0) {
                $item['caption'] = $caption;
                $item['parse_mode'] = 'HTML';
            }
            $media[] = $item;
        }

        $response = $this->httpClient->post("{$apiBase}/sendMediaGroup", [
            'form_params' => [
                'chat_id' => $chatId,
                'media' => json_encode($media),
            ],
        ]);

        $result = $this->parseResponse($response);

        // If media group fails because of mixed document types, fall back to
        // sending each item individually (with caption on first only).
        if (!$result['success'] && !empty($mediaUrls)) {
            return $this->sendIndividually($apiBase, $chatId, $caption, $mediaUrls);
        }

        return $result;
    }

    /**
     * Fallback: send each media item as its own message.
     * First item carries the caption; the rest go without.
     */
    private function sendIndividually(string $apiBase, string $chatId, string $caption, array $mediaUrls): array
    {
        $firstMessageId = null;
        $lastError = null;

        foreach ($mediaUrls as $i => $url) {
            $itemCaption = ($i === 0) ? $caption : '';
            $result = $this->sendSingleMedia($apiBase, $chatId, $itemCaption, $url);
            if ($result['success']) {
                if ($firstMessageId === null) {
                    $firstMessageId = $result['external_id'] ?? null;
                }
            } else {
                $lastError = $result['error'] ?? 'Unknown error';
            }
        }

        if ($firstMessageId !== null) {
            return [
                'success' => true,
                'external_id' => $firstMessageId,
                'warning' => $lastError ? "Some media failed to send. Last error: {$lastError}" : null,
            ];
        }

        return [
            'success' => false,
            'error' => $lastError ?? 'All media failed to send',
        ];
    }

    private function parseResponse($response): array
    {
        $body = json_decode($response->getBody()->getContents(), true);

        if (!($body['ok'] ?? false)) {
            return [
                'success' => false,
                'error' => $body['description'] ?? 'Telegram API error',
            ];
        }

        $messageId = null;
        if (isset($body['result']['message_id'])) {
            $messageId = (string) $body['result']['message_id'];
        } elseif (isset($body['result'][0]['message_id'])) {
            // sendMediaGroup returns array of messages
            $messageId = (string) $body['result'][0]['message_id'];
        }

        return [
            'success' => true,
            'external_id' => $messageId,
        ];
    }
}
