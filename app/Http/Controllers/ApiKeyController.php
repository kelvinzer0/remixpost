<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApiKeyController extends Controller
{
    /**
     * Show API keys management page.
     */
    public function index(Request $request)
    {
        $keys = $request->user()->apiKeys()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return Inertia::render('ApiKeys/Index', [
            'keys' => $keys,
        ]);
    }

    /**
     * Generate a new API key.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $token = ApiKey::generateToken();

        $key = $request->user()->apiKeys()->create([
            'name' => $validated['name'],
            'token' => $token,
        ]);

        return back()->with('message', "API key '{$validated['name']}' created. Copy the token now — it won't be shown again.")
            ->with('new_token', $token);
    }

    /**
     * Delete (revoke) an API key.
     */
    public function destroy(Request $request, int $id)
    {
        $key = $request->user()->apiKeys()->findOrFail($id);
        $name = $key->name;
        $key->delete();

        return back()->with('message', "API key '{$name}' revoked.");
    }
}
