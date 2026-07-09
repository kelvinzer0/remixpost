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
            $publisher = PublisherFactory::make($account->provider);

            $result = $publisher->publish([
                'content' => $post->content,
                'media_urls' => $post->media_urls ?? [],
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
            $post->socialAccounts()->updateExistingPivot($account->id, [
                'external_post_id' => $result['external_id'] ?? null,
                'failure_reason' => $result['success']
                    ? $note  // null on clean success, or [WARNING]/[INFO] note
                    : ($result['error'] ?? 'Unknown error'),
                'published_at' => $result['success'] ? now() : null,
            ]);

            // Check if all accounts are done
            $this->updatePostStatus($post);

        } catch (\Exception $e) {
            Log::error("PublishPostJob failed", [
                'post_id' => $post->id,
                'account' => $account->provider,
                'error' => $e->getMessage(),
            ]);

            $post->socialAccounts()->updateExistingPivot($account->id, [
                'failure_reason' => $e->getMessage(),
            ]);

            $this->updatePostStatus($post);
        }
    }

    /**
     * Update post status based on all social account pivot results.
     */
    private function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $totalAccounts = $post->socialAccounts()->count();
        $publishedCount = $post->socialAccounts()
            ->wherePivotNotNull('published_at')
            ->count();
        $failedCount = $post->socialAccounts()
            ->wherePivotNotNull('failure_reason')
            ->count();

        // All done?
        if ($publishedCount + $failedCount === $totalAccounts) {
            if ($publishedCount === $totalAccounts) {
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
                $post->update([
                    'status' => Post::STATUS_FAILED,
                    'failure_reason' => "Failed on all {$totalAccounts} accounts",
                ]);
            }
        }
    }
}
