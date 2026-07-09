<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Tags/hashtags array — e.g. ["shorts", "viral", "promosi"]
            // Different platforms handle tags differently:
            //   - Twitter/X: appended to text
            //   - Instagram: appended to text or sent as tags[]
            //   - YouTube: sent as tags[] in snippet
            //   - LinkedIn: appended to text (no native tag field)
            //   - Facebook: appended to text
            //   - Mastodon: sent as hashtags in text
            //   - Buffer: sent in metadata per channel
            $table->json('tags')->nullable()->after('media_urls');

            // First comment — auto-posted as first comment after main post
            // Supported by: Facebook, Instagram, LinkedIn, YouTube (community tab)
            // Not supported by: Twitter, Telegram, Discord, Mastodon, Email
            $table->text('first_comment')->nullable()->after('tags');

            // Alt text for images — accessibility + SEO
            // Supported by: Instagram, Facebook, Mastodon, Twitter
            $table->text('alt_text')->nullable()->after('first_comment');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['tags', 'first_comment', 'alt_text']);
        });
    }
};
