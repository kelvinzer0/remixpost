<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\PlatformRequirements;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->posts()->with('socialAccounts');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $posts = $query->orderBy('scheduled_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Posts/Index', [
            'posts' => $posts,
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        $accounts = $request->user()
            ->socialAccounts()
            ->where('is_active', true)
            ->get(['id', 'provider', 'name', 'username', 'avatar']);

        $media = $request->user()
            ->media()
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get(['id', 'url', 'original_name', 'mime_type']);

        return Inertia::render('Posts/Create', [
            'accounts' => $accounts,
            'media' => $media,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array', 'max:10'],
            'media_urls.*' => ['url'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'first_comment' => ['nullable', 'string', 'max:8000'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['exists:social_accounts,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        // Per-platform requirement check
        $providers = $request->user()
            ->socialAccounts()
            ->whereIn('id', $validated['account_ids'])
            ->pluck('provider')
            ->unique()
            ->toArray();

        $errors = PlatformRequirements::validate(
            $providers,
            $validated['content'],
            $validated['media_urls'] ?? []
        );

        if (!empty($errors)) {
            return back()
                ->withErrors(['account_ids' => implode(' ', $errors)])
                ->withInput();
        }

        // datetime-local input sends naive local time (e.g. "2026-07-08T22:59")
        // Parse it in app timezone so it's stored correctly
        $scheduledAt = \Carbon\Carbon::parse($validated['scheduled_at'], config('app.timezone'));

        $post = $request->user()->posts()->create([
            'content' => $validated['content'],
            'media_urls' => $validated['media_urls'] ?? [],
            'tags' => $validated['tags'] ?? [],
            'first_comment' => $validated['first_comment'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'scheduled_at' => $scheduledAt,
            'status' => Post::STATUS_SCHEDULED,
        ]);

        // Verify all accounts belong to user
        $accountIds = $request->user()
            ->socialAccounts()
            ->whereIn('id', $validated['account_ids'])
            ->pluck('id');

        $post->socialAccounts()->sync($accountIds);

        return redirect()->route('posts.index')
            ->with('message', 'Post scheduled successfully.');
    }

    public function show(Request $request, int $id)
    {
        $post = $request->user()
            ->posts()
            ->with('socialAccounts')
            ->findOrFail($id);

        return Inertia::render('Posts/Show', [
            'post' => $post,
        ]);
    }

    public function edit(Request $request, int $id)
    {
        $post = $request->user()
            ->posts()
            ->with('socialAccounts')
            ->findOrFail($id);

        if (!in_array($post->status, [Post::STATUS_DRAFT, Post::STATUS_SCHEDULED, Post::STATUS_FAILED])) {
            return redirect()->route('posts.show', $post)
                ->with('error', 'Cannot edit a post that has been published.');
        }

        $accounts = $request->user()
            ->socialAccounts()
            ->where('is_active', true)
            ->get(['id', 'provider', 'name', 'username', 'avatar']);

        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'accounts' => $accounts,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $post = $request->user()->posts()->findOrFail($id);

        if (!in_array($post->status, [Post::STATUS_DRAFT, Post::STATUS_SCHEDULED, Post::STATUS_FAILED])) {
            return back()->with('error', 'Cannot edit a post that has been published.');
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array', 'max:10'],
            'media_urls.*' => ['url'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'first_comment' => ['nullable', 'string', 'max:8000'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['exists:social_accounts,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        // Per-platform requirement check
        $providers = $request->user()
            ->socialAccounts()
            ->whereIn('id', $validated['account_ids'])
            ->pluck('provider')
            ->unique()
            ->toArray();

        $errors = PlatformRequirements::validate(
            $providers,
            $validated['content'],
            $validated['media_urls'] ?? []
        );

        if (!empty($errors)) {
            return back()
                ->withErrors(['account_ids' => implode(' ', $errors)])
                ->withInput();
        }

        $post->update([
            'content' => $validated['content'],
            'media_urls' => $validated['media_urls'] ?? [],
            'tags' => $validated['tags'] ?? [],
            'first_comment' => $validated['first_comment'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'scheduled_at' => \Carbon\Carbon::parse($validated['scheduled_at'], config('app.timezone')),
            'status' => Post::STATUS_SCHEDULED,
            'failure_reason' => null,
        ]);

        $accountIds = $request->user()
            ->socialAccounts()
            ->whereIn('id', $validated['account_ids'])
            ->pluck('id');

        $post->socialAccounts()->sync($accountIds);

        return redirect()->route('posts.index')
            ->with('message', 'Post updated successfully.');
    }

    public function destroy(Request $request, int $id)
    {
        $post = $request->user()->posts()->findOrFail($id);
        $post->delete();

        return redirect()->route('posts.index')
            ->with('message', 'Post deleted.');
    }
}
