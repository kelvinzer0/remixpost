<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $stats = [
            'total_posts' => $user->posts()->count(),
            'scheduled_posts' => $user->posts()->where('status', 'scheduled')->count(),
            'published_posts' => $user->posts()->where('status', 'published')->count(),
            'connected_accounts' => $user->socialAccounts()->where('is_active', true)->count(),
        ];

        $upcomingPosts = $user->posts()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')
            ->take(5)
            ->get();

        $accounts = $user->socialAccounts()
            ->where('is_active', true)
            ->get(['id', 'provider', 'name', 'username', 'avatar']);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'upcomingPosts' => $upcomingPosts,
            'accounts' => $accounts,
        ]);
    }
}
