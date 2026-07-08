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

        $driver = Socialite::driver($provider);
        $scopes = config("services.{$provider}.scopes", []);

        // LinkedIn: Socialite adds deprecated r_liteprofile + r_emailaddress by default.
        // Use setScopes() to completely replace default scopes with our custom set.
        if ($provider === 'linkedin') {
            $driver->setScopes($scopes);
            return $driver->redirect();
        }

        // Twitter uses OAuth1 (Socialite\One) which doesn't have scopes().
        // Only call scopes() for OAuth2 providers.
        if (!in_array($provider, ['twitter'])) {
            $driver->scopes($scopes);
        }

        return $driver->redirect();
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

            // Twitter, Pinterest, TikTok, Mastodon: store directly
            // LinkedIn: Socialite tries to fetch email via deprecated API (403).
            // Use manual flow like Postiz: token exchange + userinfo + page picker.
            if ($provider === 'linkedin') {
                $linkedResult = $this->handleLinkedInCallback($request);

                if ($linkedResult['type'] === 'select_page') {
                    // Multiple orgs found — show page picker
                    session([
                        'li_access_token' => $linkedResult['access_token'],
                        'li_refresh_token' => $linkedResult['refresh_token'] ?? null,
                        'li_expires_in' => $linkedResult['expires_in'] ?? null,
                        'li_pages' => $linkedResult['pages'],
                        'li_personal' => $linkedResult['personal'],
                        'oauth_user_id' => $userId,
                    ]);
                    return Inertia::render('SocialAccounts/SelectLinkedinPage', [
                        'pages' => $linkedResult['pages'],
                        'personal' => $linkedResult['personal'],
                    ]);
                }

                // Single option — store directly
                $socialiteUser = $linkedResult['user'];
            } else if ($provider !== 'facebook' && $provider !== 'youtube') {
                $socialiteUser = Socialite::driver($provider)->user();
            }

            if ($provider !== 'facebook' && $provider !== 'youtube') {
                $this->createSocialAccount($userId, $provider, $socialiteUser);
            }

            return redirect()->route('social-accounts.index')
                ->with('message', ucfirst($provider) . ' account connected successfully.');

        } catch (\Exception $e) {
            Log::error("OAuth callback failed for {$provider}: " . $e->getMessage());
            return redirect()->route('social-accounts.index')
                ->with('error', 'Failed to connect ' . ucfirst($provider) . ': ' . $e->getMessage());
        }
    }

    /**
     * Handle LinkedIn OAuth callback — manual flow (no Socialite).
     * Uses OIDC userinfo endpoint (not deprecated email API).
     * Fetches org pages for page selection (like Postiz).
     */
    private function handleLinkedInCallback(Request $request): array
    {
        // Step 1: Exchange code for access token
        $tokenResponse = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $request->get('code'),
            'redirect_uri' => url('/integrations/social/linkedin'),
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ])->json();

        $accessToken = $tokenResponse['access_token'] ?? null;
        $refreshToken = $tokenResponse['refresh_token'] ?? null;
        $expiresIn = $tokenResponse['expires_in'] ?? null;

        if (!$accessToken) {
            throw new \Exception('Failed to get LinkedIn access token: ' . json_encode($tokenResponse));
        }

        // Step 2: Get user info via OIDC userinfo (NOT deprecated /v2/emailAddress)
        $userInfo = Http::withToken($accessToken)
            ->get('https://api.linkedin.com/v2/userinfo')
            ->json();

        $userId = $userInfo['sub'] ?? null;
        $userName = $userInfo['name'] ?? 'LinkedIn User';
        $userPicture = $userInfo['picture'] ?? null;

        if (!$userId) {
            throw new \Exception('Failed to get LinkedIn user info: ' . json_encode($userInfo));
        }

        // Step 3: Create personal account user object
        $personalUser = new \Laravel\Socialite\Two\User();
        $personalUser->id = $userId;
        $personalUser->name = $userName;
        $personalUser->avatar = $userPicture;
        $personalUser->token = $accessToken;
        $personalUser->refreshToken = $refreshToken;
        $personalUser->expiresIn = $expiresIn;

        $personal = [
            'id' => $userId,
            'name' => $userName,
            'picture' => $userPicture,
            'type' => 'personal',
        ];

        // Step 4: Fetch organization pages where user is admin
        $pages = [];
        try {
            $orgsResponse = Http::withToken($accessToken)
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'LinkedIn-Version' => '202601',
                ])
                ->get('https://api.linkedin.com/v2/organizationalEntityAcls', [
                    'q' => 'roleAssignee',
                    'role' => 'ADMINISTRATOR',
                    'projection' => '(elements*(organizationalTarget~(localizedName,vanityName,logoV2(original~:playableStreams))))',
                ])->json();

            if (isset($orgsResponse['elements'])) {
                foreach ($orgsResponse['elements'] as $el) {
                    $org = $el['organizationalTarget~'] ?? null;
                    if ($org) {
                        $pages[] = [
                            'id' => $el['organizationalTarget'],
                            'name' => $org['localizedName'] ?? 'Organization',
                            'vanityName' => $org['vanityName'] ?? null,
                            'logo' => $org['logoV2']['original~']['playableStreams'][0] ?? null,
                            'type' => 'page',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("LinkedIn org fetch failed: " . $e->getMessage());
        }

        Log::info("LinkedIn: user={$userName}, found " . count($pages) . " org page(s)");

        // Step 5: If org pages found → show picker (personal + pages)
        if (count($pages) > 0) {
            return [
                'type' => 'select_page',
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresIn,
                'personal' => $personal,
                'pages' => $pages,
            ];
        }

        // Step 6: No org pages — store personal account only
        return ['type' => 'user', 'user' => $personalUser];
    }

    /**
     * Store selected LinkedIn account (personal or page).
     */
    public function selectLinkedinPage(Request $request)
    {
        $validated = $request->validate([
            'account_type' => 'required|string|in:personal,page',
            'page_id' => 'nullable|string',
        ]);

        $userId = session('oauth_user_id', Auth::id());
        $accessToken = session('li_access_token');
        $refreshToken = session('li_refresh_token');
        $expiresIn = session('li_expires_in');

        if ($validated['account_type'] === 'personal') {
            $personal = session('li_personal');
            SocialAccount::updateOrCreate(
                ['provider' => 'linkedin', 'provider_id' => $personal['id']],
                [
                    'user_id' => $userId,
                    'name' => $personal['name'],
                    'username' => $personal['name'],
                    'avatar' => $personal['picture'],
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                    'is_active' => true,
                ]
            );
            $name = $personal['name'];
        } else {
            $pages = session('li_pages', []);
            $selected = collect($pages)->firstWhere('id', $validated['page_id']);
            if (!$selected) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Invalid page selection.');
            }
            SocialAccount::updateOrCreate(
                ['provider' => 'linkedin', 'provider_id' => $selected['id']],
                [
                    'user_id' => $userId,
                    'name' => $selected['name'],
                    'username' => $selected['vanityName'] ?? $selected['name'],
                    'avatar' => $selected['logo'],
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                    'is_active' => true,
                ]
            );
            $name = $selected['name'];
        }

        session()->forget(['li_access_token', 'li_refresh_token', 'li_expires_in', 'li_pages', 'li_personal', 'oauth_user_id']);

        return redirect()->route('social-accounts.index')
            ->with('message', "LinkedIn '{$name}' connected successfully.");
    }

    /**
     * Handle YouTube OAuth callback — supports personal + brand accounts.
     *
     * Brand accounts need channels?managedByMe=true to be detected.
     * If multiple channels found (personal + brand), return channel list
     * for user selection (similar to Facebook Page selection).
     */
    private function handleYouTubeCallback(Request $request): array
    {
        // Step 1: Try Socialite normal flow (works for personal accounts)
        try {
            $socialiteUser = Socialite::driver('youtube')->user();
        } catch (\Exception $e) {
            Log::warning("YouTube Socialite failed: " . $e->getMessage());
            $socialiteUser = null;
        }

        // Step 2: If Socialite worked, get access token
        if ($socialiteUser) {
            $accessToken = $socialiteUser->token;
            $refreshToken = $socialiteUser->refreshToken ?? null;
            $expiresIn = $socialiteUser->expiresIn ?? null;
        } else {
            // Step 3: Fallback — manual token exchange
            $tokenResponse = Http::post('https://oauth2.googleapis.com/token', [
                'code' => $request->get('code'),
                'client_id' => config('services.youtube.client_id'),
                'client_secret' => config('services.youtube.client_secret'),
                'redirect_uri' => url('/integrations/social/youtube'),
                'grant_type' => 'authorization_code',
            ])->json();

            $accessToken = $tokenResponse['access_token'] ?? null;
            $refreshToken = $tokenResponse['refresh_token'] ?? null;
            $expiresIn = $tokenResponse['expires_in'] ?? null;

            if (!$accessToken) {
                throw new \Exception('Failed to get Google access token');
            }
        }

        // Step 4: Fetch ALL channels (personal + brand/managed)
        $channels = [];

        // Try personal channel (mine=true)
        try {
            $mineResponse = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet,contentDetails',
                    'mine' => 'true',
                ])->json();

            if (isset($mineResponse['items'])) {
                foreach ($mineResponse['items'] as $ch) {
                    $channels[] = [
                        'id' => $ch['id'],
                        'title' => $ch['snippet']['title'] ?? 'YouTube Channel',
                        'thumbnail' => $ch['snippet']['thumbnails']['default']['url'] ?? null,
                        'type' => 'personal',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("YouTube mine=true failed: " . $e->getMessage());
        }

        // Try brand/managed channels (managedByMe=true)
        try {
            $managedResponse = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet,contentDetails',
                    'managedByMe' => 'true',
                    'maxResults' => 50,
                ])->json();

            if (isset($managedResponse['items'])) {
                foreach ($managedResponse['items'] as $ch) {
                    $channels[] = [
                        'id' => $ch['id'],
                        'title' => $ch['snippet']['title'] ?? 'Brand Channel',
                        'thumbnail' => $ch['snippet']['thumbnails']['default']['url'] ?? null,
                        'type' => 'brand',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("YouTube managedByMe=true failed: " . $e->getMessage());
        }

        Log::info("YouTube: found " . count($channels) . " channel(s)");

        // Step 5: If multiple channels → return for selection
        if (count($channels) > 1) {
            return [
                'type' => 'select_channel',
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresIn,
                'channels' => $channels,
            ];
        }

        // Step 6: Single channel or no channel — create user object
        if (count($channels) === 1) {
            $ch = $channels[0];
            $user = new \Laravel\Socialite\Two\User();
            $user->id = $ch['id'];
            $user->name = $ch['title'];
            $user->avatar = $ch['thumbnail'];
            $user->token = $accessToken;
            $user->refreshToken = $refreshToken;
            $user->expiresIn = $expiresIn;
            return ['type' => 'user', 'user' => $user];
        }

        // Step 7: No channels at all — fallback to Google userinfo
        $googleUser = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo')
            ->json();

        if (!isset($googleUser['id'])) {
            throw new \Exception('Could not get YouTube channel or Google user info');
        }

        $user = new \Laravel\Socialite\Two\User();
        $user->id = $googleUser['id'];
        $user->name = $googleUser['name'] ?? $googleUser['email'] ?? 'YouTube User';
        $user->email = $googleUser['email'] ?? null;
        $user->avatar = $googleUser['picture'] ?? null;
        $user->token = $accessToken;
        $user->refreshToken = $refreshToken;
        $user->expiresIn = $expiresIn;

        Log::info("YouTube: no channels found, using Google userinfo: " . ($googleUser['email'] ?? 'unknown'));

        return ['type' => 'user', 'user' => $user];
    }

    /**
     * Store selected YouTube channel as social account.
     */
    public function selectYoutubeChannel(Request $request)
    {
        $validated = $request->validate([
            'channel_id' => 'required|string',
        ]);

        $channels = session('yt_channels', []);
        $selected = collect($channels)->firstWhere('id', $validated['channel_id']);

        if (!$selected) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid channel selection.');
        }

        $userId = session('oauth_user_id', Auth::id());

        SocialAccount::updateOrCreate(
            [
                'provider' => 'youtube',
                'provider_id' => $selected['id'],
            ],
            [
                'user_id' => $userId,
                'name' => $selected['title'],
                'username' => $selected['title'],
                'avatar' => $selected['thumbnail'],
                'access_token' => session('yt_access_token'),
                'refresh_token' => session('yt_refresh_token'),
                'expires_at' => session('yt_expires_in')
                    ? now()->addSeconds(session('yt_expires_in'))
                    : null,
                'is_active' => true,
            ]
        );

        session()->forget(['yt_access_token', 'yt_refresh_token', 'yt_expires_in', 'yt_channels', 'oauth_user_id']);

        return redirect()->route('social-accounts.index')
            ->with('message', "YouTube channel '{$selected['title']}' connected successfully.");
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
