<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add folder_path to media table for virtual folder management.
 *
 * folder_path stores a virtual path like "Promo/Lebaran 2026" — it does
 * NOT change the physical file location (files stay in uploads/). It's
 * purely a DB-level grouping mechanism for the Media Manager UI.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('folder_path')->nullable()->default(null)->after('path');
            $table->index(['user_id', 'folder_path']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'folder_path']);
            $table->dropColumn('folder_path');
        });
    }
};
