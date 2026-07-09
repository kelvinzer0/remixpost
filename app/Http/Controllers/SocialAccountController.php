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
            ->get(['id', 'provider', 'name', 'username', 'avatar', 'is_active', 'metadata', 'refresh_token', 'created_at']);

        return Inertia::render('SocialAccounts/Index', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * Redirect to provider's OAuth consent screen.
     */
    public function redirectToProvider(Request $request, string $provider)
    {
        $allowed = ['twitter', 'facebook', 'linkedin', 'youtube', 'tiktok', 'pinterest'];
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

        // Pinterest: SocialiteProviders Pinterest provider defaults to ['user_accounts:read']
        // which would silently drop pins:write (required for /v5/media + /v5/pins).
        // Use setScopes() to replace defaults entirely with our config scopes.
        if ($provider === 'pinterest') {
            $driver->setScopes($scopes);
            return $driver->redirect();
        }

        // YouTube (Google): Google only returns a refresh_token on the FIRST
        // authorization. If the user has previously authorized this app with
        // the same scopes, Google silently drops the refresh_token from the
        // response — leaving us unable to refresh the access token when it
        // expires (which happens every hour).
        // Fix: force prompt=consent + access_type=offline on every authorization
        // URL so Google always issues a fresh refresh_token.
        if ($provider === 'youtube') {
            $driver->setScopes($scopes);
            // SocialiteProviders YouTube provider sets access_type=offline by default,
            // but we still need to force prompt=consent via the with() method.
            return $driver->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])->redirect();
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
        $allowed = ['twitter', 'facebook', 'linkedin', 'youtube', 'tiktok', 'pinterest'];
        if (!in_array($provider, $allowed)) {
            return redirect()->route('social-accounts.index')
                ->with('error', "Provider {$provider} is not supported.");
        }

        if ($request->has('error')) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Authorization was cancelled or failed.');
        }

        try {
            $userId = session('oauth_user_id', Auth::id());

            // LinkedIn: use manual flow (no Socialite — it calls deprecated email API)
            if ($provider === 'linkedin') {
                $linkedResult = $this->handleLinkedInCallback($request);

                if ($linkedResult['type'] === 'select_page') {
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

                $socialiteUser = $linkedResult['user'];
                $this->createSocialAccount($userId, $provider, $socialiteUser);
                return redirect()->route('social-accounts.index')
                    ->with('message', ucfirst($provider) . ' account connected successfully.');
            }

            // YouTube: manual flow with channel + upload_mode picker
            if ($provider === 'youtube') {
                $youtubeResult = $this->handleYouTubeCallback($request);

                if ($youtubeResult['type'] === 'select_channel') {
                    session([
                        'yt_access_token' => $youtubeResult['access_token'],
                        'yt_refresh_token' => $youtubeResult['refresh_token'] ?? null,
                        'yt_expires_in' => $youtubeResult['expires_in'] ?? null,
                        'yt_channels' => $youtubeResult['channels'],
                        'oauth_user_id' => $userId,
                    ]);
                    return Inertia::render('SocialAccounts/SelectYoutubeChannel', [
                        'channels' => $youtubeResult['channels'],
                    ]);
                }

                if ($youtubeResult['type'] === 'error') {
                    return redirect()->route('social-accounts.index')
                        ->with('error', $youtubeResult['message']);
                }

                // Legacy fallback (should not happen anymore since we always select_channel)
                $socialiteUser = $youtubeResult['user'] ?? null;
                if ($socialiteUser) {
                    $this->createSocialAccount($userId, $provider, $socialiteUser);
                }
                return redirect()->route('social-accounts.index')
                    ->with('error', 'YouTube connection failed: unexpected state. Please try again.');
            }

            // Facebook, Twitter, Pinterest, TikTok, Mastodon: use Socialite
            $socialiteUser = Socialite::driver($provider)->user();

            // Facebook: user must select a Page to post as.
            if ($provider === 'facebook') {
                $pages = $this->getFacebookPages(
                    $socialiteUser->token,
                    config('services.facebook.graph_version', 'v18.0')
                );

                if (empty($pages)) {
                    return redirect()->route('social-accounts.index')
                        ->with('error', 'No Facebook Pages found. You must be an admin of at least one Page.');
                }

                session([
                    'fb_user_token' => $socialiteUser->token,
                    'fb_user_name' => $socialiteUser->getName(),
                    'fb_pages' => $pages,
                ]);

                return Inertia::render('SocialAccounts/SelectFacebookPage', [
                    'pages' => $pages,
                ]);
            }

            // Pinterest: must select a board to post pins to.
            // Pins in Pinterest API v5 require board_id — we cannot create a pin without one.
            if ($provider === 'pinterest') {
                $boards = $this->getPinterestBoards($socialiteUser->token);

                if (empty($boards)) {
                    return redirect()->route('social-accounts.index')
                        ->with('error', 'No Pinterest boards found. Create at least one board on Pinterest first, then try connecting again.');
                }

                session([
                    'pin_access_token' => $socialiteUser->token,
                    'pin_refresh_token' => $socialiteUser->refreshToken
                        ?? (method_exists($socialiteUser, 'getRefreshToken') ? $socialiteUser->getRefreshToken() : null)
                        ?? (property_exists($socialiteUser, 'refreshToken') ? $socialiteUser->refreshToken : null),
                    'pin_expires_in' => $socialiteUser->expiresIn,
                    'pin_user_name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? 'Pinterest User',
                    'pin_user_id' => $socialiteUser->getId(),
                    'pin_user_avatar' => $socialiteUser->getAvatar(),
                    'pin_boards' => $boards,
                ]);

                return Inertia::render('SocialAccounts/SelectPinterestBoard', [
                    'boards' => $boards,
                ]);
            }

            // Store directly for Twitter, TikTok, Mastodon
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
        $accessToken = null;
        $refreshToken = null;
        $expiresIn = null;

        if ($socialiteUser) {
            $accessToken = $socialiteUser->token;
            $refreshToken = $socialiteUser->refreshToken ?? null;
            $expiresIn = $socialiteUser->expiresIn ?? null;
        }

        // Step 3: Always do a fresh manual token exchange to ensure we get
        // refresh_token. Google's Socialite driver sometimes drops the refresh
        // token if it thinks the user has already authorized the app. Manual
        // exchange is more reliable because we can verify the response directly.
        if (!$accessToken || !$refreshToken) {
            $tokenResponse = Http::post('https://oauth2.googleapis.com/token', [
                'code' => $request->get('code'),
                'client_id' => config('services.youtube.client_id'),
                'client_secret' => config('services.youtube.client_secret'),
                'redirect_uri' => url('/integrations/social/youtube'),
                'grant_type' => 'authorization_code',
            ])->json();

            if (!$accessToken) {
                $accessToken = $tokenResponse['access_token'] ?? null;
            }
            if (!$refreshToken) {
                $refreshToken = $tokenResponse['refresh_token'] ?? null;
            }
            if (!$expiresIn) {
                $expiresIn = $tokenResponse['expires_in'] ?? null;
            }

            if (empty($tokenResponse['refresh_token'])) {
                // Google did NOT return a refresh_token even with prompt=consent.
                // This is rare but happens if the user previously revoked only
                // some scopes. Log it so we can diagnose.
                Log::warning('YouTube token exchange did not return refresh_token', [
                    'has_access_token' => !empty($accessToken),
                    'response_keys' => array_keys($tokenResponse),
                ]);
            }
        }

        if (!$accessToken) {
            throw new \Exception('Failed to get Google access token');
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

        // Step 5: ALWAYS return for channel+mode selection, even if only 1 channel found.
        // The user must pick the upload mode (Video / Shorts) before we can store
        // the account — that's the whole point of the new picker.
        if (count($channels) >= 1) {
            return [
                'type' => 'select_channel',
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresIn,
                'channels' => $channels,
            ];
        }

        // Step 6: No channels found at all — the Google account has no YouTube channel.
        // Return an error type so the caller can show a helpful message.
        return [
            'type' => 'error',
            'message' => 'No YouTube channels found for this Google account. Create a YouTube channel first at https://www.youtube.com/create_channel, then try connecting again.',
        ];
    }

    /**
     * Store selected YouTube channel as social account.
     * User also chooses upload_mode: 'video' (regular) or 'short' (YouTube Shorts).
     * Stored in metadata column so the publisher knows whether to auto-add
     * #shorts hashtag and adjust category.
     */
    public function selectYoutubeChannel(Request $request)
    {
        $validated = $request->validate([
            'channel_id' => 'required|string',
            'upload_mode' => ['required', 'string', 'in:video,short'],
        ]);

        $channels = session('yt_channels', []);
        $selected = collect($channels)->firstWhere('id', $validated['channel_id']);

        if (!$selected) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid channel selection.');
        }

        $uploadMode = $validated['upload_mode'];
        $userId = session('oauth_user_id', Auth::id());

        // Use provider_id with mode suffix so user can connect the same channel
        // in both video + short mode if they want (e.g. "UCxxx:video" and "UCxxx:short").
        // The unique constraint on (provider, provider_id) prevents duplicate connections
        // of the same mode, but allows one of each mode.
        $providerId = $selected['id'] . ':' . $uploadMode;

        $displayName = $selected['title'] . ' (' . ($uploadMode === 'short' ? 'Shorts' : 'Video') . ')';

        SocialAccount::updateOrCreate(
            [
                'provider' => 'youtube',
                'provider_id' => $providerId,
            ],
            [
                'user_id' => $userId,
                'name' => $displayName,
                'username' => $selected['title'],
                'avatar' => $selected['thumbnail'],
                'access_token' => session('yt_access_token'),
                'refresh_token' => session('yt_refresh_token'),
                'expires_at' => session('yt_expires_in')
                    ? now()->addSeconds(session('yt_expires_in'))
                    : null,
                'is_active' => true,
                'metadata' => [
                    'upload_mode' => $uploadMode,
                    'channel_id' => $selected['id'],
                    'channel_title' => $selected['title'],
                    'channel_type' => $selected['type'] ?? 'personal',
                ],
            ]
        );

        session()->forget(['yt_access_token', 'yt_refresh_token', 'yt_expires_in', 'yt_channels', 'oauth_user_id']);

        $modeLabel = $uploadMode === 'short' ? 'Shorts' : 'Video';
        return redirect()->route('social-accounts.index')
            ->with('message', "YouTube channel '{$selected['title']}' connected as {$modeLabel} channel successfully.");
    }

    /**
     * Connect a Telegram channel — Step 1: Initiate verification.
     *
     * Flow:
     *   1. Validate channel_username (e.g., @warunglakku or -1001234567890)
     *   2. Call getChat → verifies bot can access the channel
     *   3. Call getChatMember for the bot itself → verifies bot is ADMINISTRATOR
     *   4. Generate 6-char verification code, store in session
     *   5. Bot sends the code to the channel as a verification message
     *   6. Wait briefly, then delete the message → proves bot has delete permission
     *   7. Return response with the code + bot's @username + deep-link for user to send
     *      the code back to the bot in a PRIVATE chat (proves user is a human admin)
     *
     * The user must then click the deep-link, which opens Telegram with the bot's
     * private chat pre-filled with the code. User taps send → bot receives update
     * → user calls /verify-telegram (step 2) → app captures user_id from update,
     * verifies user_id is in channel's admin list → connection complete.
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

        $channelInput = trim($validated['channel_username']);

        try {
            // Step 1: getChat — verify bot can access the channel
            $getChat = Http::get("https://api.telegram.org/bot{$botToken}/getChat", [
                'chat_id' => $channelInput,
            ]);
            if (!$getChat->ok() || !($getChat['ok'] ?? false)) {
                return back()->with('error', 'Bot cannot access this channel. Make sure the bot is added to the channel as an administrator. Telegram error: ' . ($getChat['description'] ?? 'Unknown'));
            }

            $chatInfo = $getChat['result'];
            $channelId = $chatInfo['id']; // numeric ID, more reliable than @username
            $channelTitle = $chatInfo['title'] ?? $channelInput;
            $channelType = $chatInfo['type'] ?? '';

            if (!in_array($channelType, ['channel', 'supergroup', 'group'])) {
                return back()->with('error', "Expected a channel/group, got type '{$channelType}'. Only channels and groups are supported.");
            }

            // Step 2: getChatMember for the bot — verify bot is ADMINISTRATOR
            $botMe = Http::get("https://api.telegram.org/bot{$botToken}/getMe");
            if (!$botMe->ok() || !($botMe['ok'] ?? false)) {
                return back()->with('error', 'Failed to identify bot: ' . ($botMe['description'] ?? 'Unknown'));
            }
            $botId = $botMe['result']['id'];
            $botUsername = $botMe['result']['username'];

            $getBotMember = Http::get("https://api.telegram.org/bot{$botToken}/getChatMember", [
                'chat_id' => $channelId,
                'user_id' => $botId,
            ]);
            if (!$getBotMember->ok() || !($getBotMember['ok'] ?? false)) {
                return back()->with('error', 'Failed to verify bot membership: ' . ($getBotMember['description'] ?? 'Unknown'));
            }
            $botStatus = $getBotMember['result']['status'] ?? '';
            if ($botStatus !== 'administrator') {
                return back()->with('error', "Bot must be added as ADMINISTRATOR to the channel. Current bot status: '{$botStatus}'. Please promote the bot to admin in channel settings.");
            }

            // Step 3: Generate verification code, store in session
            $code = strtoupper(\Illuminate\Support\Str::random(6));
            session([
                'telegram_verify' => [
                    'channel_id' => $channelId,
                    'channel_username' => $channelInput,
                    'channel_title' => $channelTitle,
                    'code' => $code,
                    'bot_id' => $botId,
                    'bot_username' => $botUsername,
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ],
            ]);

            // Step 4: Bot sends the verification code to the channel as a proof-of-post message
            $sendMsg = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $channelId,
                'text' => "🔐 *Verification Code: `{$code}`*\n\nThis channel is being connected to Remixpost. The code will auto-delete shortly.\n\n_Channel: " . addslashes($channelTitle) . "_",
                'parse_mode' => 'MarkdownV2',
            ]);

            $sentMessageId = null;
            if ($sendMsg->ok() && ($sendMsg['ok'] ?? false)) {
                $sentMessageId = $sendMsg['result']['message_id'] ?? null;
            }

            // Step 5: Delete the verification message after a brief delay
            // (proves bot has delete permission — only admins can delete in channels)
            if ($sentMessageId) {
                sleep(2);
                Http::post("https://api.telegram.org/bot{$botToken}/deleteMessage", [
                    'chat_id' => $channelId,
                    'message_id' => $sentMessageId,
                ]);
            }

            // Step 6: Get bot's current updates offset (so we only look at messages from now on)
            // This prevents replay attacks using old messages.
            $updatesResp = Http::get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                'offset' => -1,
                'limit' => 1,
                'timeout' => 0,
            ]);
            $latestUpdateId = null;
            if ($updatesResp->ok() && ($updatesResp['ok'] ?? false)) {
                $updates = $updatesResp['result'] ?? [];
                if (!empty($updates)) {
                    $latestUpdateId = $updates[count($updates) - 1]['update_id'] ?? null;
                }
            }
            session()->put('telegram_verify.updates_offset', $latestUpdateId ? $latestUpdateId + 1 : 0);

            // Deep-link URL: opens bot's private chat with the code pre-filled
            $botDeepLink = "https://t.me/{$botUsername}?start=" . $code;

            return Inertia::render('SocialAccounts/VerifyTelegram', [
                'channel_title' => $channelTitle,
                'channel_username' => $channelInput,
                'verification_code' => $code,
                'bot_username' => $botUsername,
                'bot_deep_link' => $botDeepLink,
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'Failed to initiate Telegram verification: ' . $e->getMessage());
        }
    }

    /**
     * Connect a Telegram channel — Step 2: Verify the user sent the code to the bot.
     *
     * Polls getUpdates for the bot, looking for a private message containing the code.
     * When found, captures the sender's user_id and verifies it's an admin of the channel.
     */
    public function verifyTelegram(Request $request)
    {
        $session = session('telegram_verify');
        if (!$session) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Verification session expired. Please start again.');
        }

        if (time() > $session['expires_at']) {
            session()->forget('telegram_verify');
            return redirect()->route('social-accounts.index')
                ->with('error', 'Verification code expired (10-minute window). Please start again.');
        }

        $botToken = config('services.telegram.bot_token');
        $code = $session['code'];
        $channelId = $session['channel_id'];
        $channelTitle = $session['channel_title'];
        $channelUsername = $session['channel_username'];
        $offset = $session['updates_offset'] ?? 0;

        try {
            // Poll getUpdates with the stored offset (long-poll 5s)
            $updatesResp = Http::get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                'offset' => $offset,
                'limit' => 50,
                'timeout' => 5,
            ]);

            if (!$updatesResp->ok() || !($updatesResp['ok'] ?? false)) {
                return back()->with('error', 'Failed to fetch bot updates: ' . ($updatesResp['description'] ?? 'Unknown'));
            }

            $updates = $updatesResp['result'] ?? [];
            $newOffset = $offset;

            foreach ($updates as $update) {
                $newOffset = max($newOffset, ($update['update_id'] ?? 0) + 1);

                $msg = $update['message'] ?? null;
                if (!$msg) continue;

                $chat = $msg['chat'] ?? [];
                $chatType = $chat['type'] ?? '';
                $text = trim($msg['text'] ?? '');
                $sender = $msg['from'] ?? [];

                // We only care about private chats (1:1 with bot)
                if ($chatType !== 'private') continue;

                // Strip /start prefix if user used deep-link (e.g., "/start WL7K2P9X")
                $cleanText = $text;
                if (str_starts_with($cleanText, '/start ')) {
                    $cleanText = trim(substr($cleanText, 7));
                } elseif (str_starts_with($cleanText, '/start')) {
                    $cleanText = trim(substr($cleanText, 6));
                }

                // Compare case-insensitively
                if (strcasecmp($cleanText, $code) !== 0) {
                    continue;
                }

                // Code matches! Capture sender's user_id
                $senderUserId = $sender['id'] ?? null;
                $senderName = trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? ''));
                if (empty($senderName)) $senderName = $sender['username'] ?? 'Unknown';

                if (!$senderUserId) continue;

                // Verify the sender is an administrator of the channel
                $adminsResp = Http::get("https://api.telegram.org/bot{$botToken}/getChatAdministrators", [
                    'chat_id' => $channelId,
                ]);

                if (!$adminsResp->ok() || !($adminsResp['ok'] ?? false)) {
                    session()->put('telegram_verify.updates_offset', $newOffset);
                    return back()->with('error', 'Failed to fetch channel administrators: ' . ($adminsResp['description'] ?? 'Unknown'));
                }

                $admins = $adminsResp['result'] ?? [];
                $isAdmin = false;
                foreach ($admins as $admin) {
                    if (($admin['user']['id'] ?? null) === $senderUserId) {
                        $isAdmin = true;
                        break;
                    }
                }

                if (!$isAdmin) {
                    session()->put('telegram_verify.updates_offset', $newOffset);
                    session()->forget('telegram_verify');
                    return redirect()->route('social-accounts.index')
                        ->with('error', "Verification failed: '{$senderName}' is not an administrator of channel '{$channelTitle}'. Only channel admins can connect it.");
                }

                // Success! Save the SocialAccount
                SocialAccount::updateOrCreate(
                    [
                        'provider' => 'telegram',
                        'provider_id' => (string)$channelId,
                    ],
                    [
                        'user_id' => $request->user()->id,
                        'name' => $channelTitle,
                        'username' => $channelUsername,
                        'avatar' => null,
                        'access_token' => $botToken,
                        'refresh_token' => null,
                        'is_active' => true,
                    ]
                );

                session()->forget('telegram_verify');

                return redirect()->route('social-accounts.index')
                    ->with('message', "Telegram channel '{$channelTitle}' verified and connected successfully! Verified admin: {$senderName}.");

                // Acknowledge the update so it doesn't come back next poll
                Http::get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                    'offset' => $newOffset,
                    'limit' => 1,
                    'timeout' => 0,
                ]);
            }

            // Code not found yet — update offset and ask user to try again
            session()->put('telegram_verify.updates_offset', $newOffset);

            return Inertia::render('SocialAccounts/VerifyTelegram', [
                'channel_title' => $channelTitle,
                'channel_username' => $channelUsername,
                'verification_code' => $code,
                'bot_username' => $session['bot_username'],
                'bot_deep_link' => "https://t.me/{$session['bot_username']}?start=" . $code,
                'expires_at' => now()->addSeconds($session['expires_at'] - time())->toIso8601String(),
                'waiting' => true,
                'message' => 'Code not received yet. Make sure you sent the code to @' . $session['bot_username'] . ' in a private chat (not in the channel). Click Verify again after sending.',
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'Verification failed: ' . $e->getMessage());
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

    /**
     * Fetch Pinterest boards the user owns.
     * API: GET https://api.pinterest.com/v5/boards
     * Requires scope: boards:read
     */
    private function getPinterestBoards(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://api.pinterest.com/v5/boards', [
                'page_size' => 100,
            ]);

        if (!$response->ok()) {
            Log::error('Failed to fetch Pinterest boards', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $boards = $response->json('items', []);
        // Normalize: only return id, name, description, privacy
        return array_map(function ($b) {
            return [
                'id' => $b['id'] ?? null,
                'name' => $b['name'] ?? 'Untitled board',
                'description' => $b['description'] ?? '',
                'privacy' => $b['privacy'] ?? 'PUBLIC',
            ];
        }, $boards);
    }

    /**
     * Handle Pinterest board selection after OAuth.
     * Stores the board_id as provider_id (PinterestPublisher needs board_id to create pins).
     */
    public function selectPinterestBoard(Request $request)
    {
        $validated = $request->validate([
            'board_id' => 'required|string',
        ]);

        $boards = session('pin_boards', []);
        $selected = collect($boards)->firstWhere('id', $validated['board_id']);

        if (!$selected) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid board selection.');
        }

        $userId = session('oauth_user_id', Auth::id());
        $accessToken = session('pin_access_token');
        $refreshToken = session('pin_refresh_token');
        $expiresIn = session('pin_expires_in');

        SocialAccount::updateOrCreate(
            [
                'provider' => 'pinterest',
                'provider_id' => $selected['id'], // board_id — required by /v5/pins
            ],
            [
                'user_id' => $userId,
                'name' => session('pin_user_name') . ' → ' . $selected['name'],
                'username' => $selected['name'],
                'avatar' => session('pin_user_avatar'),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'is_active' => true,
            ]
        );

        session()->forget([
            'pin_access_token', 'pin_refresh_token', 'pin_expires_in',
            'pin_user_name', 'pin_user_id', 'pin_user_avatar',
            'pin_boards', 'oauth_user_id',
        ]);

        return redirect()->route('social-accounts.index')
            ->with('message', "Pinterest board '{$selected['name']}' connected successfully.");
    }

    /**
     * Connect a Mastodon account — Step 1: Register OAuth app on the instance.
     *
     * Mastodon is federated — each instance (mastodon.social, mas.to, etc)
     * has its own OAuth app registration. Rather than forcing the user to
     * manually create an app on their instance, we auto-register one via
     * POST /api/v1/apps. The returned client_id + client_secret are stored
     * in session (not in DB, since they're instance-wide not user-specific),
     * and user is redirected to the instance's authorize endpoint.
     *
     * Required input: instance_url (e.g. https://mastodon.social)
     */
    public function connectMastodon(Request $request)
    {
        $validated = $request->validate([
            'instance_url' => 'required|string|url|max:255',
        ]);

        $instanceUrl = rtrim($validated['instance_url'], '/');
        $userId = $request->user()->id;

        // Validate URL is reachable + is a Mastodon instance
        try {
            $registerResponse = Http::post("{$instanceUrl}/api/v1/apps", [
                'client_name' => config('app.name', 'Remixpost'),
                'redirect_uris' => url('/integrations/social/mastodon'),
                'scopes' => 'read write',
                'website' => config('app.url'),
            ]);

            if (!$registerResponse->ok()) {
                return back()->with('error', "Failed to register app on {$instanceUrl}. Make sure it's a Mastodon instance. Response: " . $registerResponse->body());
            }

            $appData = $registerResponse->json();
            $clientId = $appData['client_id'] ?? null;
            $clientSecret = $appData['client_secret'] ?? null;

            if (!$clientId || !$clientSecret) {
                return back()->with('error', "Instance did not return client_id/client_secret. Response: " . json_encode($appData));
            }
        } catch (\Exception $e) {
            return back()->with('error', "Cannot reach {$instanceUrl}: " . $e->getMessage());
        }

        // Store in session for callback
        session([
            'mastodon_instance' => $instanceUrl,
            'mastodon_client_id' => $clientId,
            'mastodon_client_secret' => $clientSecret,
            'oauth_user_id' => $userId,
        ]);

        // Build authorize URL
        $state = \Illuminate\Support\Str::random(32);
        session(['mastodon_state' => $state]);

        $authorizeUrl = "{$instanceUrl}/oauth/authorize?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => url('/integrations/social/mastodon'),
            'response_type' => 'code',
            'scope' => 'read write',
            'state' => $state,
        ]);

        // IMPORTANT: do NOT use redirect($authorizeUrl) here.
        // The form was submitted via Inertia's useForm().post() which sends an XHR.
        // A redirect() response would cause Inertia to follow the redirect via XHR,
        // which triggers CORS blocking because mastodon.social doesn't allow our origin.
        // Inertia::location() tells the frontend to do a full page navigation
        // (window.location.href = url) instead of an XHR visit.
        return Inertia::location($authorizeUrl);
    }

    /**
     * Mastodon OAuth callback handler — NOT routed via handleProviderCallback
     * because Mastodon is no longer in the $allowed list (we removed it from
     * Socialite). This method is called directly by a dedicated route.
     *
     * Exchanges authorization code for access token, fetches user account
     * info, stores SocialAccount with instance_url in metadata.
     */
    public function handleMastodonCallback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Mastodon authorization was cancelled: ' . $request->get('error_description', $request->get('error')));
        }

        $instanceUrl = session('mastodon_instance');
        $clientId = session('mastodon_client_id');
        $clientSecret = session('mastodon_client_secret');
        $expectedState = session('mastodon_state');
        $userId = session('oauth_user_id', $request->user() ? $request->user()->id : null);

        if (!$instanceUrl || !$clientId || !$clientSecret) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Mastodon session expired. Please try connecting again.');
        }

        // Validate state to prevent CSRF
        $state = $request->get('state');
        if (!$state || $state !== $expectedState) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid state parameter. Please try connecting again.');
        }

        $code = $request->get('code');
        if (!$code) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'No authorization code received from Mastodon.');
        }

        // Exchange code for access token
        try {
            $tokenResponse = Http::asForm()->post("{$instanceUrl}/oauth/token", [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => url('/integrations/social/mastodon'),
                'code' => $code,
                'scope' => 'read write',
            ]);

            if (!$tokenResponse->ok()) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Failed to exchange code for token: ' . $tokenResponse->body());
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Mastodon did not return access token.');
            }
        } catch (\Exception $e) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Token exchange failed: ' . $e->getMessage());
        }

        // Fetch user account info
        try {
            $verifyResponse = Http::withToken($accessToken)
                ->get("{$instanceUrl}/api/v1/accounts/verify_credentials");

            if (!$verifyResponse->ok()) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Failed to verify Mastodon credentials: ' . $verifyResponse->body());
            }

            $account = $verifyResponse->json();
            $accountId = (string) ($account['id'] ?? '');
            $username = $account['username'] ?? 'unknown';
            $displayName = $account['display_name'] ?? $username;
            $avatar = $account['avatar'] ?? null;
            $fullHandle = "@{$username}@" . parse_url($instanceUrl, PHP_URL_HOST);
        } catch (\Exception $e) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Failed to fetch Mastodon account info: ' . $e->getMessage());
        }

        if (!$accountId) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Mastodon did not return account ID.');
        }

        // Store SocialAccount with instance_url + client_id/secret in metadata.
        // We store client_id/secret in metadata so future token refreshes can
        // use them (Mastodon tokens don't expire by default, but we keep them
        // for safety).
        SocialAccount::updateOrCreate(
            [
                'provider' => 'mastodon',
                'provider_id' => "{$instanceUrl}:{$accountId}",
            ],
            [
                'user_id' => $userId,
                'name' => $displayName,
                'username' => $fullHandle,
                'avatar' => $avatar,
                'access_token' => $accessToken,
                'refresh_token' => null,
                'expires_at' => null, // Mastodon tokens don't expire by default
                'is_active' => true,
                'metadata' => [
                    'instance_url' => $instanceUrl,
                    'account_id' => $accountId,
                    'username' => $username,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]
        );

        // Clear session
        session()->forget([
            'mastodon_instance', 'mastodon_client_id', 'mastodon_client_secret',
            'mastodon_state', 'oauth_user_id',
        ]);

        return redirect()->route('social-accounts.index')
            ->with('message', "Mastodon account '{$fullHandle}' connected successfully.");
    }

    /**
     * Connect a Discord channel via webhook URL.
     *
     * Discord doesn't need OAuth for posting — webhooks are the simplest
     * way to send messages to a channel programmatically. User creates a
     * webhook in Discord channel settings, pastes the URL here.
     *
     * Setup steps for user:
     *   1. In Discord: Channel Settings → Integrations → Webhooks → New Webhook
     *   2. Customize name + avatar (optional)
     *   3. Copy Webhook URL
     *   4. Paste below
     *
     * We validate the webhook URL by fetching its metadata via
     * GET {webhook_url} — returns { id, name, channel_id, guild_id, avatar }.
     * If validation fails, we reject the connection.
     */
    public function connectDiscord(Request $request)
    {
        $validated = $request->validate([
            'webhook_url' => 'required|string|url|max:500',
            'display_name' => 'nullable|string|max:100',
        ]);

        $webhookUrl = $validated['webhook_url'];
        $displayName = $validated['display_name'] ?? '';

        // Validate URL format
        if (!preg_match('#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', $webhookUrl)) {
            return back()->with('error', 'Invalid Discord webhook URL. Expected format: https://discord.com/api/webhooks/{id}/{token}');
        }

        // Fetch webhook metadata to validate it's a real, working webhook.
        // Use retry logic because Discord API or DNS resolution can be flaky
        // transiently — first request may timeout, second succeeds.
        $maxRetries = 3;
        $response = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(30)->connectTimeout(15)->get($webhookUrl);

                if ($response->ok()) {
                    break; // success, exit retry loop
                }

                $status = $response->status();
                $body = $response->body();
                if ($status === 401 || $status === 403) {
                    return back()->with('error', 'Webhook URL is invalid or token is wrong. Copy the full URL from Discord channel settings.');
                } elseif ($status === 404) {
                    return back()->with('error', 'Webhook not found — it may have been deleted. Create a new one in Discord.');
                }
                return back()->with('error', "Failed to validate webhook (HTTP {$status}): {$body}");
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastError = $e->getMessage();
                \Illuminate\Support\Facades\Log::warning("Discord webhook validation attempt {$attempt}/{$maxRetries} failed", [
                    'error' => $lastError,
                ]);
                if ($attempt < $maxRetries) {
                    sleep(2); // wait 2s before retry
                }
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                if ($attempt < $maxRetries) {
                    sleep(2);
                }
            }
        }

        if (!$response || !$response->ok()) {
            return back()->with('error', "Cannot reach Discord after {$maxRetries} attempts. Last error: {$lastError}. Please try again — this is usually a transient network issue. If it persists, check your server's internet connection and DNS resolution for discord.com.");
        }

        try {
            $webhook = $response->json();
            $webhookId = (string) ($webhook['id'] ?? '');
            $webhookName = $webhook['name'] ?? 'Discord Webhook';
            $channelId = (string) ($webhook['channel_id'] ?? '');
            $guildId = (string) ($webhook['guild_id'] ?? '');
            $avatarHash = $webhook['avatar'] ?? null;

            if (!$webhookId) {
                return back()->with('error', 'Webhook response did not include ID. Invalid webhook?');
            }
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to parse Discord webhook response: ' . $e->getMessage());
        }

        // Build display name
        $name = trim($displayName) ?: $webhookName;
        $avatar = $avatarHash ? "https://cdn.discordapp.com/avatars/{$webhookId}/{$avatarHash}.png" : null;

        // Store SocialAccount. provider_id is the full webhook URL (so we can POST to it).
        // We also store webhook metadata (id, channel_id, guild_id, name) for reference.
        SocialAccount::updateOrCreate(
            [
                'provider' => 'discord',
                'provider_id' => $webhookUrl,
            ],
            [
                'user_id' => $request->user()->id,
                'name' => $name,
                'username' => $webhookName,
                'avatar' => $avatar,
                'access_token' => 'webhook', // Placeholder — webhook URL itself is the credential
                'refresh_token' => null,
                'expires_at' => null, // Webhooks don't expire
                'is_active' => true,
                'metadata' => [
                    'webhook_id' => $webhookId,
                    'webhook_name' => $webhookName,
                    'channel_id' => $channelId,
                    'guild_id' => $guildId,
                    'connection_type' => 'webhook',
                ],
            ]
        );

        return redirect()->route('social-accounts.index')
            ->with('message', "Discord webhook '{$name}' connected successfully.");
    }

    /**
     * Connect a Buffer account — Step 1: Initiate OAuth 2.0 + PKCE flow.
     *
     * Buffer uses OAuth 2.0 with mandatory PKCE for ALL clients (public
     * clients don't even have a client_secret — PKCE alone authenticates).
     *
     * Flow:
     *   1. Generate code_verifier (random 32 bytes, base64url)
     *   2. Generate code_challenge = base64url(SHA256(code_verifier))
     *   3. Store code_verifier + state in session
     *   4. Redirect user to https://auth.buffer.com/auth?client_id=...
     *      &code_challenge=...&code_challenge_method=S256&prompt=consent
     *   5. User authorizes → Buffer redirects back with ?code=...
     *   6. handleBufferCallback() exchanges code + code_verifier for tokens
     */
    public function connectBuffer(Request $request)
    {
        $clientId = config('services.buffer.client_id');
        if (!$clientId) {
            return back()->with('error', 'BUFFER_CLIENT_ID is not configured in .env');
        }

        // Generate PKCE verifier + challenge (per Buffer docs PHP example)
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = \Illuminate\Support\Str::random(32);

        session([
            'buffer_code_verifier' => $codeVerifier,
            'buffer_state' => $state,
            'oauth_user_id' => $request->user()->id,
        ]);

        $scopes = implode(' ', config('services.buffer.scopes'));
        $redirectUri = url(config('services.buffer.redirect'));
        $authUrl = config('services.buffer.auth_url') . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopes,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'consent', // force consent so we always get a fresh refresh_token
        ]);

        // Use Inertia::location to do full page navigation (avoid CORS — same fix as Mastodon)
        return Inertia::location($authUrl);
    }

    /**
     * Buffer OAuth callback — Step 2: Exchange code for tokens, fetch orgs.
     *
     * Buffer returns access_token + refresh_token + expires_in.
     * Refresh tokens are SINGLE-USE — store them carefully, never reuse.
     *
     * After token exchange, query account { organizations { id name } }
     * to get list of Buffer organizations (workspaces) the user has.
     * Render SelectBufferOrganization.vue to let user pick which org.
     */
    public function handleBufferCallback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Buffer authorization failed: ' . $request->get('error_description', $request->get('error')));
        }

        $code = $request->get('code');
        $state = $request->get('state');
        $expectedState = session('buffer_state');
        $codeVerifier = session('buffer_code_verifier');
        $userId = session('oauth_user_id', $request->user() ? $request->user()->id : null);

        if (!$code || !$state || !$codeVerifier) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Buffer callback missing required parameters.');
        }

        if ($state !== $expectedState) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid state parameter. Please try connecting again.');
        }

        // Exchange code for tokens
        $clientId = config('services.buffer.client_id');
        $tokenUrl = config('services.buffer.token_url');
        $redirectUri = url(config('services.buffer.redirect'));

        try {
            $tokenResponse = Http::asForm()->post($tokenUrl, [
                'client_id' => $clientId,
                // Public client: no client_secret — PKCE code_verifier authenticates
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

            if (!$tokenResponse->ok()) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Failed to exchange Buffer code for token: ' . $tokenResponse->body());
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 3600;

            if (!$accessToken || !$refreshToken) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Buffer did not return access_token or refresh_token.');
            }
        } catch (\Exception $e) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Buffer token exchange failed: ' . $e->getMessage());
        }

        // Fetch user account + organizations via GraphQL
        try {
            $accountQuery = 'query {
  account {
    id
    email
    name
    avatar
    organizations { id name }
  }
}';
            $accountResponse = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), ['query' => $accountQuery]);

            $accountBody = $accountResponse->json();
            if (isset($accountBody['errors']) && !empty($accountBody['errors'])) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Failed to fetch Buffer account: ' . ($accountBody['errors'][0]['message'] ?? 'Unknown'));
            }

            $account = $accountBody['data']['account'] ?? null;
            if (!$account) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Buffer did not return account info.');
            }

            $organizations = $account['organizations'] ?? [];
            if (empty($organizations)) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'No Buffer organizations found. Create an organization in Buffer first.');
            }
        } catch (\Exception $e) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Failed to fetch Buffer account: ' . $e->getMessage());
        }

        // Store tokens + account info in session for next step
        session([
            'buffer_access_token' => $accessToken,
            'buffer_refresh_token' => $refreshToken,
            'buffer_expires_in' => $expiresIn,
            'buffer_account' => $account,
            'buffer_organizations' => $organizations,
        ]);

        // Render org picker (single org → skip to channel picker)
        if (count($organizations) === 1) {
            return $this->showBufferChannelPicker($organizations[0]);
        }

        return Inertia::render('SocialAccounts/SelectBufferOrganization', [
            'organizations' => $organizations,
            'account' => [
                'name' => $account['name'] ?? $account['email'] ?? 'Buffer User',
                'email' => $account['email'] ?? null,
                'avatar' => $account['avatar'] ?? null,
            ],
        ]);
    }

    /**
     * Step 3 (multi-org): User selected an organization. Show channel picker.
     */
    public function selectBufferOrganization(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|string',
        ]);

        $organizations = session('buffer_organizations', []);
        $selected = collect($organizations)->firstWhere('id', $validated['organization_id']);

        if (!$selected) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Invalid Buffer organization selection.');
        }

        return $this->showBufferChannelPicker($selected);
    }

    /**
     * Internal: fetch channels for org, render channel picker (multi-select).
     * User can connect multiple channels at once — each becomes a
     * separate SocialAccount row (provider='buffer', provider_id=channel_id).
     *
     * Returns either Inertia\Response (success) or RedirectResponse (error).
     */
    private function showBufferChannelPicker(array $organization)
    {
        $accessToken = session('buffer_access_token');
        $orgId = $organization['id'];

        // Fetch channels (Buffer calls them "channels", formerly "profiles")
        $channelsQuery = 'query GetChannels($orgId: OrganizationId!) {
  channels(input: { organizationId: $orgId, filter: { isLocked: false } }) {
    id
    name
    displayName
    service
    avatar
    isQueuePaused
    isDisconnected
  }
}';
        try {
            $response = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), [
                    'query' => $channelsQuery,
                    'variables' => ['orgId' => $orgId],
                ]);

            $body = $response->json();
            if (isset($body['errors']) && !empty($body['errors'])) {
                return redirect()->route('social-accounts.index')
                    ->with('error', 'Failed to fetch Buffer channels: ' . ($body['errors'][0]['message'] ?? 'Unknown'));
            }

            $channels = $body['data']['channels'] ?? [];
        } catch (\Exception $e) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Failed to fetch Buffer channels: ' . $e->getMessage());
        }

        // Filter out disconnected channels
        $channels = array_filter($channels, fn($c) => empty($c['isDisconnected']));

        return Inertia::render('SocialAccounts/SelectBufferChannel', [
            'channels' => array_values($channels),
            'organization' => $organization,
        ]);
    }

    /**
     * Step 4: User selected channels. Store each as SocialAccount.
     * Multi-select — user can connect multiple channels (FB + IG + X + LinkedIn) at once.
     * Also stores per-channel config:
     *   - Pinterest: pinterest_board_id (selected from board picker)
     *   - Instagram: instagram_post_type (post/reel/story)
     */
    public function selectBufferChannel(Request $request)
    {
        $validated = $request->validate([
            'channel_ids' => 'required|array|min:1',
            'channel_ids.*' => 'required|string',
            'channel_configs' => 'nullable|array',
            'channel_configs.*.pinterest_board_id' => 'nullable|string',
            'channel_configs.*.instagram_post_type' => 'nullable|string|in:post,reel,story',
        ]);

        $accessToken = session('buffer_access_token');
        $refreshToken = session('buffer_refresh_token');
        $expiresIn = session('buffer_expires_in');
        $account = session('buffer_account');
        $organizations = session('buffer_organizations', []);
        $userId = session('oauth_user_id', $request->user() ? $request->user()->id : null);

        if (!$accessToken || !$refreshToken || !$account) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Buffer session expired. Please try connecting again.');
        }

        $orgId = count($organizations) === 1 ? $organizations[0]['id'] : ($organizations[0]['id'] ?? null);

        $channelsQuery = 'query GetChannels($orgId: OrganizationId!) {
  channels(input: { organizationId: $orgId, filter: { isLocked: false } }) {
    id name displayName service avatar isDisconnected
  }
}';
        try {
            $response = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), [
                    'query' => $channelsQuery,
                    'variables' => ['orgId' => $orgId],
                ]);
            $allChannels = $response->json('data.channels', []);
        } catch (\Exception $e) {
            return redirect()->route('social-accounts.index')
                ->with('error', 'Failed to fetch channels for storage: ' . $e->getMessage());
        }

        $channelConfigs = $validated['channel_configs'] ?? [];
        $connectedCount = 0;
        $connectedNames = [];
        foreach ($validated['channel_ids'] as $channelId) {
            $ch = collect($allChannels)->firstWhere('id', $channelId);
            if (!$ch) continue;

            $service = $ch['service'] ?? 'unknown';
            $displayName = $ch['displayName'] ?? $ch['name'] ?? "Buffer {$service}";
            $name = "{$displayName} (Buffer → {$service})";

            // Metadata stores channel info. Pinterest board, IG mode, Pin Title,
            // and Destination Link are NOT stored here — they're picked per-post
            // via account_overrides in the Create Post page.
            $metadata = [
                'channel_id' => $channelId,
                'channel_service' => $service,
                'channel_name' => $ch['name'] ?? $displayName,
                'channel_display_name' => $displayName,
                'organization_id' => $orgId,
                'buffer_account_email' => $account['email'] ?? null,
            ];

            SocialAccount::updateOrCreate(
                [
                    'provider' => 'buffer',
                    'provider_id' => $channelId,
                ],
                [
                    'user_id' => $userId,
                    'name' => $name,
                    'username' => $ch['name'] ?? $displayName,
                    'avatar' => $ch['avatar'] ?? null,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                    'is_active' => true,
                    'metadata' => $metadata,
                ]
            );
            $connectedCount++;
            $connectedNames[] = $name;
        }

        session()->forget([
            'buffer_access_token', 'buffer_refresh_token', 'buffer_expires_in',
            'buffer_account', 'buffer_organizations', 'buffer_code_verifier',
            'buffer_state', 'oauth_user_id',
        ]);
        // Clean up Pinterest board sessions (keys like buffer_pinterest_boards_*)
        foreach (array_keys(session()->all()) as $key) {
            if (str_starts_with($key, 'buffer_pinterest_boards_')) {
                session()->forget($key);
            }
        }

        $msg = $connectedCount === 1
            ? "Buffer channel '{$connectedNames[0]}' connected successfully."
            : "{$connectedCount} Buffer channels connected: " . implode(', ', $connectedNames);

        return redirect()->route('social-accounts.index')
            ->with('message', $msg);
    }

    /**
     * Fetch Pinterest boards for a Buffer channel via GraphQL.
     * Called by frontend when user selects a Pinterest channel during Buffer connect.
     *
     * POST /ai/buffer-pinterest-boards
     * Body: { channel_id: string }
     * Returns: { boards: [{ serviceId, name }] }
     */
    public function fetchBufferPinterestBoards(Request $request)
    {
        $validated = $request->validate([
            'channel_id' => 'required|string',
        ]);

        $channelId = $validated['channel_id'];
        $accessToken = session('buffer_access_token');

        if (!$accessToken) {
            return response()->json(['error' => 'Buffer session expired'], 401);
        }

        $query = 'query GetChannelBoards($channelId: ChannelId!) {
  channel(input: { id: $channelId }) {
    id name service
    metadata {
      ... on PinterestMetadata { boards { serviceId name } }
    }
  }
}';

        try {
            $response = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), [
                    'query' => $query,
                    'variables' => ['channelId' => $channelId],
                ]);

            $body = $response->json();
            if (isset($body['errors']) && !empty($body['errors'])) {
                return response()->json(['error' => 'Buffer API error: ' . ($body['errors'][0]['message'] ?? 'Unknown')], 400);
            }

            $channel = $body['data']['channel'] ?? null;
            if (!$channel) {
                return response()->json(['error' => 'Channel not found'], 404);
            }

            $boards = [];
            $metadata = $channel['metadata'] ?? null;
            if ($metadata && is_array($metadata)) {
                $boards = $metadata['boards'] ?? [];
            }

            // Store boards in session so selectBufferChannel can look up
            // board names when building display names with suffix.
            session(['buffer_pinterest_boards_' . $channelId => $boards]);

            return response()->json(['boards' => $boards, 'channel_name' => $channel['name'] ?? '']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch boards: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Fetch Pinterest boards for an already-connected Buffer account.
     * Called by frontend when user creates a post and selects a Buffer → Pinterest account.
     * Uses the stored access_token (not session token) since the user is already connected.
     *
     * POST /ai/buffer-account-boards
     * Body: { account_id: int }
     * Returns: { boards: [{ serviceId, name }] }
     */
    public function fetchBufferAccountBoards(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:social_accounts,id',
        ]);

        $account = SocialAccount::findOrFail($validated['account_id']);

        // Manual ownership check (no policy registered for SocialAccount)
        if ($account->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Ensure this is a Buffer Pinterest account
        if ($account->provider !== 'buffer') {
            return response()->json(['error' => 'Not a Buffer account'], 400);
        }

        $metadata = is_string($account->metadata) ? json_decode($account->metadata, true) : ($account->metadata ?? []);
        if (($metadata['channel_service'] ?? '') !== 'pinterest') {
            return response()->json(['error' => 'Not a Pinterest channel'], 400);
        }

        $channelId = $metadata['channel_id'] ?? $account->provider_id;
        $accessToken = $account->access_token;

        $query = 'query GetChannelBoards($channelId: ChannelId!) {
  channel(input: { id: $channelId }) {
    id name service
    metadata {
      ... on PinterestMetadata { boards { serviceId name } }
    }
  }
}';

        try {
            $response = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), [
                    'query' => $query,
                    'variables' => ['channelId' => $channelId],
                ]);

            $body = $response->json();
            if (isset($body['errors']) && !empty($body['errors'])) {
                return response()->json(['error' => 'Buffer API error: ' . ($body['errors'][0]['message'] ?? 'Unknown')], 400);
            }

            $channel = $body['data']['channel'] ?? null;
            $boards = [];
            $meta = $channel['metadata'] ?? null;
            if ($meta && is_array($meta)) {
                $boards = $meta['boards'] ?? [];
            }

            return response()->json(['boards' => $boards]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch boards: ' . $e->getMessage()], 500);
        }
    }
}
