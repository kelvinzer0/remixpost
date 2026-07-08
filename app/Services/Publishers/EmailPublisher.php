<?php

namespace App\Services\Publishers;

use Exception;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Email Publisher — sends HTML email newsletter to a recipient list.
 *
 * No OAuth — uses SMTP credentials configured in .env (MAIL_* / SMTP_*).
 * The "social account" stores the recipient email address in provider_id.
 *
 * Behavior:
 *   - Post content → HTML email body
 *   - All images → embedded inline (CID attachments)
 *   - All videos and other media → clickable links appended after body
 *   - Sends via Laravel Mail facade (uses config/mail.php SMTP driver)
 *
 * @license Apache-2.0
 */
class EmailPublisher implements PublisherInterface
{
    public function publish(array $post, array $account): array
    {
        try {
            $recipientEmail = $account['provider_id']; // Recipient email address
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // Validate email
            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'error' => "Invalid recipient email: {$recipientEmail}",
                ];
            }

            // Build HTML body (simple — convert newlines to <br>)
            $htmlBody = nl2br(e($content));

            // Attach all media: images embedded inline, videos/others as clickable links
            $inlineAttachments = []; // [temp_path, mime] for images embedded via CID
            $mediaLinksHtml = [];    // text link entries for non-image media

            foreach ($mediaUrls as $i => $url) {
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
                $isVideo = in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);

                if ($isImage) {
                    // Download and embed inline via CID
                    try {
                        $imgData = @file_get_contents($url);
                        if ($imgData !== false) {
                            $mime = $this->guessImageMime($ext);
                            $tempPath = storage_path('app/temp/email-image-' . $i . '-' . uniqid() . '.' . $ext);
                            file_put_contents($tempPath, $imgData);
                            $inlineAttachments[] = ['path' => $tempPath, 'mime' => $mime, 'ext' => $ext, 'cid' => 'image-' . $i];
                            $htmlBody .= '<br><br><img src="cid:image-' . $i . '" style="max-width:100%;height:auto;border-radius:6px;">';
                        }
                    } catch (Exception $e) {
                        // Fall back to link if download fails
                        $mediaLinksHtml[] = '<p>📷 Image: <a href="' . e($url) . '">' . e($url) . '</a></p>';
                    }
                } elseif ($isVideo) {
                    // Append video as link (we can't easily embed video in email)
                    $mediaLinksHtml[] = '<p>🎬 Video: <a href="' . e($url) . '">' . e($url) . '</a></p>';
                } else {
                    // Other media: just a link
                    $mediaLinksHtml[] = '<p>📎 Media: <a href="' . e($url) . '">' . e($url) . '</a></p>';
                }
            }

            // Append media links section after body if any
            if (!empty($mediaLinksHtml)) {
                $htmlBody .= '<br><hr style="border:0;border-top:1px solid #eee;margin:12px 0;">';
                $htmlBody .= '<p style="font-size:12px;color:#666;margin:4px 0;">Media attachments:</p>';
                foreach ($mediaLinksHtml as $link) {
                    $htmlBody .= $link;
                }
            }

            // Send email
            Mail::html($htmlBody, function ($message) use ($recipientEmail, $content, $inlineAttachments) {
                $message->to($recipientEmail)
                    ->subject(mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '...' : ''));

                foreach ($inlineAttachments as $att) {
                    $message->attach($att['path'], [
                        'as' => 'image-' . $att['cid'] . '.' . $att['ext'],
                        'mime' => $att['mime'],
                    ]);
                }
            });

            // Cleanup temp files
            foreach ($inlineAttachments as $att) {
                @unlink($att['path']);
            }

            return [
                'success' => true,
                'external_id' => 'email-' . time() . '-' . uniqid(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function guessImageMime(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'svg'  => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
