<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // metadata stores provider-specific config in JSON format.
            // Examples:
            //   YouTube:   {"upload_mode": "short"} or {"upload_mode": "video"}
            //   Pinterest: {"board_name": "My Board", "board_privacy": "PUBLIC"}
            //   Telegram:  {"verified_admin": "John Doe"}
            $table->json('metadata')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
