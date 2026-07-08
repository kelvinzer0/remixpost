<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));
        $startDate = now()->parse($month . '-01')->startOfMonth()->startOfWeek();
        $endDate = now()->parse($month . '-01')->endOfMonth()->endOfWeek();

        $posts = $request->user()
            ->posts()
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->orWhere(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('published_at', [$startDate, $endDate]);
            })
            ->with('socialAccounts')
            ->get();

        return Inertia::render('Calendar/Index', [
            'posts' => $posts,
            'currentMonth' => $month,
        ]);
    }
}
