<?php

namespace App\Services;

/**
 * Shared media type detection helper.
 *
 * Used by all publishers to ensure consistent content-type handling across platforms.
 * Detects from URL extension (or MIME type when available).
 *
 * Categories:
 *   - 'image'    : raster/vector images (jpg, png, gif, webp, bmp, svg)
 *   - 'video'    : video files (mp4, mov, webm, mkv, avi, m4v)
 *   - 'document' : anything else (PDF, ZIP, etc.)
 */
class MediaType
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v', 'flv', 'wmv', '3gp'];
    public const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'rtf'];
    // LinkedIn supports: PDF only (max 100MB, max 300 pages)
    // We expose the broader list above for general categorization, but
    // publishers should validate per-platform (LinkedIn = pdf only).

    /**
     * Detect media type from URL (by file extension).
     * Returns 'image' | 'video' | 'document'.
     */
    public static function fromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, self::IMAGE_EXTENSIONS)) {
            return 'image';
        }
        if (in_array($ext, self::VIDEO_EXTENSIONS)) {
            return 'video';
        }
        // Documents (PDF, DOCX, etc.) — treated as 'document' bucket
        // Note:MediaType::fromUrl returns 'document' for ALL non-image/video
        // files (including unknown extensions like .bin). Callers that need
        // to distinguish PDFs specifically should use isPdfUrl().
        return 'document';
    }

    /**
     * Check if URL points to a PDF file specifically.
     * Useful for LinkedIn (which only supports PDF as document type).
     */
    public static function isPdfUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext === 'pdf';
    }

    /**
     * Check if URL points to any document (PDF, DOCX, PPTX, etc).
     */
    public static function isDocumentUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::DOCUMENT_EXTENSIONS);
    }

    /**
     * Detect media type from MIME type string (e.g., 'video/mp4').
     * Returns 'image' | 'video' | 'document'.
     */
    public static function fromMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        return 'document';
    }

    /**
     * Combined detection: try URL first, fall back to MIME.
     * Useful when URL lacks a clear extension (e.g., CDN URLs with query strings).
     */
    public static function detect(string $url, ?string $mime = null): string
    {
        $fromUrl = self::fromUrl($url);
        if ($fromUrl !== 'document') {
            return $fromUrl;
        }
        if ($mime) {
            return self::fromMime($mime);
        }
        return 'document';
    }

    /**
     * Get file extension from URL (lowercased, without dot).
     */
    public static function extension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Categorize an array of URLs. Returns ['images' => [...], 'videos' => [...], 'documents' => [...]].
     */
    public static function categorize(array $urls): array
    {
        $result = ['images' => [], 'videos' => [], 'documents' => []];
        foreach ($urls as $url) {
            $type = self::fromUrl($url);
            if ($type === 'image') {
                $result['images'][] = $url;
            } elseif ($type === 'video') {
                $result['videos'][] = $url;
            } else {
                $result['documents'][] = $url;
            }
        }
        return $result;
    }

    /**
     * Get pixel dimensions {w, h} for a media file.
     * Returns null for non-image/video types or on failure.
     *
     * Image: uses PHP getimagesize() (fast, no external deps)
     * Video: uses ffprobe (already installed in container)
     *
     * @param string $path     Absolute local file path
     * @param string|null $mime MIME type (optional, auto-detected if not provided)
     * @return array|null      {w: int, h: int} or null
     */
    public static function getDimensions(string $path, ?string $mime = null): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        if (!$mime) {
            $mime = mime_content_type($path);
        }

        // Image — getimagesize() supports jpeg/png/gif/webp/bmp
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($path);
            if ($info && isset($info[0], $info[1])) {
                return ['w' => (int) $info[0], 'h' => (int) $info[1]];
            }
            return null;
        }

        // Video — use ffprobe
        if (str_starts_with($mime, 'video/')) {
            $ffprobe = trim((string) shell_exec('which ffprobe 2>/dev/null') ?? '');
            if (!$ffprobe || !file_exists($ffprobe)) {
                return null;
            }
            $cmd = sprintf(
                '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>/dev/null',
                escapeshellarg($ffprobe),
                escapeshellarg($path)
            );
            $output = trim((string) shell_exec($cmd) ?? '');
            if ($output && str_contains($output, ',')) {
                [$w, $h] = explode(',', $output, 2);
                $w = (int) trim($w);
                $h = (int) trim($h);
                if ($w > 0 && $h > 0) {
                    return ['w' => $w, 'h' => $h];
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Compute a human-readable aspect ratio label from width + height.
     *
     * Returns common ratios as their standard labels:
     *   1:1, 16:9, 9:16, 4:3, 3:4, 3:2, 2:3, 21:9, 5:4, 4:5
     * Falls back to GCD-based simplification or decimal ratio.
     *
     * @param int $w  Width in pixels
     * @param int $h  Height in pixels
     * @return string|null  Label like "16:9" or null if invalid
     */
    public static function aspectRatioLabel(int $w, int $h): ?string
    {
        if ($w <= 0 || $h <= 0) return null;

        $ratio = $w / $h;
        $common = [
            '1:1'   => 1.0,
            '16:9'  => 16 / 9,
            '9:16'  => 9 / 16,
            '4:3'   => 4 / 3,
            '3:4'   => 3 / 4,
            '3:2'   => 3 / 2,
            '2:3'   => 2 / 3,
            '21:9'  => 21 / 9,
            '5:4'   => 5 / 4,
            '4:5'   => 4 / 5,
            '2:1'   => 2 / 1,
            '1:2'   => 1 / 2,
        ];

        foreach ($common as $label => $target) {
            if (abs($ratio - $target) / $target < 0.02) {
                return $label;
            }
        }

        // Fallback: GCD-based simplification
        $gcd = function ($a, $b) {
            while ($b != 0) {
                [$a, $b] = [$b, $a % $b];
            }
            return $a;
        };
        $g = $gcd($w, $h);
        $sw = $w / $g;
        $sh = $h / $g;

        if ($sw > 50 || $sh > 50) {
            return number_format($ratio, 1) . ':1';
        }

        return "{$sw}:{$sh}";
    }
}
