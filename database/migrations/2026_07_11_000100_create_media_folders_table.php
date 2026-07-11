<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * media_folders table — stores virtual folder structure for Media Manager.
 *
 * Each row = one folder. Folders are virtual (no physical directory) —
 * they exist only in DB to organize media in the UI.
 *
 * path: full path like "Promo/Lebaran 2026"
 * parent_path: parent folder path (null = root-level folder)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('path', 500);
            $table->string('parent_path', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'path']);
            $table->index(['user_id', 'parent_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
