<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\SocialAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Social accounts — OAuth flow
    // URL pattern matches Postiz: /integrations/social/{provider} for easy migration
    Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('social-accounts.index');
    Route::get('/social-accounts/connect/{provider}', [SocialAccountController::class, 'redirectToProvider'])->name('social-accounts.connect');

    // Mastodon — manual OAuth flow (instance auto-registration, no Socialite).
    // CRITICAL: must be registered BEFORE the /integrations/social/{provider}
    // wildcard route below. Laravel matches routes in registration order for
    // paths with the same pattern — wildcard would catch 'mastodon' literal
    // and try to dispatch to handleProviderCallback, which rejects it because
    // 'mastodon' is not in the Socialite allowed list.
    Route::get('/integrations/social/mastodon', [SocialAccountController::class, 'handleMastodonCallback'])->name('social-accounts.mastodon-callback');
    Route::post('/integrations/social/connect-mastodon', [SocialAccountController::class, 'connectMastodon'])->name('social-accounts.connect-mastodon');

    // Buffer — OAuth 2.0 + PKCE flow (aggregator for FB/IG/X/Pinterest/LinkedIn/TikTok/etc).
    // Same critical ordering rule as Mastodon: register BEFORE the {provider} wildcard.
    // Both callback URLs are accepted (user may have registered either in Buffer app):
    //   - /integrations/social/buffer (Postiz-compatible)
    //   - /social-accounts/callback/buffer (legacy URL)
    Route::get('/integrations/social/buffer', [SocialAccountController::class, 'handleBufferCallback'])->name('social-accounts.buffer-callback');
    Route::get('/social-accounts/callback/buffer', [SocialAccountController::class, 'handleBufferCallback']);
    Route::post('/integrations/social/connect-buffer', [SocialAccountController::class, 'connectBuffer'])->name('social-accounts.connect-buffer');
    Route::post('/integrations/social/select-buffer-organization', [SocialAccountController::class, 'selectBufferOrganization'])->name('social-accounts.select-buffer-organization');
    Route::post('/integrations/social/select-buffer-channel', [SocialAccountController::class, 'selectBufferChannel'])->name('social-accounts.select-buffer-channel');

    // Callback URLs — compatible with Postiz pattern for easy migration
    Route::get('/integrations/social/{provider}', [SocialAccountController::class, 'handleProviderCallback'])->name('social-accounts.callback');
    // Legacy callback URL (backward compatible with existing remixpost setups)
    Route::get('/social-accounts/callback/{provider}', [SocialAccountController::class, 'handleProviderCallback']);

    Route::post('/integrations/social/select-facebook-page', [SocialAccountController::class, 'selectFacebookPage'])->name('social-accounts.select-facebook-page');
    Route::post('/integrations/social/select-pinterest-board', [SocialAccountController::class, 'selectPinterestBoard'])->name('social-accounts.select-pinterest-board');
    Route::post('/integrations/social/connect-instagram', [SocialAccountController::class, 'connectInstagram'])->name('social-accounts.connect-instagram');
    Route::post('/integrations/social/connect-telegram', [SocialAccountController::class, 'connectTelegram'])->name('social-accounts.connect-telegram');
    Route::post('/integrations/social/verify-telegram', [SocialAccountController::class, 'verifyTelegram'])->name('social-accounts.verify-telegram');
    Route::post('/integrations/social/connect-email', [SocialAccountController::class, 'connectEmail'])->name('social-accounts.connect-email');
    // Discord — manual webhook URL input (no OAuth needed)
    Route::post('/integrations/social/connect-discord', [SocialAccountController::class, 'connectDiscord'])->name('social-accounts.connect-discord');
    Route::post('/integrations/social/connect-whatsapp', [SocialAccountController::class, 'connectWhatsApp'])->name('social-accounts.connect-whatsapp');
    Route::post('/integrations/social/whatsapp-targets', [SocialAccountController::class, 'fetchWhatsAppTargets'])->name('social-accounts.whatsapp-targets');
    Route::post('/integrations/social/select-youtube-channel', [SocialAccountController::class, 'selectYoutubeChannel'])->name('social-accounts.select-youtube-channel');
    Route::post('/integrations/social/select-linkedin-page', [SocialAccountController::class, 'selectLinkedinPage'])->name('social-accounts.select-linkedin-page');
    Route::delete('/social-accounts/{id}', [SocialAccountController::class, 'destroy'])->name('social-accounts.destroy');

    Route::resource('posts', PostController::class);
    Route::post('/posts/{id}/duplicate', [PostController::class, 'duplicate'])->name('posts.duplicate');
    Route::post('/posts/auto-save', [PostController::class, 'autoSave'])->name('posts.auto-save');
    Route::post('/posts/{id}/auto-save', [PostController::class, 'autoSave'])->name('posts.auto-save-existing');

    Route::get('/media', [\App\Http\Controllers\MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [\App\Http\Controllers\MediaController::class, 'store'])->name('media.store');
    Route::delete('/media/{id}', [\App\Http\Controllers\MediaController::class, 'destroy'])->name('media.destroy');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    // Analytics
    Route::get('/analytics', [\App\Http\Controllers\AnalyticsController::class, 'index'])->name('analytics.index');
    Route::post('/analytics/refresh', [\App\Http\Controllers\AnalyticsController::class, 'refresh'])->name('analytics.refresh');

    // AI Caption Generator
    Route::post('/ai/caption', [\App\Http\Controllers\AICaptionController::class, 'generate'])->name('ai.caption.generate');
    Route::get('/ai/tones', [\App\Http\Controllers\AICaptionController::class, 'tones'])->name('ai.tones');

    // WhatsApp presence opt-in tracker
    Route::get('/whatsapp-presence', [\App\Http\Controllers\WhatsAppPresenceController::class, 'index'])->name('whatsapp-presence.index');
    Route::post('/whatsapp-presence', [\App\Http\Controllers\WhatsAppPresenceController::class, 'store'])->name('whatsapp-presence.store');
    Route::delete('/whatsapp-presence/{id}', [\App\Http\Controllers\WhatsAppPresenceController::class, 'destroy'])->name('whatsapp-presence.destroy');
    Route::delete('/whatsapp-presence/{id}/force', [\App\Http\Controllers\WhatsAppPresenceController::class, 'forceDelete'])->name('whatsapp-presence.force-delete');
    Route::post('/whatsapp-presence/{id}/check', [\App\Http\Controllers\WhatsAppPresenceController::class, 'checkNow'])->name('whatsapp-presence.check-now');
    Route::get('/whatsapp-presence/heatmap', [\App\Http\Controllers\WhatsAppPresenceController::class, 'heatmap'])->name('whatsapp-presence.heatmap');
    Route::post('/whatsapp-presence/available-contacts', [\App\Http\Controllers\WhatsAppPresenceController::class, 'availableContacts'])->name('whatsapp-presence.available-contacts');
    Route::get('/whatsapp-presence/recommend', [\App\Http\Controllers\WhatsAppPresenceController::class, 'recommend'])->name('whatsapp-presence.recommend');

    // Buffer Pinterest boards fetcher (proxied GraphQL call)
    Route::post('/ai/buffer-pinterest-boards', [\App\Http\Controllers\SocialAccountController::class, 'fetchBufferPinterestBoards'])->name('social-accounts.buffer-pinterest-boards');
    // Fetch Pinterest boards for an already-connected Buffer account (for post-time board picker)
    Route::post('/ai/buffer-account-boards', [\App\Http\Controllers\SocialAccountController::class, 'fetchBufferAccountBoards'])->name('social-accounts.buffer-account-boards');
});
