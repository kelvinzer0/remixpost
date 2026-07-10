<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp presence samples — one row per presence check.
 *
 * Populated by CheckWhatsAppPresence job. Used to compute activity
 * patterns per JID (which hours of day the contact is typically online)
 * and aggregate across all consented contacts (audience peak hours).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_presence_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consent_id')->constrained('whatsapp_presence_consents')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('jid', 100);
            // 'online' | 'offline' | 'unknown'
            $table->string('status', 20);
            // WhatsApp lastSeen timestamp (when status=offline and lastSeen available)
            $table->timestamp('last_seen_at')->nullable();
            // When we sampled (i.e., when the API call returned)
            $table->timestamp('sampled_at')->useCurrent();
            $table->timestamps();

            // Index for heatmap queries: aggregate by hour-of-day across all JIDs
            // for a given social_account_id. Query pattern:
            //   SELECT HOUR(sampled_at) AS h, COUNT(*) FROM samples
            //   WHERE social_account_id = ? AND status = 'online'
            //   AND sampled_at > NOW() - INTERVAL 30 DAY
            //   GROUP BY h
            $table->index(['social_account_id', 'status', 'sampled_at']);
            $table->index(['consent_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_presence_samples');
    }
};
