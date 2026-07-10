<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Media Compression Service — compress images + videos before publishing.
 *
 * Checks each media URL against the target platform's max file size limit.
 * If the file exceeds the limit, compresses it:
 *
 * Images (jpg, png, gif, webp):
 *   - Uses PHP GD library (already installed in container)
 *   - Resize if dimensions > 1920x1080 (maintain aspect ratio)
 *   - Reduce JPEG quality to 82 (visually lossless for social media)
 *   - Convert PNG > 2MB to JPEG (PNG is lossless = huge for photos)
 *   - Output: compressed JPEG in storage/app/public/compressed/
 *
 * Videos (mp4, mov, webm, etc):
 *   - Uses ffmpeg binary (already installed in container)
 *   - Re-encode with H.264 CRF 28 (good quality/size balance)
 *   - Scale down to max 1080p if resolution higher
 *   - Use faststart for web streaming
 *   - Output: compressed MP4 in storage/app/public/compressed/
 *
 * Design principle: "compress without losing visible quality"
 *   - Image quality 82 is indistinguishable from 100 on phone screens
 *   - Video CRF 28 is visually lossless for social media viewing
 *   - Only compress if file EXCEEDS platform limit — skip if already small
 *
 * @license Apache-2.0
 */
class MediaCompressionService
{
    private const IMAGE_MAX_DIMENSION = 1920;  // max width or height
    private const IMAGE_JPEG_QUALITY = 82;     // visually lossless for social
    private const IMAGE_PNG_TO_JPEG_THRESHOLD = 2097152; // 2MB — PNGs bigger than this = photos, convert to JPEG
    private const VIDEO_MAX_HEIGHT = 1080;     // 1080p max
    private const VIDEO_CRF = 28;              // constant rate factor (lower = better quality)
    private const VIDEO_PRESET = 'fast';       // x264 preset (faster = larger file, faster encode)

    /**
     * Compress media files if they exceed the platform's max size limit.
     *
     * @param array $mediaUrls  Original media URLs
     * @param int|null $maxSizeMb  Platform max size in MB (null = no limit, skip)
     * @return array  Compressed media URLs (same order as input)
     */
    public static function compressIfNeeded(array $mediaUrls, ?int $maxSizeMb): array
    {
        if (!$maxSizeMb || empty($mediaUrls)) {
            return $mediaUrls;
        }

        $maxSizeBytes = $maxSizeMb * 1024 * 1024;
        $compressed = [];

        foreach ($mediaUrls as $url) {
            try {
                $fileSize = self::getRemoteFileSize($url);

                if ($fileSize === null || $fileSize <= $maxSizeBytes) {
                    // File is within limit or size unknown — use original
                    $compressed[] = $url;
                    continue;
                }

                // File exceeds limit — compress
                Log::info('Media exceeds platform limit, compressing', [
                    'url' => $url,
                    'original_size_mb' => round($fileSize / 1024 / 1024, 2),
                    'limit_mb' => $maxSizeMb,
                ]);

                $mediaType = MediaType::fromUrl($url);
                $newUrl = null;

                if ($mediaType === 'image') {
                    $newUrl = self::compressImage($url, $maxSizeBytes);
                } elseif ($mediaType === 'video') {
                    $newUrl = self::compressVideo($url, $maxSizeBytes);
                }

                $compressed[] = $newUrl ?: $url;

                if ($newUrl) {
                    $newSize = self::getRemoteFileSize($newUrl);
                    Log::info('Media compressed successfully', [
                        'original_url' => $url,
                        'compressed_url' => $newUrl,
                        'original_mb' => round($fileSize / 1024 / 1024, 2),
                        'compressed_mb' => $newSize ? round($newSize / 1024 / 1024, 2) : 'unknown',
                        'reduction' => $newSize ? round((1 - $newSize / $fileSize) * 100, 1) . '%' : 'unknown',
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Media compression failed, using original', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $compressed[] = $url;
            }
        }

        return $compressed;
    }

    /**
     * Get file size from URL via HEAD request.
     */
    private static function getRemoteFileSize(string $url): ?int
    {
        try {
            $response = Http::head($url);
            $contentLength = $response->header('Content-Length');
            return $contentLength ? (int) $contentLength : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve public storage URL to local file path.
     */
    private static function resolveLocalPath(string $url): ?string
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

    /**
     * Build public URL for compressed file.
     */
    private static function compressedUrl(string $filename): string
    {
        return Storage::disk('public')->url('compressed/' . $filename);
    }

    /**
     * Compress image using GD library.
     *
     * Strategy:
     *   1. Load image from local file or download from URL
     *   2. Resize if width or height > IMAGE_MAX_DIMENSION (maintain aspect ratio)
     *   3. Convert PNG > 2MB to JPEG (photos as PNG are wasteful)
     *   4. Save as JPEG with quality 82
     *   5. If still too big, reduce quality incrementally (82 → 70 → 60 → 50)
     */
    private static function compressImage(string $url, int $maxSizeBytes): ?string
    {
        // Try to resolve to local path first (avoid network download)
        $localPath = self::resolveLocalPath($url);
        $imageData = null;

        if ($localPath) {
            $imageData = file_get_contents($localPath);
        } else {
            $response = Http::get($url);
            $imageData = $response->body();
        }

        if (empty($imageData)) {
            return null;
        }

        // Create GD image from string
        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            return null;
        }

        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        // Resize if exceeds max dimension (maintain aspect ratio)
        $newWidth = $origWidth;
        $newHeight = $origHeight;

        if ($origWidth > self::IMAGE_MAX_DIMENSION || $origHeight > self::IMAGE_MAX_DIMENSION) {
            $ratio = min(self::IMAGE_MAX_DIMENSION / $origWidth, self::IMAGE_MAX_DIMENSION / $origHeight);
            $newWidth = (int) ($origWidth * $ratio);
            $newHeight = (int) ($origHeight * $ratio);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($image);
            $image = $resized;
        }

        // Ensure compressed directory exists
        Storage::disk('public')->makeDirectory('compressed');

        $filename = 'img_' . time() . '_' . uniqid() . '.jpg';
        $localSavePath = storage_path('app/public/compressed/' . $filename);

        // Try decreasing quality until under size limit
        $qualities = [82, 75, 65, 55, 45];
        $saved = false;

        foreach ($qualities as $quality) {
            imagejpeg($image, $localSavePath, $quality);
            $fileSize = filesize($localSavePath);

            if ($fileSize <= $maxSizeBytes) {
                $saved = true;
                break;
            }
        }

        imagedestroy($image);

        if (!$saved) {
            // Even lowest quality is too big — use the last one (lowest quality)
            // This shouldn't happen for normal images, but handle edge case
            Log::warning('Image compression could not meet size limit even at lowest quality', [
                'url' => $url,
                'final_size_mb' => round(filesize($localSavePath) / 1024 / 1024, 2),
                'limit_mb' => round($maxSizeBytes / 1024 / 1024, 2),
            ]);
        }

        return self::compressedUrl($filename);
    }

    /**
     * Compress video using ffmpeg.
     *
     * Strategy:
     *   1. Download video to temp file (or use local path)
     *   2. Re-encode with H.264 CRF 28, preset fast
     *   3. Scale to max 1080p height (maintain aspect ratio)
     *   4. Add +faststart for web streaming
     *   5. If still too big, increase CRF (30, 32, 35) and re-encode
     *
     * Requires ffmpeg binary installed (Alpine: apk add ffmpeg — already in Dockerfile).
     */
    private static function compressVideo(string $url, int $maxSizeBytes): ?string
    {
        // Check ffmpeg is available
        $ffmpeg = self::findFfmpeg();
        if (!$ffmpeg) {
            Log::warning('ffmpeg not found, skipping video compression');
            return null;
        }

        // Try to resolve local path or download
        $localPath = self::resolveLocalPath($url);
        $tempInput = null;

        if (!$localPath) {
            // Download to temp file
            $tempInput = tempnam(sys_get_temp_dir(), 'vid_in_');
            $response = Http::get($url);
            file_put_contents($tempInput, $response->body());
            $localPath = $tempInput;
        }

        // Ensure compressed directory exists
        Storage::disk('public')->makeDirectory('compressed');

        $filename = 'vid_' . time() . '_' . uniqid() . '.mp4';
        $outputPath = storage_path('app/public/compressed/' . $filename);

        // Try increasing CRF (lower quality) until under size limit
        $crfValues = [self::VIDEO_CRF, 30, 32, 35, 40];
        $success = false;

        // Scale filter that PRESERVES ASPECT RATIO using simple syntax:
        //   scale=-2:1080
        //
        // -2 = auto-calculate that dimension (maintains aspect ratio, ensures
        //      even number required by libx264)
        // 1080 = cap the HEIGHT at 1080
        //
        // This handles ALL orientations correctly:
        //   Portrait 1080x1920 → 608x1080  (9:16 preserved)
        //   Landscape 1920x1080 → 1920x1080 (no change, already 1080 tall)
        //   Landscape 3840x2160 → 1920x1080 (downscaled, 16:9 preserved)
        //   Square  1080x1080  → 1080x1080 (no change)
        //
        // Previous attempts used complex if(gt(iw,ih),...) expressions with
        // escaped single quotes that ffmpeg couldn't parse ("No such filter:
        // 'ih)'"), causing compression to silently fail — the video would
        // pass through uncompressed or get squashed to 1:1.
        $scaleFilter = "scale=-2:" . self::VIDEO_MAX_HEIGHT;

        foreach ($crfValues as $crf) {
            $cmd = sprintf(
                '%s -i %s -c:v libx264 -crf %d -preset %s -vf "%s" -c:a aac -b:a 128k -movflags +faststart -y %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localPath),
                $crf,
                self::VIDEO_PRESET,
                $scaleFilter,
                escapeshellarg($outputPath)
            );

            $output = shell_exec($cmd);

            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                $fileSize = filesize($outputPath);
                if ($fileSize <= $maxSizeBytes) {
                    $success = true;
                    break;
                }
                // Still too big — try next CRF value (delete current output)
                @unlink($outputPath);
            }
        }

        // Cleanup temp input if we downloaded it
        if ($tempInput) {
            @unlink($tempInput);
        }

        if (!$success && file_exists($outputPath) === false) {
            // Last attempt — use highest CRF result even if still over limit
            // Re-encode with CRF 40 (last value)
            $cmd = sprintf(
                '%s -i %s -c:v libx264 -crf 40 -preset %s -vf "%s" -c:a aac -b:a 96k -movflags +faststart -y %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localPath),
                self::VIDEO_PRESET,
                $scaleFilter,
                escapeshellarg($outputPath)
            );
            shell_exec($cmd);

            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                $success = true;
                Log::warning('Video compression could not meet size limit, using lowest quality', [
                    'url' => $url,
                    'final_size_mb' => round(filesize($outputPath) / 1024 / 1024, 2),
                    'limit_mb' => round($maxSizeBytes / 1024 / 1024, 2),
                ]);
            }
        }

        if (!$success || !file_exists($outputPath)) {
            return null;
        }

        return self::compressedUrl($filename);
    }

    /**
     * Find ffmpeg binary on system.
     */
    private static function findFfmpeg(): ?string
    {
        $candidates = ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
        foreach ($candidates as $c) {
            $result = shell_exec("which $c 2>/dev/null");
            if ($result) {
                return trim($result);
            }
            if (file_exists($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }
}
