<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Per-account overrides for Buffer channels.
            // JSON keyed by social_account_id:
            // { "25": { "pinterest_board_id": "board123" }, "26": { "instagram_post_type": "reel" } }
            // This lets user pick different board/IG mode per post without reconnecting.
            $table->json('account_overrides')->nullable()->after('alt_text');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('account_overrides');
        });
    }
};
