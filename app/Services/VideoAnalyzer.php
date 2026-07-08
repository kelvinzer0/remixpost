<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Analyze video file metadata using ffprobe (part of ffmpeg suite).
 *
 * Used by:
 *  - YouTubePublisher: validate video meets Shorts criteria (≤60s + vertical aspect ratio)
 *  - Future: thumbnail generation, preview clips, etc.
 *
 * Requires ffprobe binary installed on the system (Alpine: apk add ffmpeg).
 * Falls back gracefully (returns null) if ffprobe unavailable — caller should
 * treat null as "unknown" and proceed without validation.
 */
class VideoAnalyzer
{
    /**
     * Analyze a video file from local path or URL.
     *
     * Returns array with keys:
     *   - duration: float (seconds)
     *   - width: int
     *   - height: int
     *   - aspect_ratio: string (e.g. "9:16", "16:9", "1:1")
     *   - is_vertical: bool (height > width)
     *   - is_horizontal: bool (width > height)
     *   - codec: string (e.g. "h264")
     *   - fps: float
     * Or null if analysis fails.
     */
    public static function analyze(string $pathOrUrl): ?array
    {
        $binary = self::findFfprobe();
        if (!$binary) {
            Log::debug('VideoAnalyzer: ffprobe not installed, skipping analysis');
            return null;
        }

        // For local storage URLs (e.g. https://myapp.com/storage/uploads/x.mp4),
        // try to resolve to local file path first to avoid network round-trip.
        $localPath = self::resolveLocalPath($pathOrUrl);

        $target = $localPath ?? $pathOrUrl;

        $cmd = sprintf(
            '%s -v error -show_format -show_streams -of json %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($target)
        );

        $output = shell_exec($cmd);
        if (!$output) {
            return null;
        }

        $data = json_decode($output, true);
        if (!$data) {
            return null;
        }

        // Find video stream
        $videoStream = null;
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        if (!$videoStream) {
            return null;
        }

        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);
        $duration = (float) ($videoStream['duration'] ?? $data['format']['duration'] ?? 0);
        $codec = $videoStream['codec_name'] ?? 'unknown';
        $aspectRatio = $videoStream['display_aspect_ratio'] ?? self::computeAspectRatio($width, $height);

        // Parse fps from r_frame_rate like "30/1"
        $fps = 0.0;
        if (!empty($videoStream['r_frame_rate'])) {
            $parts = explode('/', $videoStream['r_frame_rate']);
            if (count($parts) === 2 && (int) $parts[1] > 0) {
                $fps = (float) $parts[0] / (float) $parts[1];
            }
        }

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => $aspectRatio,
            'is_vertical' => $height > $width,
            'is_horizontal' => $width > $height,
            'is_square' => $width === $height,
            'codec' => $codec,
            'fps' => $fps,
        ];
    }

    /**
     * Check if a video meets YouTube Shorts criteria.
     * Returns array with 'meets_criteria' bool + 'reasons' array of failure reasons.
     */
    public static function meetsShortsCriteria(?array $analysis): array
    {
        if (!$analysis) {
            return [
                'meets_criteria' => null,  // unknown
                'reasons' => ['Could not analyze video (ffprobe may not be installed)'],
            ];
        }

        $reasons = [];

        // Duration must be ≤ 60 seconds (YouTube Shorts max)
        if ($analysis['duration'] > 60) {
            $reasons[] = sprintf(
                'Duration %.1fs exceeds 60s limit (Shorts max is 60s)',
                $analysis['duration']
            );
        }

        // Aspect ratio: must be 9:16 (vertical) or 1:1 (square)
        // 16:9 horizontal videos CANNOT be Shorts
        if (!$analysis['is_vertical'] && !$analysis['is_square']) {
            $reasons[] = sprintf(
                'Aspect ratio %s (%dx%d) is horizontal — Shorts must be vertical (9:16) or square (1:1)',
                $analysis['aspect_ratio'] ?: 'unknown',
                $analysis['width'],
                $analysis['height']
            );
        }

        return [
            'meets_criteria' => empty($reasons),
            'reasons' => $reasons,
            'analysis' => $analysis,
        ];
    }

    /**
     * Find ffprobe binary on system.
     */
    private static function findFfprobe(): ?string
    {
        $candidates = ['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe'];
        foreach ($candidates as $c) {
            if (is_executable($c) || shell_exec("which $c 2>/dev/null")) {
                return trim(shell_exec("which $c 2>/dev/null") ?: $c);
            }
        }
        return null;
    }

    /**
     * Try to resolve a public storage URL to local file path.
     * E.g. https://myapp.com/storage/uploads/x.mp4 → /app/storage/app/public/uploads/x.mp4
     */
    private static function resolveLocalPath(string $url): ?string
    {
        // Check if URL points to our own storage
        $appUrl = config('app.url');
        if (!str_starts_with($url, $appUrl)) {
            return null;
        }

        // Strip app URL + /storage/ prefix
        $storagePrefix = rtrim($appUrl, '/') . '/storage/';
        if (!str_starts_with($url, $storagePrefix)) {
            return null;
        }

        $relativePath = substr($url, strlen($storagePrefix));
        $localPath = storage_path('app/public/' . $relativePath);

        if (file_exists($localPath)) {
            return $localPath;
        }

        return null;
    }

    /**
     * Compute aspect ratio string from width + height (simplified fraction).
     */
    private static function computeAspectRatio(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return 'unknown';
        }
        $gcd = self::gcd($width, $height);
        $w = intdiv($width, $gcd);
        $h = intdiv($height, $gcd);
        return "{$w}:{$h}";
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b > 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }
        return $a;
    }
}
