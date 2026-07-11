<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Watermark Service — apply text watermark to images and videos.
 *
 * Used to protect media content before publishing to social platforms.
 * Watermark text (e.g. @warunglakku) is composited onto the media using
 * the Raleway Dots font (Google Fonts).
 *
 * Image watermarking:
 *   - Uses PHP GD (already installed)
 *   - Allocates a translucent color (custom opacity)
 *   - Renders text withimagettftext() using Raleway Dots TTF
 *   - Position: 9-point grid (top-left, top-center, ..., bottom-right)
 *   - Output: watermarked JPEG (quality 90) saved to storage/app/public/watermarked/
 *
 * Video watermarking:
 *   - Uses ffmpeg drawtext filter
 *   - Same font, position, size, opacity mapping
 *   - Re-encodes video with H.264 + watermark burned in
 *   - Output: watermarked MP4 saved to storage/app/public/watermarked/
 *
 * Position mapping (3x3 grid):
 *   top-left     top-center     top-right
 *   middle-left  middle-center  middle-right
 *   bottom-left  bottom-center  bottom-right
 *
 * Font: Raleway Dots by Matt McInerney & Pablo Impallari (OFL license)
 *   https://fonts.google.com/specimen/Raleway+Dots
 *   Stored at: storage/fonts/RalewayDots-Regular.ttf
 *
 * @license Apache-2.0 (implementation, font under OFL)
 */
class WatermarkService
{
    /**
     * Watermark positions (3x3 grid).
     * Maps position key to [x-anchor, y-anchor] for both GD (image) and ffmpeg (video).
     */
    public const POSITIONS = [
        'top-left'      => ['label' => 'Top Left'],
        'top-center'    => ['label' => 'Top Center'],
        'top-right'     => ['label' => 'Top Right'],
        'middle-left'   => ['label' => 'Middle Left'],
        'middle-center' => ['label' => 'Middle Center'],
        'middle-right'  => ['label' => 'Middle Right'],
        'bottom-left'   => ['label' => 'Bottom Left'],
        'bottom-center' => ['label' => 'Bottom Center'],
        'bottom-right'  => ['label' => 'Bottom Right'],
    ];

    /**
     * Default watermark settings.
     */
    public const DEFAULTS = [
        'text' => '@warunglakku',
        'position' => 'bottom-right',
        'font_size' => 24,        // points (image) / height pct relative (video)
        'opacity' => 60,          // 0-100
    ];

    /**
     * Path to Raleway Dots TTF (absolute, in storage/fonts/).
     * Copied to storage/app/public/fonts/ on first use for public access.
     */
    private static function fontPath(): string
    {
        // Stored in storage/fonts/ (not public — accessed server-side only)
        $path = storage_path('fonts/RalewayDots-Regular.ttf');
        if (!file_exists($path)) {
            // Fallback: try to find in storage/app/public/fonts/
            $publicPath = storage_path('app/public/fonts/RalewayDots-Regular.ttf');
            if (file_exists($publicPath)) {
                return $publicPath;
            }
            Log::warning('Watermark font not found: RalewayDots-Regular.ttf');
        }
        return $path;
    }

    /**
     * Apply watermark to a media file. Returns public URL of watermarked file,
     * or null on failure (caller should fall back to original URL).
     *
     * @param string $mediaUrl     Public URL of source media
     * @param array  $settings     {text, position, font_size, opacity}
     * @return string|null         Public URL of watermarked media, or null
     */
    public static function apply(string $mediaUrl, array $settings = []): ?string
    {
        $settings = array_merge(self::DEFAULTS, $settings);

        // Resolve local file path (avoid re-download if file is already local)
        $localPath = self::resolveLocalPath($mediaUrl);
        $tempInput = null;

        if (!$localPath) {
            // Download to temp file
            $tempInput = tempnam(sys_get_temp_dir(), 'wm_in_');
            try {
                $response = Http::get($mediaUrl);
                if (!$response->ok()) {
                    Log::warning('Watermark: failed to download source', ['url' => $mediaUrl]);
                    @unlink($tempInput);
                    return null;
                }
                file_put_contents($tempInput, $response->body());
                $localPath = $tempInput;
            } catch (\Exception $e) {
                Log::warning('Watermark: exception downloading source: ' . $e->getMessage());
                @unlink($tempInput);
                return null;
            }
        }

        // Detect media type from URL or file content
        $mediaType = MediaType::fromUrl($mediaUrl);
        if ($mediaType === 'document') {
            // Try from MIME
            $mime = mime_content_type($localPath);
            $mediaType = MediaType::fromMime($mime);
        }

        $resultUrl = null;
        if ($mediaType === 'image') {
            $resultUrl = self::watermarkImage($localPath, $settings);
        } elseif ($mediaType === 'video') {
            $resultUrl = self::watermarkVideo($localPath, $settings);
        } else {
            Log::warning('Watermark: unsupported media type', ['url' => $mediaUrl, 'type' => $mediaType]);
        }

        // Cleanup temp input
        if ($tempInput) {
            @unlink($tempInput);
        }

        return $resultUrl;
    }

    /**
     * Apply text watermark to an image using GD.
     *
     * @param string $localPath  Absolute path to source image
     * @param array  $settings   {text, position, font_size, opacity}
     * @return string|null       Public URL of watermarked image
     */
    private static function watermarkImage(string $localPath, array $settings): ?string
    {
        $fontPath = self::fontPath();
        if (!file_exists($fontPath)) {
            Log::error('Watermark: font file missing, cannot watermark image');
            return null;
        }

        // Load source image
        $image = self::loadImage($localPath);
        if (!$image) {
            return null;
        }

        $origW = imagesx($image);
        $origH = imagesy($image);

        // Allocate watermark color with alpha
        // opacity 0-100 → alpha 0-127 (GD alpha: 0=opaque, 127=transparent)
        $alpha = (int) (127 * (1 - $settings['opacity'] / 100));
        $color = imagecolorallocatealpha($image, 255, 255, 255, $alpha);

        // Scale font size relative to image dimensions if needed
        // Default font_size is for ~1080px wide image; scale proportionally
        $fontSize = $settings['font_size'];
        $scaledFontSize = (int) ($fontSize * $origW / 1080);
        if ($scaledFontSize < 8) $scaledFontSize = 8;

        // Measure text bounding box
        $bbox = imagettfbbox($scaledFontSize, 0, $fontPath, $settings['text']);
        $textW = $bbox[2] - $bbox[0];
        $textH = $bbox[1] - $bbox[7]; // bbox[1] is bottom, bbox[7] is top

        // Padding from edges (5% of image dimension, min 10px)
        $padX = max(10, (int) ($origW * 0.03));
        $padY = max(10, (int) ($origH * 0.03));

        // Calculate X, Y position based on 3x3 grid
        [$x, $y] = self::calculatePosition(
            $settings['position'],
            $origW, $origH,
            $textW, $textH,
            $bbox[7], $padX, $padY
        );

        // Apply subtle dark shadow behind text for readability on light images
        // (draw black shadow at +1px offset, then white text on top)
        $shadowAlpha = (int) (127 * (1 - min(0.8, $settings['opacity'] / 100) * 0.5));
        $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, $shadowAlpha);
        imagettftext($image, $scaledFontSize, 0, $x + 1, $y + 1, $shadowColor, $fontPath, $settings['text']);

        // Draw main watermark text
        imagettftext($image, $scaledFontSize, 0, $x, $y, $color, $fontPath, $settings['text']);

        // Save watermarked image
        Storage::disk('public')->makeDirectory('watermarked');
        $filename = 'wm_img_' . time() . '_' . uniqid() . '.jpg';
        $outputPath = storage_path('app/public/watermarked/' . $filename);

        imagejpeg($image, $outputPath, 90);
        imagedestroy($image);

        if (!file_exists($outputPath)) {
            return null;
        }

        return Storage::disk('public')->url('watermarked/' . $filename);
    }

    /**
     * Apply text watermark to a video using ffmpeg drawtext filter.
     *
     * @param string $localPath  Absolute path to source video
     * @param array  $settings   {text, position, font_size, opacity}
     * @return string|null       Public URL of watermarked video
     */
    private static function watermarkVideo(string $localPath, array $settings): ?string
    {
        $fontPath = self::fontPath();
        if (!file_exists($fontPath)) {
            Log::error('Watermark: font file missing, cannot watermark video');
            return null;
        }

        $ffmpeg = MediaCompressionService::findFfmpegPublic();
        if (!$ffmpeg) {
            Log::warning('Watermark: ffmpeg not found, cannot watermark video');
            return null;
        }

        Storage::disk('public')->makeDirectory('watermarked');
        $filename = 'wm_vid_' . time() . '_' . uniqid() . '.mp4';
        $outputPath = storage_path('app/public/watermarked/' . $filename);

        // Get video dimensions
        $probeCmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($localPath)
        );
        $dims = trim(shell_exec($probeCmd) ?? '');
        [$vidW, $vidH] = explode(',', $dims) + [0, 0];
        $vidW = (int) $vidW;
        $vidH = (int) $vidH;

        if ($vidW === 0 || $vidH === 0) {
            Log::warning('Watermark: could not detect video dimensions', ['output' => $dims]);
            return null;
        }

        // Scale font size relative to video width
        $fontSize = (int) ($settings['font_size'] * $vidW / 1080);
        if ($fontSize < 10) $fontSize = 10;

        // ffmpeg drawtext position expressions
        // x, y use video dimensions (w, h) and text dimensions (tw, th)
        // Padding: 3% of video width
        $padExpr = 'w*0.03';

        [$xExpr, $yExpr] = self::ffmpegPositionExpr($settings['position'], $padExpr);

        // Convert opacity (0-100) to ffmpeg alpha (0.0-1.0)
        $alpha = number_format($settings['opacity'] / 100, 2);

        // Escape special chars in text for ffmpeg drawtext
        // ffmpeg requires escaping: : \ ' %
        $escapedText = str_replace(['\\', ':', "'", '%'], ['\\\\', '\\:', "\\'", '\\%'], $settings['text']);

        // Build drawtext filter
        // fontcolor=white@0.6 means white at 60% opacity
        $drawtext = sprintf(
            "drawtext=fontfile=%s:text='%s':fontsize=%d:x=%s:y=%s:fontcolor=white@%s",
            escapeshellarg($fontPath),
            $escapedText,
            $fontSize,
            $xExpr,
            $yExpr,
            $alpha
        );

        // Add shadow for readability (similar to image shadow)
        $shadowAlpha = number_format(min(0.4, $settings['opacity'] / 100 * 0.5), 2);
        $drawtextShadow = sprintf(
            ":shadowcolor=black@%s:shadowx=1:shadowy=1",
            $shadowAlpha
        );
        $drawtext .= $drawtextShadow;

        // Re-encode with watermark (H.264 + AAC, preserve audio)
        // -c:a copy would be faster but some formats need re-encoding; use aac to be safe
        $cmd = sprintf(
            '%s -i %s -vf "%s" -c:v libx264 -crf 23 -preset fast -c:a aac -b:a 128k -movflags +faststart -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($localPath),
            $drawtext,
            escapeshellarg($outputPath)
        );

        $output = shell_exec($cmd);

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::warning('Watermark: ffmpeg video watermark failed', [
                'output' => substr($output ?? '', -500),
            ]);
            return null;
        }

        return Storage::disk('public')->url('watermarked/' . $filename);
    }

    /**
     * Calculate X, Y coordinates for watermark text in GD image.
     *
     * @param string $position  Position key (top-left, etc.)
     * @param int    $imgW      Image width
     * @param int    $imgH      Image height
     * @param int    $textW     Text width (from imagettfbbox)
     * @param int    $textH     Text height (from imagettfbbox)
     * @param int    $textTop   Text top offset (bbox[7], usually negative)
     * @param int    $padX      Horizontal padding
     * @param int    $padY      Vertical padding
     * @return array [x, y]  Top-left of text drawing position
     */
    private static function calculatePosition(
        string $position,
        int $imgW, int $imgH,
        int $textW, int $textH,
        int $textTop,
        int $padX, int $padY
    ): array {
        // X position
        $x = match (true) {
            str_contains($position, 'left')   => $padX,
            str_contains($position, 'center') => (int) (($imgW - $textW) / 2),
            str_contains($position, 'right')  => $imgW - $textW - $padX,
            default                            => $padX,
        };

        // Y position (GD y is baseline, not top)
        // imagettftext y = baseline; we need to offset by textH to make y = top
        $baselineOffset = $textH + $textTop; // adjust for font's internal top offset
        $y = match (true) {
            str_starts_with($position, 'top')    => $padY + $textH,
            str_starts_with($position, 'middle') => (int) (($imgH + $textH) / 2),
            str_starts_with($position, 'bottom') => $imgH - $padY,
            default                               => $imgH - $padY,
        };

        return [$x, $y];
    }

    /**
     * Generate ffmpeg drawtext x and y expressions for the given position.
     *
     * ffmpeg expressions use:
     *   w, h       — video width/height
     *   tw, th     — text width/height (auto-calculated by drawtext)
     *   line_height, line_h — text line height
     */
    private static function ffmpegPositionExpr(string $position, string $padExpr): array
    {
        $x = match (true) {
            str_contains($position, 'left')   => $padExpr,
            str_contains($position, 'center') => "(w-tw)/2",
            str_contains($position, 'right')  => "w-tw-{$padExpr}",
            default                            => $padExpr,
        };

        $y = match (true) {
            str_starts_with($position, 'top')    => $padExpr,
            str_starts_with($position, 'middle') => "(h-th)/2",
            str_starts_with($position, 'bottom') => "h-th-{$padExpr}",
            default                               => "h-th-{$padExpr}",
        };

        return [$x, $y];
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
     * Load image from file path using GD.
     */
    private static function loadImage(string $path): ?\GdImage
    {
        if (!file_exists($path)) {
            return null;
        }

        $mime = mime_content_type($path);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png'  => @imagecreatefrompng($path) ?: null,
            'image/gif'  => @imagecreatefromgif($path) ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            'image/bmp'  => @imagecreatefrombmp($path) ?: null,
            default      => null,
        };
    }
}
