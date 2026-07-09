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
    Route::post('/integrations/social/select-youtube-channel', [SocialAccountController::class, 'selectYoutubeChannel'])->name('social-accounts.select-youtube-channel');
    Route::post('/integrations/social/select-linkedin-page', [SocialAccountController::class, 'selectLinkedinPage'])->name('social-accounts.select-linkedin-page');
    Route::delete('/social-accounts/{id}', [SocialAccountController::class, 'destroy'])->name('social-accounts.destroy');

    Route::resource('posts', PostController::class);

    Route::get('/media', [\App\Http\Controllers\MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [\App\Http\Controllers\MediaController::class, 'store'])->name('media.store');
    Route::delete('/media/{id}', [\App\Http\Controllers\MediaController::class, 'destroy'])->name('media.destroy');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
});
