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
 *   - First media URL → email attachment (image)
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

            // Attach first image inline if present
            $attachments = [];
            if (!empty($mediaUrls)) {
                $imageUrl = $mediaUrls[0];
                try {
                    $imageData = file_get_contents($imageUrl);
                    if ($imageData !== false) {
                        $tempPath = storage_path('app/temp/email-image-' . uniqid() . '.jpg');
                        file_put_contents($tempPath, $imageData);
                        $attachments[] = $tempPath;
                        $htmlBody .= '<br><br><img src="cid:image-0" style="max-width:100%;height:auto;">';
                    }
                } catch (Exception $e) {
                    // Skip image if download fails
                }
            }

            // Send email
            Mail::html($htmlBody, function ($message) use ($recipientEmail, $content, $attachments) {
                $message->to($recipientEmail)
                    ->subject(mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '...' : ''));

                foreach ($attachments as $i => $path) {
                    $message->attach($path, [
                        'as' => 'image-' . $i . '.jpg',
                        'mime' => 'image/jpeg',
                    ]);
                }
            });

            // Cleanup temp files
            foreach ($attachments as $path) {
                @unlink($path);
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
}
