<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/**
 * Odoo Carousel Controller — manage carousel slides on warunglakku.com.
 *
 * Provides a management UI for listing, viewing, and deleting carousel
 * slides via the Warung Lakku Carousel REST API.
 *
 * Endpoints:
 *   GET  /odoo-carousel           → list all slides (Inertia page)
 *   DELETE /odoo-carousel/{id}    → delete a slide via API
 */
class OdooCarouselController extends Controller
{
    private const API_BASE = 'https://warunglakku.com';

    /**
     * List all carousel slides.
     */
    public function index(Request $request)
    {
        // Find the user's Odoo Carousel account
        $account = $request->user()
            ->socialAccounts()
            ->where('provider', 'odoo_carousel')
            ->where('is_active', true)
            ->first();

        $slides = [];
        $error = null;
        $connected = false;

        if ($account) {
            $connected = true;
            $response = Http::withToken($account->access_token)
                ->get(self::API_BASE . '/carousel/api/slides');

            if ($response->ok()) {
                $data = $response->json();
                $slides = $data['slides'] ?? [];
            } else {
                $error = "Failed to fetch slides (HTTP {$response->status()}). API key may be invalid.";
            }
        }

        return Inertia::render('OdooCarousel/Index', [
            'slides' => $slides,
            'connected' => $connected,
            'error' => $error,
        ]);
    }

    /**
     * Delete a carousel slide.
     */
    public function destroy(Request $request, int $slideId)
    {
        $account = $request->user()
            ->socialAccounts()
            ->where('provider', 'odoo_carousel')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return back()->with('error', 'Odoo Carousel account not connected.');
        }

        $response = Http::withToken($account->access_token)
            ->delete(self::API_BASE . "/carousel/api/slides/{$slideId}");

        if ($response->ok() || $response->noContent()) {
            return back()->with('message', "Carousel slide #{$slideId} deleted successfully.");
        }

        $errBody = $response->json();
        $errMsg = $errBody['error'] ?? $errBody['message'] ?? $response->body();
        return back()->with('error', "Failed to delete slide #{$slideId}: {$errMsg}");
    }

    /**
     * Toggle slide active status (activate/deactivate).
     */
    public function toggle(Request $request, int $slideId)
    {
        $account = $request->user()
            ->socialAccounts()
            ->where('provider', 'odoo_carousel')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return back()->with('error', 'Odoo Carousel account not connected.');
        }

        // First, get current slide to read its active status
        $getResp = Http::withToken($account->access_token)
            ->get(self::API_BASE . "/carousel/api/slides/{$slideId}");

        if (!$getResp->ok()) {
            return back()->with('error', "Failed to fetch slide #{$slideId} for toggle.");
        }

        $slide = $getResp->json();
        $newActive = !($slide['active'] ?? true);

        // Update via PUT
        $updateResp = Http::withToken($account->access_token)
            ->put(self::API_BASE . "/carousel/api/slides/{$slideId}", [
                'active' => $newActive,
            ]);

        if ($updateResp->ok()) {
            $status = $newActive ? 'activated' : 'deactivated';
            return back()->with('message', "Slide #{$slideId} {$status}.");
        }

        $errBody = $updateResp->json();
        $errMsg = $errBody['error'] ?? $errBody['message'] ?? $updateResp->body();
        return back()->with('error', "Failed to toggle slide #{$slideId}: {$errMsg}");
    }
}
