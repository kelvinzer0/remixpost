<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

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

    /**
     * Redirect to provider's OAuth consent screen.
     */
    public function redirectToProvider(Request $request, string $provider)
    {
        $allowed = ['twitter', 'facebook', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'mastodon'];
        if (!in_array($provider, $allowed)) {
            return back()->with('error', "Provider {$provider} is not supported.");
        }

        // Store intended user_id in session (for callback)
        session(['oauth_user_id' => $request->user()->id]);

        return Socialite::driver($provider)->scopes(
            config("services.{$provider}.scopes", [])
        )->redirect();
    }

    /**
     * Handle OAuth callback from provider.
     */
    public function handleProviderCallback(Request $request, string $provider)
    {
        $allowed = ['twitter', 'facebook', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'mastodon'];
        if (!in_array($provider, $allowed)) {
            return redirect()->route('social-accounts.index')
                ->with('error', "Provider {$provider} is not supported.");
        }

        if ($request->has('error')) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Authorization was cancelled or failed.');
        }

        try {
            $socialiteUser = Socialite::driver($provider)->user();
            $userId = session('oauth_user_id', Auth::id());

            // For Facebook: user must select a Page to post as.
            // We'll redirect to Page selection after getting user token.
            if ($provider === 'facebook') {
                $pages = $this->getFacebookPages(
                    $socialiteUser->token,
                    config('services.facebook.graph_version', 'v18.0')
                );

                if (empty($pages)) {
                    return redirect()->route('social-accounts.index')
                        ->with('error', 'No Facebook Pages found. You must be an admin of at least one Page.');
                }

                // Store user token in session for Page selection step
                session([
                    'fb_user_token' => $socialiteUser->token,
                    'fb_user_name' => $socialiteUser->getName(),
                    'fb_pages' => $pages,
                ]);

                return Inertia::render('SocialAccounts/SelectFacebookPage', [
                    'pages' => $pages,
                ]);
            }

            // Twitter and LinkedIn: store the account directly
            $this->createSocialAccount($userId, $provider, $socialiteUser);

            return redirect()->route('social-accounts.index')
                ->with('message', ucfirst($provider) . ' account connected successfully.');

        } catch (\Exception $e) {
            Log::error("OAuth callback failed for {$provider}: " . $e->getMessage());
            return redirect()->route('social-accounts.index')
                ->with('error', 'Failed to connect ' . ucfirst($provider) . ': ' . $e->getMessage());
        }
    }

    /**
     * Connect a Telegram channel manually (no OAuth — bot token from .env).
     */
    public function connectTelegram(Request $request)
    {
        $validated = $request->validate([
            'channel_username' => 'required|string|max:255',
        ]);

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return back()->with('error', 'TELEGRAM_TOKEN is not configured in .env');
        }

        $channelUsername = $validated['channel_username'];

        // Verify bot can access the channel by sending a test API call
        try {
            $response = \Illuminate\Support\Facades\Http::get(
                "https://api.telegram.org/bot{$botToken}/getChat",
                ['chat_id' => $channelUsername]
            );

            if (!$response->ok() || !($response['ok'] ?? false)) {
                return back()->with('error', 'Bot cannot access this channel. Make sure the bot is added as admin. Error: ' . ($response['description'] ?? 'Unknown'));
            }

            $chatInfo = $response['result'];

            SocialAccount::updateOrCreate(
                [
                    'provider' => 'telegram',
                    'provider_id' => $channelUsername,
                ],
                [
                    'user_id' => $request->user()->id,
                    'name' => $chatInfo['title'] ?? $channelUsername,
                    'username' => $channelUsername,
                    'avatar' => null,
                    'access_token' => $botToken, // Bot token stored per-account (same for all)
                    'refresh_token' => null,
                    'is_active' => true,
                ]
            );

            return redirect()->route('social-accounts.index')
                ->with('message', "Telegram channel '{$channelUsername}' connected successfully.");

        } catch (\Exception $e) {
            return back()->with('error', 'Failed to connect Telegram: ' . $e->getMessage());
        }
    }

    /**
     * Connect an email recipient manually (no OAuth — SMTP from .env).
     */
    public function connectEmail(Request $request)
    {
        $validated = $request->validate([
            'recipient_email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
        ]);

        SocialAccount::updateOrCreate(
            [
                'provider' => 'email',
                'provider_id' => $validated['recipient_email'],
            ],
            [
                'user_id' => $request->user()->id,
                'name' => $validated['name'] ?: $validated['recipient_email'],
                'username' => $validated['recipient_email'],
                'avatar' => null,
                'access_token' => 'smtp', // Placeholder — actual SMTP creds in config/mail.php
                'refresh_token' => null,
                'is_active' => true,
            ]
        );

        return redirect()->route('social-accounts.index')
            ->with('message', "Email recipient '{$validated['recipient_email']}' connected successfully.");
    }

    /**
     * Store selected Facebook Page as a social account.
     */
    public function selectFacebookPage(Request $request)
    {
        $validated = $request->validate([
            'page_id' => 'required|string',
        ]);

        $pages = session('fb_pages', []);
        $selectedPage = collect($pages)->firstWhere('id', $validated['page_id']);

        if (!$selectedPage) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid Page selection.');
        }

        $userId = session('oauth_user_id', Auth::id());

        SocialAccount::updateOrCreate(
            [
                'provider' => 'facebook',
                'provider_id' => $selectedPage['id'],
            ],
            [
                'user_id' => $userId,
                'name' => $selectedPage['name'],
                'username' => $selectedPage['name'],
                'avatar' => null,
                'access_token' => $selectedPage['access_token'],
                'refresh_token' => null,
                'is_active' => true,
            ]
        );

        session()->forget(['fb_user_token', 'fb_user_name', 'fb_pages', 'oauth_user_id']);

        return redirect()->route('social-accounts.index')
            ->with('message', "Facebook Page '{$selectedPage['name']}' connected successfully.");
    }

    /**
     * Get Instagram Business Account ID from a connected Facebook Page.
     * Called after Facebook Page is selected, to set up Instagram.
     */
    public function connectInstagram(Request $request)
    {
        $request->validate([
            'facebook_account_id' => 'required|exists:social_accounts,id',
        ]);

        $fbAccount = SocialAccount::findOrFail($request->facebook_account_id);
        $this->authorize('update', $fbAccount);

        $graphVersion = config('services.facebook.graph_version', 'v18.0');
        $pageId = $fbAccount->provider_id;
        $pageToken = $fbAccount->access_token;

        // Get IG business account ID from the Page
        $response = Http::get("https://graph.facebook.com/{$graphVersion}/{$pageId}", [
            'fields' => 'instagram_business_account',
            'access_token' => $pageToken,
        ]);

        if (!$response->ok() || !isset($response['instagram_business_account']['id'])) {
            return back()->with('error', 'This Facebook Page is not connected to an Instagram Business account.');
        }

        $igUserId = $response['instagram_business_account']['id'];

        // Get IG profile info
        $igResponse = Http::get("https://graph.facebook.com/{$graphVersion}/{$igUserId}", [
            'fields' => 'username,profile_picture_url,name',
            'access_token' => $pageToken,
        ]);

        $igData = $igResponse->json();

        SocialAccount::updateOrCreate(
            [
                'provider' => 'instagram',
                'provider_id' => $igUserId,
            ],
            [
                'user_id' => $request->user()->id,
                'name' => $igData['name'] ?? $igData['username'] ?? 'Instagram',
                'username' => $igData['username'] ?? null,
                'avatar' => $igData['profile_picture_url'] ?? null,
                'access_token' => $pageToken, // IG uses the Page access token
                'refresh_token' => null,
                'is_active' => true,
            ]
        );

        return redirect()->route('social-accounts.index')
            ->with('message', 'Instagram Business account connected successfully.');
    }

    public function destroy(Request $request, int $id)
    {
        $account = $request->user()->socialAccounts()->findOrFail($id);
        $account->delete();

        return back()->with('message', 'Account disconnected successfully.');
    }

    /**
     * Create or update a social account from Socialite user data.
     */
    private function createSocialAccount(int $userId, string $provider, $socialiteUser): void
    {
        $providerId = $socialiteUser->getId();

        // For LinkedIn, store the URN format (urn:li:person:{id})
        if ($provider === 'linkedin') {
            $providerId = 'urn:li:person:' . $socialiteUser->getId();
        }

        SocialAccount::updateOrCreate(
            [
                'provider' => $provider,
                'provider_id' => $providerId,
            ],
            [
                'user_id' => $userId,
                'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? 'Unknown',
                'username' => $socialiteUser->getNickname() ?? $socialiteUser->getEmail(),
                'avatar' => $socialiteUser->getAvatar(),
                'access_token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken
                    ?? (method_exists($socialiteUser, 'getRefreshToken') ? $socialiteUser->getRefreshToken() : null)
                    ?? (property_exists($socialiteUser, 'refreshToken') ? $socialiteUser->refreshToken : null),
                'expires_at' => $socialiteUser->expiresIn
                    ? now()->addSeconds($socialiteUser->expiresIn)
                    : null,
                'is_active' => true,
            ]
        );
    }

    /**
     * Fetch Facebook Pages the user administers.
     */
    private function getFacebookPages(string $userToken, string $graphVersion): array
    {
        $response = Http::get("https://graph.facebook.com/{$graphVersion}/me/accounts", [
            'access_token' => $userToken,
            'fields' => 'id,name,access_token',
            'limit' => 100,
        ]);

        if (!$response->ok()) {
            return [];
        }

        return $response->json('data', []);
    }
}
