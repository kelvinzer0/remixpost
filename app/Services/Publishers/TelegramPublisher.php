<?php

namespace App\Services\Publishers;

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
 *   - POST https://api.telegram.org/bot{TOKEN}/sendMessage (text)
 *   - POST https://api.telegram.org/bot{TOKEN}/sendPhoto (single image)
 *   - POST https://api.telegram.org/bot{TOKEN}/sendMediaGroup (multiple images)
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
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $botToken = $account['access_token']; // Bot token stored as access_token
            $chatId = $account['provider_id']; // @channelusername or -100... chat ID
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            $apiBase = "https://api.telegram.org/bot{$botToken}";

            // Single image with caption
            if (count($mediaUrls) === 1) {
                return $this->sendPhoto($apiBase, $chatId, $content, $mediaUrls[0]);
            }

            // Multiple images as media group
            if (count($mediaUrls) > 1) {
                return $this->sendMediaGroup($apiBase, $chatId, $content, $mediaUrls);
            }

            // Text only
            return $this->sendMessage($apiBase, $chatId, $content);
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

    private function sendPhoto(string $apiBase, string $chatId, string $caption, string $photoUrl): array
    {
        $response = $this->httpClient->post("{$apiBase}/sendPhoto", [
            'form_params' => [
                'chat_id' => $chatId,
                'photo' => $photoUrl,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ],
        ]);

        return $this->parseResponse($response);
    }

    private function sendMediaGroup(string $apiBase, string $chatId, string $caption, array $photoUrls): array
    {
        $media = [];
        foreach ($photoUrls as $i => $url) {
            $media[] = [
                'type' => 'photo',
                'media' => $url,
                // Only first item can have caption
                'caption' => $i === 0 ? $caption : '',
                'parse_mode' => 'HTML',
            ];
        }

        $response = $this->httpClient->post("{$apiBase}/sendMediaGroup", [
            'form_params' => [
                'chat_id' => $chatId,
                'media' => json_encode($media),
            ],
        ]);

        return $this->parseResponse($response);
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
            $messageId = (string) $body['result'][0]['message_id'];
        }

        return [
            'success' => true,
            'external_id' => $messageId,
        ];
    }
}
