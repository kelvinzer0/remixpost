<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Odoo Carousel Publisher — creates carousel slides on warunglakku.com.
 *
 * This publisher creates slides on the Odoo website's carousel/slider via
 * the Warung Lakku Carousel REST API. Each post with image media becomes
 * a new carousel slide with desktop + mobile images.
 *
 * Authentication: Bearer token (API key prefixed with 'wlc_').
 * Stored as SocialAccount.access_token.
 *
 * API endpoint: POST https://warunglakku.com/carousel/api/slides
 * Required fields: title, image_desktop (base64), image_mobile (base64)
 * Optional fields: link_url, link_new_tab, sequence, active,
 *                  desktop_media_filename, mobile_media_filename
 *
 * Image requirements:
 *   - Desktop: 1920×960px (2:1 aspect ratio)
 *   - Mobile: 800×1000px (4:5 aspect ratio)
 *   - Both sent as base64-encoded bytes in JSON body
 *
 * Flow:
 *   1. Take first image from post media_urls
 *   2. Auto-generate desktop + mobile versions via GD:
 *      - Desktop: center-crop to 2:1 (1920×960)
 *      - Mobile: center-crop to 4:5 (800×1000)
 *   3. Base64-encode both
 *   4. POST to /carousel/api/slides with title from post content
 *   5. Return slide ID as external_id
 *
 * @license Apache-2.0
 */
class OdooCarouselPublisher implements PublisherInterface
{
    private const API_BASE = 'https://warunglakku.com';
    private const DESKTOP_WIDTH = 1920;
    private const DESKTOP_HEIGHT = 960;
    private const MOBILE_WIDTH = 800;
    private const MOBILE_HEIGHT = 1000;

    public function publish(array $post, array $account): array
    {
        try {
            $apiKey = $account['access_token'];
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // Find first image in media
            $imageUrl = null;
            foreach ($mediaUrls as $url) {
                if (MediaType::fromUrl($url) === 'image') {
                    $imageUrl = $url;
                    break;
                }
            }

            if (!$imageUrl) {
                return [
                    'success' => false,
                    'error' => 'Odoo Carousel requires at least one image in the post.',
                ];
            }

            // Read account overrides for optional fields
            $accountId = (string) ($post['account_id'] ?? $account['id'] ?? '');
            $overrides = $post['account_overrides'] ?? [];
            $overrideData = $overrides[$accountId] ?? [];

            // Title: use override or first 100 chars of content
            $title = $overrideData['carousel_title'] ?? mb_substr($content, 0, 100);
            $linkUrl = $overrideData['carousel_link_url'] ?? '';
            $linkNewTab = (bool) ($overrideData['carousel_link_new_tab'] ?? true);
            $sequence = (int) ($overrideData['carousel_sequence'] ?? 10);

            // Load image from local storage or download
            $localPath = $this->resolveLocalPath($imageUrl);
            $tempInput = null;
            if (!$localPath) {
                $tempInput = tempnam(sys_get_temp_dir(), 'carousel_');
                $response = Http::get($imageUrl);
                if (!$response->ok()) {
                    @unlink($tempInput);
                    return ['success' => false, 'error' => 'Failed to download image for carousel.'];
                }
                file_put_contents($tempInput, $response->body());
                $localPath = $tempInput;
            }

            // Generate desktop image (2:1 = 1920×960)
            $desktopBase64 = $this->generateCroppedImage(
                $localPath,
                self::DESKTOP_WIDTH,
                self::DESKTOP_HEIGHT,
                85
            );

            // Generate mobile image (4:5 = 800×1000)
            $mobileBase64 = $this->generateCroppedImage(
                $localPath,
                self::MOBILE_WIDTH,
                self::MOBILE_HEIGHT,
                85
            );

            // Cleanup temp
            if ($tempInput) {
                @unlink($tempInput);
            }

            if (!$desktopBase64 || !$mobileBase64) {
                return ['success' => false, 'error' => 'Failed to generate desktop/mobile carousel images.'];
            }

            // Build request body
            $slideData = [
                'title' => $title,
                'image_desktop' => $desktopBase64,
                'image_mobile' => $mobileBase64,
                'desktop_media_filename' => 'carousel-desktop.jpg',
                'mobile_media_filename' => 'carousel-mobile.jpg',
                'sequence' => $sequence,
                'active' => true,
                'link_new_tab' => $linkNewTab,
            ];

            if ($linkUrl) {
                $slideData['link_url'] = $linkUrl;
            }

            // POST to Odoo Carousel API
            $response = Http::withToken($apiKey)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::API_BASE . '/carousel/api/slides', $slideData);

            if (!$response->created() && !$response->ok()) {
                $errBody = $response->json();
                $errMsg = $errBody['error'] ?? $errBody['message'] ?? $response->body();
                return [
                    'success' => false,
                    'error' => "Odoo Carousel API error ({$response->status()}): {$errMsg}",
                ];
            }

            $body = $response->json();
            $slideId = $body['id'] ?? null;

            if (!$slideId) {
                return ['success' => false, 'error' => 'Odoo Carousel did not return slide ID.'];
            }

            return [
                'success' => true,
                'external_id' => (string) $slideId,
                'info' => "Carousel slide created (ID: {$slideId}). Desktop: 1920×960 (2:1), Mobile: 800×1000 (4:5). View at https://warunglakku.com",
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Center-crop an image to exact dimensions and return as base64 JPEG.
     *
     * Uses GD (already installed). Applies EXIF orientation correction
     * for JPEGs before cropping (same logic as WatermarkService).
     *
     * @param string $localPath  Absolute path to source image
     * @param int    $targetW    Target width in pixels
     * @param int    $targetH    Target height in pixels
     * @param int    $quality    JPEG quality (0-100)
     * @return string|null       Base64-encoded JPEG, or null on failure
     */
    private function generateCroppedImage(string $localPath, int $targetW, int $targetH, int $quality = 85): ?string
    {
        $mime = mime_content_type($localPath);

        // Load image based on MIME type
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($localPath) ?: null,
            'image/png'  => @imagecreatefrompng($localPath) ?: null,
            'image/gif'  => @imagecreatefromgif($localPath) ?: null,
            'image/webp' => @imagecreatefromwebp($localPath) ?: null,
            'image/bmp'  => @imagecreatefrombmp($localPath) ?: null,
            default      => null,
        };

        if (!$image) {
            return null;
        }

        // Apply EXIF orientation for JPEGs
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($localPath);
            if ($exif && !empty($exif['Orientation'])) {
                $image = $this->applyExifOrientation($image, (int) $exif['Orientation']);
            }
        }

        $origW = imagesx($image);
        $origH = imagesy($image);

        // Center-crop to target aspect ratio
        $targetRatio = $targetW / $targetH;
        $origRatio = $origW / $origH;

        if ($origRatio > $targetRatio) {
            // Source wider — crop width
            $cropW = (int) ($origH * $targetRatio);
            $cropH = $origH;
            $srcX = (int) (($origW - $cropW) / 2);
            $srcY = 0;
        } else {
            // Source taller — crop height
            $cropW = $origW;
            $cropH = (int) ($origW / $targetRatio);
            $srcX = 0;
            $srcY = (int) (($origH - $cropH) / 2);
        }

        // Create canvas at target dimensions
        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, $targetW, $targetH, $cropW, $cropH);

        // Encode as JPEG → base64
        ob_start();
        imagejpeg($canvas, null, $quality);
        $jpegData = ob_get_clean();

        imagedestroy($image);
        imagedestroy($canvas);

        if (!$jpegData) {
            return null;
        }

        return base64_encode($jpegData);
    }

    /**
     * Apply EXIF orientation to GD image (same as WatermarkService).
     */
    private function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $bg = imagecolorallocatealpha($image, 0, 0, 0, 127);
        switch ($orientation) {
            case 3: $r = imagerotate($image, 180, $bg); imagedestroy($image); return $r;
            case 6: $r = imagerotate($image, 90, $bg); imagedestroy($image); return $r;
            case 8: $r = imagerotate($image, -90, $bg); imagedestroy($image); return $r;
            case 2: imageflip($image, IMG_FLIP_HORIZONTAL); return $image;
            case 4: imageflip($image, IMG_FLIP_VERTICAL); return $image;
            case 5: imageflip($image, IMG_FLIP_HORIZONTAL); $r = imagerotate($image, 90, $bg); imagedestroy($image); return $r;
            case 7: imageflip($image, IMG_FLIP_HORIZONTAL); $r = imagerotate($image, -90, $bg); imagedestroy($image); return $r;
            default: return $image;
        }
    }

    /**
     * Resolve public storage URL to local file path.
     */
    private function resolveLocalPath(string $url): ?string
    {
        $appUrl = config('app.url');
        if (!str_starts_with($url, $appUrl)) {
            return null;
        }
        $storagePrefix = rtrim($appUrl, '/') . '/storage/';
        if (!str_starts_with($url, $storagePrefix)) {
            return null;
        }
        $relativePath = substr($url, strlen($storagePrefix));
        $localPath = storage_path('app/public/' . $relativePath);
        return file_exists($localPath) ? $localPath : null;
    }
}
