<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_post_id')->nullable();
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->json('raw_metrics')->nullable(); // full API response for debugging
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'social_account_id']);
            $table->index(['social_account_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
    }
};
