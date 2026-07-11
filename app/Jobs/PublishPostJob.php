<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Publishers\PublisherFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    /**
     * Per-job timeout in seconds. Some publishers (notably WhatsApp Story
     * via Evolution API, which uploads media to mmg.whatsapp.net) take
     * well over the Laravel default 60s. Bumping to 5min so media-heavy
     * publishes can complete without hitting TimeoutExceededException.
     */
    public int $timeout = 300;

    public function __construct(
        public int $postId,
        public int $socialAccountId
    ) {}

    public function handle(): void
    {
        $post = Post::find($this->postId);
        $account = SocialAccount::find($this->socialAccountId);

        if (!$post || !$account) {
            Log::warning("PublishPostJob: post or account not found", [
                'post_id' => $this->postId,
                'account_id' => $this->socialAccountId,
            ]);
            return;
        }

        // Skip if post was canceled
        if ($post->status === Post::STATUS_CANCELED) {
            return;
        }

        // Mark as publishing
        $post->update(['status' => Post::STATUS_PUBLISHING]);

        try {
            Log::info("PublishPostJob starting", [
                'post_id' => $post->id,
                'account_id' => $account->id,
                'provider' => $account->provider,
            ]);

            $publisher = PublisherFactory::make($account->provider);

            // Compress media if it exceeds this platform's max size limit.
            // This prevents "file too large" errors (e.g. Telegram 5MB image limit,
            // Discord 25MB attachment limit) by auto-compressing before publish.
            $mediaUrls = $post->media_urls ?? [];
            $platformReq = \App\Services\PlatformRequirements::for($account->provider);
            $maxSizeMb = $platformReq['max_media_size_mb'] ?? null;

            // Apply watermark if enabled on this post.
            // Watermark is applied to each media URL listed in watermark_settings.applied_to
            // before compression and publishing. Watermarked files are saved to
            // storage/app/public/watermarked/ and used in place of the originals.
            $watermarkSettings = $post->watermark_settings ?? [];
            if (!empty($watermarkSettings['enabled']) && !empty($watermarkSettings['applied_to'])) {
                $appliedTo = $watermarkSettings['applied_to'];
                $watermarkedUrls = [];
                foreach ($mediaUrls as $url) {
                    if (in_array($url, $appliedTo, true)) {
                        $watermarkedUrl = \App\Services\WatermarkService::apply($url, $watermarkSettings);
                        if ($watermarkedUrl) {
                            Log::info('Watermark applied to media', [
                                'post_id' => $post->id,
                                'original_url' => $url,
                                'watermarked_url' => $watermarkedUrl,
                            ]);
                            $watermarkedUrls[] = $watermarkedUrl;
                        } else {
                            // Watermark failed — fall back to original (better than failing publish)
                            Log::warning('Watermark failed, using original media', [
                                'post_id' => $post->id,
                                'url' => $url,
                            ]);
                            $watermarkedUrls[] = $url;
                        }
                    } else {
                        $watermarkedUrls[] = $url;
                    }
                }
                $mediaUrls = $watermarkedUrls;
            }

            if ($maxSizeMb && !empty($mediaUrls)) {
                $mediaUrls = \App\Services\MediaCompressionService::compressIfNeeded($mediaUrls, $maxSizeMb);
            }

            $result = $publisher->publish([
                'content' => $post->content,
                'media_urls' => $mediaUrls,
                'tags' => $post->tags ?? [],
                'first_comment' => $post->first_comment,
                'alt_text' => $post->alt_text,
                'account_overrides' => $post->account_overrides ?? [],
                'account_id' => $account->id,
                'scheduled_at' => $post->scheduled_at,
            ], [
                'id' => $account->id,
                'user_id' => $account->user_id,
                'access_token' => $account->access_token,
                'refresh_token' => $account->refresh_token,
                'provider_id' => $account->provider_id,
                'expires_at' => $account->expires_at,
                'metadata' => $account->metadata,
            ]);

            Log::info("PublishPostJob publish result", [
                'post_id' => $post->id,
                'account_id' => $account->id,
                'success' => $result['success'] ?? false,
                'external_id' => $result['external_id'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            // Update pivot table with result
            // Note: even on success, we may attach a warning/info message in
            // failure_reason field (prefixed with [INFO] or [WARNING]) so the
            // user can see post-publish notes (e.g. YouTube Shorts criteria check).
            $note = null;
            if ($result['success']) {
                if (!empty($result['warning'])) {
                    $note = '[WARNING] ' . $result['warning'];
                } elseif (!empty($result['info'])) {
                    $note = '[INFO] ' . $result['info'];
                }
            }

            Log::info("PublishPostJob updating pivot", [
                'post_id' => $post->id,
                'account_id' => $account->id,
                'success' => $result['success'] ?? false,
                'external_id_to_set' => $result['external_id'] ?? null,
                'note_to_set' => $note,
                'error_to_set' => $result['success'] ? null : ($result['error'] ?? 'Unknown error'),
            ]);

            $post->socialAccounts()->updateExistingPivot($account->id, [
                'external_post_id' => $result['external_id'] ?? null,
                'failure_reason' => $result['success']
                    ? $note  // null on clean success, or [WARNING]/[INFO] note
                    : ($result['error'] ?? 'Unknown error'),
                'published_at' => $result['success'] ? now() : null,
            ]);

            // Verify pivot was actually updated
            $post->refresh();
            $verifyPivot = $post->socialAccounts()->wherePivot('social_account_id', $account->id)->first();
            Log::info("PublishPostJob pivot after update", [
                'post_id' => $post->id,
                'account_id' => $account->id,
                'pivot_published_at' => $verifyPivot ? $verifyPivot->pivot->published_at : 'PIVOT_ROW_MISSING',
                'pivot_external_id' => $verifyPivot ? $verifyPivot->pivot->external_post_id : 'PIVOT_ROW_MISSING',
                'pivot_failure_reason' => $verifyPivot ? $verifyPivot->pivot->failure_reason : 'PIVOT_ROW_MISSING',
            ]);

            // Check if all accounts are done
            $this->updatePostStatus($post);

        } catch (\Exception $e) {
            Log::error("PublishPostJob failed", [
                'post_id' => $post->id,
                'account_id' => $account->id ?? null,
                'account' => $account->provider ?? 'unknown',
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $post->socialAccounts()->updateExistingPivot($account->id, [
                    'failure_reason' => $e->getMessage(),
                ]);
            } catch (\Exception $pivotErr) {
                Log::error("PublishPostJob pivot update failed too", [
                    'post_id' => $post->id,
                    'account_id' => $account->id ?? null,
                    'pivot_error' => $pivotErr->getMessage(),
                ]);
            }

            $this->updatePostStatus($post);
        }
    }

    /**
     * Update post status based on all social account pivot results.
     *
     * Edge case: if the catch block also failed (e.g. pivot update threw),
     * some accounts may have NEITHER published_at NOR failure_reason set.
     * In that case doneCount < totalAccounts, so the post stays in
     * 'publishing' forever. To avoid that stuck state, we also count
     * "incomplete" accounts (no published_at, no failure_reason) as
     * failures when the job reached this method — by definition the
     * job ended, so any account that didn't get marked as published
     * must have failed silently.
     */
    private function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $totalAccounts = $post->socialAccounts()->count();

        // Published: any account with published_at set (success — including
        // partial success with [INFO]/[WARNING] notes attached as failure_reason).
        $publishedCount = $post->socialAccounts()
            ->wherePivotNotNull('published_at')
            ->count();

        // Real failures: published_at IS NULL AND failure_reason is NOT NULL
        // AND failure_reason does NOT start with [INFO] or [WARNING]
        $failedCount = $post->socialAccounts()
            ->wherePivotNull('published_at')
            ->wherePivotNotNull('failure_reason')
            ->count();

        // Silent failures: published_at IS NULL AND failure_reason IS NULL.
        // The job ended without updating this pivot — treat as failed so the
        // post doesn't stay stuck in 'publishing'.
        $silentFailCount = $post->socialAccounts()
            ->wherePivotNull('published_at')
            ->wherePivotNull('failure_reason')
            ->count();

        // Mark silent failures with a generic error so the UI shows them
        // as failed instead of stuck 'publishing'.
        if ($silentFailCount > 0) {
            $silentAccountIds = $post->socialAccounts()
                ->wherePivotNull('published_at')
                ->wherePivotNull('failure_reason')
                ->pluck('social_accounts.id');
            foreach ($silentAccountIds as $sid) {
                $post->socialAccounts()->updateExistingPivot($sid, [
                    'failure_reason' => 'Publishing failed (job ended without status)',
                ]);
            }
            $failedCount += $silentFailCount;
        }

        $doneCount = $publishedCount + $failedCount;

        if ($doneCount >= $totalAccounts) {
            if ($publishedCount === $totalAccounts) {
                // All published (some may have [INFO]/[WARNING] notes)
                $post->update([
                    'status' => Post::STATUS_PUBLISHED,
                    'published_at' => now(),
                ]);
            } elseif ($publishedCount > 0) {
                // Partial success
                $post->update([
                    'status' => Post::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'failure_reason' => "Published to {$publishedCount}/{$totalAccounts} accounts",
                ]);
            } else {
                // All failed
                $post->update([
                    'status' => Post::STATUS_FAILED,
                    'failure_reason' => "Failed on all {$totalAccounts} accounts",
                ]);
            }
        }
    }
}
