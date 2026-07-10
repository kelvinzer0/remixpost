<?php

namespace App\Console\Commands;

use App\Jobs\CheckWhatsAppPresence;
use App\Models\WhatsAppPresenceConsent;
use Illuminate\Console\Command;

class DispatchWhatsAppPresenceChecks extends Command
{
    protected $signature = 'whatsapp:presence-check';
    protected $description = 'Dispatch presence check jobs for all active WhatsApp presence consents.';

    public function handle(): int
    {
        $consents = WhatsAppPresenceConsent::active()->get();

        if ($consents->isEmpty()) {
            $this->info("No active WhatsApp presence consents.");
            return Command::SUCCESS;
        }

        $dispatched = 0;
        foreach ($consents as $consent) {
            // Stagger dispatches to avoid hammering Evolution API all at once
            CheckWhatsAppPresence::dispatch($consent->id)->delay(now()->addSeconds($dispatched * 5));
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} presence check job(s).");
        return Command::SUCCESS;
    }
}
