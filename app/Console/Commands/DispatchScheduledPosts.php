<?php

namespace App\Console\Commands;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use Illuminate\Console\Command;

class DispatchScheduledPosts extends Command
{
    protected $signature = 'posts:dispatch-scheduled';
    protected $description = 'Find scheduled posts that are due and dispatch publish jobs to the queue.';

    public function handle(): int
    {
        $duePosts = Post::where('status', Post::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->with('socialAccounts')
            ->get();

        $dispatched = 0;
        foreach ($duePosts as $post) {
            $post->update(['status' => Post::STATUS_PUBLISHING]);

            foreach ($post->socialAccounts as $account) {
                PublishPostJob::dispatch($post->id, $account->id);
                $dispatched++;
            }

            $this->info("Dispatched post #{$post->id} to {$post->socialAccounts->count()} account(s)");
        }

        $this->info("Total: {$dispatched} publish jobs dispatched for {$duePosts->count()} post(s).");

        return Command::SUCCESS;
    }
}
