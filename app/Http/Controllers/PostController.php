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
            ->get(['id', 'provider', 'name', 'username', 'avatar', 'metadata']);

        $media = $request->user()
            ->media()
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get(['id', 'url', 'original_name', 'mime_type', 'folder_path']);

        // Append dimensions + aspect ratio label for image/video items
        // so the media picker in Create Post can show aspect ratio badges
        $media->each(function ($item) {
            $localPath = storage_path('app/public/' . parse_url($item->url, PHP_URL_PATH));
            // Extract relative path from URL (after /storage/)
            if (preg_match('#/storage/(.+)$#', $item->url, $m)) {
                $localPath = storage_path('app/public/' . $m[1]);
            }
            $dims = \App\Services\MediaType::getDimensions($localPath, $item->mime_type);
            $item->dimensions = $dims;
            $item->aspect_ratio = $dims ? \App\Services\MediaType::aspectRatioLabel($dims['w'], $dims['h']) : null;
        });

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
            'watermark_settings' => ['nullable', 'array'],
            'watermark_settings.enabled' => ['boolean'],
            'watermark_settings.text' => ['string', 'max:100'],
            'watermark_settings.position' => ['string', 'in:top-left,top-center,top-right,middle-left,middle-center,middle-right,bottom-left,bottom-center,bottom-right'],
            'watermark_settings.font_size' => ['integer', 'min:8', 'max:120'],
            'watermark_settings.opacity' => ['integer', 'min:10', 'max:100'],
            'watermark_settings.applied_to' => ['array'],
            'watermark_settings.applied_to.*' => ['url'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'first_comment' => ['nullable', 'string', 'max:8000'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'linkedin_doc_title' => ['nullable', 'string', 'max:200'],
            'account_overrides' => ['nullable', 'array'],
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

        $scheduledAt = \Carbon\Carbon::parse($validated['scheduled_at'], config('app.timezone'));

        $post = $request->user()->posts()->create([
            'content' => $validated['content'],
            'media_urls' => $validated['media_urls'] ?? [],
            'watermark_settings' => $validated['watermark_settings'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'first_comment' => $validated['first_comment'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'linkedin_doc_title' => $validated['linkedin_doc_title'] ?? null,
            'account_overrides' => $validated['account_overrides'] ?? null,
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
            ->get(['id', 'provider', 'name', 'username', 'avatar', 'metadata']);

        $media = $request->user()
            ->media()
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get(['id', 'url', 'original_name', 'mime_type', 'folder_path']);

        // Append dimensions + aspect ratio label for image/video items
        $media->each(function ($item) {
            $localPath = storage_path('app/public/' . parse_url($item->url, PHP_URL_PATH));
            if (preg_match('#/storage/(.+)$#', $item->url, $m)) {
                $localPath = storage_path('app/public/' . $m[1]);
            }
            $dims = \App\Services\MediaType::getDimensions($localPath, $item->mime_type);
            $item->dimensions = $dims;
            $item->aspect_ratio = $dims ? \App\Services\MediaType::aspectRatioLabel($dims['w'], $dims['h']) : null;
        });

        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'accounts' => $accounts,
            'media' => $media,
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
            'watermark_settings' => ['nullable', 'array'],
            'watermark_settings.enabled' => ['boolean'],
            'watermark_settings.text' => ['string', 'max:100'],
            'watermark_settings.position' => ['string', 'in:top-left,top-center,top-right,middle-left,middle-center,middle-right,bottom-left,bottom-center,bottom-right'],
            'watermark_settings.font_size' => ['integer', 'min:8', 'max:120'],
            'watermark_settings.opacity' => ['integer', 'min:10', 'max:100'],
            'watermark_settings.applied_to' => ['array'],
            'watermark_settings.applied_to.*' => ['url'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'first_comment' => ['nullable', 'string', 'max:8000'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'linkedin_doc_title' => ['nullable', 'string', 'max:200'],
            'account_overrides' => ['nullable', 'array'],
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
            'watermark_settings' => $validated['watermark_settings'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'first_comment' => $validated['first_comment'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'linkedin_doc_title' => $validated['linkedin_doc_title'] ?? null,
            'account_overrides' => $validated['account_overrides'] ?? null,
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

    /**
     * Duplicate/clone a post — creates a new post with same content, media,
     * tags, first_comment, alt_text, account_overrides, and social accounts.
     * Status set to 'draft' so user can edit before scheduling.
     * Redirects to edit page of the new post.
     */
    public function duplicate(Request $request, int $id)
    {
        $post = $request->user()->posts()->with('socialAccounts')->findOrFail($id);

        $clone = $request->user()->posts()->create([
            'content' => $post->content,
            'media_urls' => $post->media_urls ?? [],
            'tags' => $post->tags ?? [],
            'first_comment' => $post->first_comment,
            'alt_text' => $post->alt_text,
            'account_overrides' => $post->account_overrides,
            'scheduled_at' => null,
            'status' => Post::STATUS_DRAFT,
        ]);

        // Copy social account associations
        $accountIds = $post->socialAccounts->pluck('id')->toArray();
        if (!empty($accountIds)) {
            $clone->socialAccounts()->sync($accountIds);
        }

        return redirect()->route('posts.edit', $clone->id)
            ->with('message', 'Post duplicated. Edit and schedule when ready.');
    }

    /**
     * Auto-save a post as draft. Called by frontend via AJAX every few seconds.
     *
     * If post_id is provided → update existing draft.
     * If no post_id → create new draft, return ID so frontend can update in-place.
     *
     * Returns JSON (not redirect) so frontend can stay on page.
     */
    public function autoSave(Request $request, $id = null)
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array', 'max:10'],
            'media_urls.*' => ['url'],
            'watermark_settings' => ['nullable', 'array'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'first_comment' => ['nullable', 'string', 'max:8000'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'account_overrides' => ['nullable', 'array'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['exists:social_accounts,id'],
            'scheduled_at' => ['nullable', 'string'],
        ]);

        // Strip tagInput if present (UI helper field)
        unset($validated['tagInput']);

        // Parse scheduled_at if provided
        $scheduledAt = null;
        if (!empty($validated['scheduled_at'])) {
            try {
                $scheduledAt = \Carbon\Carbon::parse($validated['scheduled_at'], config('app.timezone'));
            } catch (\Exception $e) {
                $scheduledAt = null;
            }
        }

        if ($id) {
            // Update existing post
            $post = $request->user()->posts()->findOrFail($id);

            // Only auto-save if post is still draft or scheduled (not published/failed)
            if (!in_array($post->status, [Post::STATUS_DRAFT, Post::STATUS_SCHEDULED])) {
                return response()->json(['error' => 'Cannot auto-save a published post'], 400);
            }

            $post->update([
                'content' => $validated['content'] ?? $post->content,
                'media_urls' => $validated['media_urls'] ?? $post->media_urls,
                'watermark_settings' => array_key_exists('watermark_settings', $validated)
                    ? $validated['watermark_settings']
                    : $post->watermark_settings,
                'tags' => $validated['tags'] ?? $post->tags,
                'first_comment' => $validated['first_comment'] ?? $post->first_comment,
                'alt_text' => $validated['alt_text'] ?? $post->alt_text,
                'linkedin_doc_title' => array_key_exists('linkedin_doc_title', $validated)
                    ? $validated['linkedin_doc_title']
                    : $post->linkedin_doc_title,
                'account_overrides' => $validated['account_overrides'] ?? $post->account_overrides,
                'scheduled_at' => $scheduledAt ?? $post->scheduled_at,
                'status' => Post::STATUS_DRAFT,
            ]);

            // Sync accounts if provided
            if (!empty($validated['account_ids'])) {
                $accountIds = $request->user()
                    ->socialAccounts()
                    ->whereIn('id', $validated['account_ids'])
                    ->pluck('id');
                $post->socialAccounts()->sync($accountIds);
            }

            return response()->json([
                'success' => true,
                'post_id' => $post->id,
                'saved_at' => now()->format('H:i:s'),
            ]);
        } else {
            // Create new draft
            $post = $request->user()->posts()->create([
                'content' => $validated['content'] ?? '',
                'media_urls' => $validated['media_urls'] ?? [],
                'watermark_settings' => $validated['watermark_settings'] ?? null,
                'tags' => $validated['tags'] ?? [],
                'first_comment' => $validated['first_comment'] ?? null,
                'alt_text' => $validated['alt_text'] ?? null,
                'linkedin_doc_title' => $validated['linkedin_doc_title'] ?? null,
                'account_overrides' => $validated['account_overrides'] ?? null,
                'scheduled_at' => $scheduledAt,
                'status' => Post::STATUS_DRAFT,
            ]);

            // Sync accounts if provided
            if (!empty($validated['account_ids'])) {
                $accountIds = $request->user()
                    ->socialAccounts()
                    ->whereIn('id', $validated['account_ids'])
                    ->pluck('id');
                $post->socialAccounts()->sync($accountIds);
            }

            return response()->json([
                'success' => true,
                'post_id' => $post->id,
                'saved_at' => now()->format('H:i:s'),
                'redirect' => route('posts.edit', $post->id),
            ]);
        }
    }
}
