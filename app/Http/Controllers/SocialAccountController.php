<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SocialAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = $request->user()
            ->socialAccounts()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'provider', 'name', 'username', 'avatar', 'is_active', 'created_at']);

        return Inertia::render('SocialAccounts/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function redirectToProvider(Request $request, string $provider)
    {
        // TODO: Implement OAuth redirect per provider
        // For MVP: stub that will be wired in next iteration
        return back()->with('error', "Provider {$provider} connection not yet implemented. Coming soon.");
    }

    public function handleProviderCallback(Request $request, string $provider)
    {
        // TODO: Implement OAuth callback
        return redirect()->route('social-accounts.index');
    }

    public function destroy(Request $request, int $id)
    {
        $account = $request->user()->socialAccounts()->findOrFail($id);
        $account->delete();

        return back()->with('message', 'Account disconnected successfully.');
    }
}
