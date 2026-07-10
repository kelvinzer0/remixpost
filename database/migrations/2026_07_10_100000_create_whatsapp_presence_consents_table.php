<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp presence opt-in consents.
 *
 * Each row = one phone number that has explicitly consented to be tracked
 * for presence (online/offline/lastSeen) by this Remixpost instance.
 *
 * IMPORTANT: This table MUST only contain rows where the contact has
 * given explicit, informed consent. There is no implicit opt-in —
 * admin must record consent_method (manual_verbal, written, qr_scan, etc.)
 * and consent_given_at timestamp. Removing consent (is_active=false)
 * immediately stops further presence checks for that JID.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_presence_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Which WA account (Evolution API instance) is doing the tracking
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            // JID of the tracked contact, e.g. 6281234567890@s.whatsapp.net
            $table->string('jid', 100);
            // Human-readable label (pushName from Evolution API, or admin-entered)
            $table->string('display_name', 200)->nullable();
            $table->string('phone', 30)->nullable();
            // How consent was obtained — manual_verbal | written | qr_scan | self_signup
            $table->string('consent_method', 50)->default('manual_verbal');
            $table->timestamp('consent_given_at')->useCurrent();
            // Set false to immediately stop tracking (effective revocation)
            $table->boolean('is_active')->default(true);
            // Optional: consent expires after this date (for time-bounded studies)
            $table->timestamp('consent_expires_at')->nullable();
            $table->text('notes')->nullable(); // admin notes about consent context
            $table->timestamps();

            // One active consent per (user, social_account, jid) — but allow
            // re-adding after revocation, so unique includes is_active too via
            // partial-index pattern (just use composite unique, conflicts
            // handled by updateOrCreate).
            $table->unique(['user_id', 'social_account_id', 'jid'], 'uniq_wa_presence_consent');
            $table->index(['social_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_presence_consents');
    }
};
