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
    Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('social-accounts.index');
    Route::get('/social-accounts/connect/{provider}', [SocialAccountController::class, 'redirectToProvider'])->name('social-accounts.connect');
    Route::get('/social-accounts/callback/{provider}', [SocialAccountController::class, 'handleProviderCallback'])->name('social-accounts.callback');
    Route::post('/social-accounts/select-facebook-page', [SocialAccountController::class, 'selectFacebookPage'])->name('social-accounts.select-facebook-page');
    Route::post('/social-accounts/connect-instagram', [SocialAccountController::class, 'connectInstagram'])->name('social-accounts.connect-instagram');
    Route::delete('/social-accounts/{id}', [SocialAccountController::class, 'destroy'])->name('social-accounts.destroy');

    Route::resource('posts', PostController::class);

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
});
