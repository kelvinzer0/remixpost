<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add watermark_settings column to posts table.
     *
     * Stores per-post watermark configuration as JSON:
     * {
     *   "enabled": true,
     *   "text": "@warunglakku",
     *   "position": "bottom-right",  // 3x3 grid: top-left, top-center, etc.
     *   "font_size": 24,             // points (relative to 1080px width)
     *   "opacity": 60,               // 0-100
     *   "applied_to": [              // which media URLs have watermark applied
     *     "https://automate.../uploads/xxx.jpg",
     *     "https://automate.../uploads/yyy.mp4"
     *   ]
     * }
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('watermark_settings')->nullable()->after('media_urls');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('watermark_settings');
        });
    }
};
