<?php

namespace App\Http\Controllers;

use App\Services\AICaptionService;
use Illuminate\Http\Request;

class AICaptionController extends Controller
{
    /**
     * Generate AI caption suggestions.
     *
     * POST /ai/caption
     * Body:
     *   - prompt: string (optional draft/topic)
     *   - tone: string (casual|professional|promotional|storytelling|humorous|inspirational|informative)
     *   - platforms: array (e.g. ['facebook', 'instagram', 'twitter'])
     *   - target_date: string ISO datetime (when post will be published)
     *   - count: int (1-5, default 3)
     *
     * Returns:
     *   - captions: string[]
     *   - context_used: string (debug)
     *   - error: string (if failed)
     */
    public function generate(Request $request, AICaptionService $aiService)
    {
        $validated = $request->validate([
            'prompt' => 'nullable|string|max:2000',
            'tone' => 'nullable|string|in:casual,professional,promotional,storytelling,humorous,inspirational,informative',
            'platforms' => 'nullable|array|max:10',
            'platforms.*' => 'string|max:50',
            'target_date' => 'nullable|string|max:50',
            'count' => 'nullable|integer|min:1|max:5',
        ]);

        $result = $aiService->generate($validated);

        return response()->json($result);
    }

    /**
     * Get available tones for UI.
     * GET /ai/tones
     */
    public function tones()
    {
        return response()->json([
            'tones' => AICaptionService::getTones(),
        ]);
    }
}
