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
}
