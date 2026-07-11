<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MediaController extends Controller
{
    /**
     * List media with optional folder filtering + folder tree.
     */
    public function index(Request $request)
    {
        $folder = $request->input('folder', '');

        $query = $request->user()->media()->orderBy('created_at', 'desc');

        if ($folder !== '') {
            $query->where('folder_path', $folder);
        } else {
            // Root: show items with no folder_path
            $query->whereNull('folder_path');
        }

        $media = $query->paginate(24)->withQueryString();

        // Build folder tree from all media for this user
        $folderTree = $this->buildFolderTree($request->user()->id);

        return Inertia::render('Media/Index', [
            'media' => $media,
            'currentFolder' => $folder,
            'folderTree' => $folderTree,
        ]);
    }

    /**
     * Upload media with optional folder_path.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // 100MB max
            'folder_path' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $originalExt = $file->getClientOriginalExtension() ?: 'bin';
        $filename = time() . '_' . uniqid() . '.' . $originalExt;
        $path = $file->storeAs('uploads', $filename, 'public');

        $folderPath = $request->input('folder_path', null);
        // Normalize: trim slashes, no leading/trailing /
        if ($folderPath) {
            $folderPath = trim($folderPath, '/');
        }

        $media = $request->user()->media()->create([
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'folder_path' => $folderPath,
        ]);

        return response()->json([
            'success' => true,
            'media' => $media,
        ]);
    }

    /**
     * Move media to a different folder.
     */
    public function move(Request $request, int $id)
    {
        $media = $request->user()->media()->findOrFail($id);

        $validated = $request->validate([
            'folder_path' => 'nullable|string|max:500',
        ]);

        $folderPath = $validated['folder_path'];
        if ($folderPath) {
            $folderPath = trim($folderPath, '/');
        }

        $media->update(['folder_path' => $folderPath ?: null]);

        return back()->with('message', 'Media moved to ' . ($folderPath ?: 'Root') . '.');
    }

    /**
     * Create a new folder.
     *
     * Folders are virtual — stored as a row in the media_folders table
     * so they show up in the tree even when empty.
     */
    public function createFolder(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|regex:/^[^\/\\\\]+$/',
            'parent' => 'nullable|string|max:500',
        ]);

        $name = trim($validated['name']);
        $parent = $validated['parent'] ? trim($validated['parent'], '/') : '';

        $folderPath = $parent ? "{$parent}/{$name}" : $name;

        // Check if folder already exists
        $exists = DB::table('media_folders')
            ->where('user_id', $request->user()->id)
            ->where('path', $folderPath)
            ->exists();

        if ($exists) {
            return back()->with('error', "Folder '{$folderPath}' already exists.");
        }

        // Create folder record
        DB::table('media_folders')->insert([
            'user_id' => $request->user()->id,
            'name' => $name,
            'path' => $folderPath,
            'parent_path' => $parent ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('message', "Folder '{$folderPath}' created.");
    }

    /**
     * Delete a folder (moves all media in it to root + deletes folder record).
     */
    public function deleteFolder(Request $request)
    {
        $validated = $request->validate([
            'folder_path' => 'required|string|max:500',
        ]);

        $folderPath = trim($validated['folder_path'], '/');

        // Move all media in this folder to root
        Media::where('user_id', $request->user()->id)
            ->where('folder_path', $folderPath)
            ->update(['folder_path' => null]);

        // Move all media in subfolders to root too
        Media::where('user_id', $request->user()->id)
            ->where('folder_path', 'like', $folderPath . '/%')
            ->update(['folder_path' => null]);

        // Delete folder record + all subfolder records
        DB::table('media_folders')
            ->where('user_id', $request->user()->id)
            ->where(function ($q) use ($folderPath) {
                $q->where('path', $folderPath)
                  ->orWhere('path', 'like', $folderPath . '/%');
            })
            ->delete();

        return back()->with('message', "Folder '{$folderPath}' deleted. Media moved to root.");
    }

    public function destroy(Request $request, int $id)
    {
        $media = $request->user()->media()->findOrFail($id);
        Storage::disk('public')->delete($media->path);
        $media->delete();

        return back()->with('message', 'Media deleted.');
    }

    /**
     * Bulk move multiple media items to a target folder.
     *
     * Body: { ids: [1,2,3,...], folder_path: "target/folder" | "" }
     */
    public function bulkMove(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer',
            'folder_path' => 'nullable|string|max:500',
        ]);

        $folderPath = $validated['folder_path'] ?? '';
        if ($folderPath) {
            $folderPath = trim($folderPath, '/');
        }

        // Move all media owned by user with matching IDs (mass assignment safe
        // — only updates folder_path, scoped to user's media)
        $count = Media::where('user_id', $request->user()->id)
            ->whereIn('id', $validated['ids'])
            ->update(['folder_path' => $folderPath ?: null]);

        $target = $folderPath ?: 'Root';
        return back()->with('message', "{$count} media moved to {$target}.");
    }

    /**
     * Bulk delete multiple media items.
     *
     * Body: { ids: [1,2,3,...] }
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer',
        ]);

        // Fetch media owned by user with matching IDs
        $mediaItems = Media::where('user_id', $request->user()->id)
            ->whereIn('id', $validated['ids'])
            ->get(['id', 'path']);

        $deleted = 0;
        foreach ($mediaItems as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
            $deleted++;
        }

        return back()->with('message', "{$deleted} media deleted.");
    }

    /**
     * Build a hierarchical folder tree from media_folders table + media counts.
     */
    private function buildFolderTree(int $userId): array
    {
        // Get all folders for this user
        $folders = DB::table('media_folders')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['name', 'path', 'parent_path']);

        // Get media count per folder_path
        $mediaCounts = DB::table('media')
            ->where('user_id', $userId)
            ->whereNotNull('folder_path')
            ->select('folder_path', DB::raw('COUNT(*) as count'))
            ->groupBy('folder_path')
            ->pluck('count', 'folder_path')
            ->toArray();

        // Build tree from flat list using parent_path
        $folderMap = []; // path => node
        $root = [];

        // First pass: create all nodes
        foreach ($folders as $folder) {
            $node = [
                'name' => $folder->name,
                'path' => $folder->path,
                'count' => $mediaCounts[$folder->path] ?? 0,
                'children' => [],
            ];
            $folderMap[$folder->path] = $node;
        }

        // Second pass: build parent-child relationships
        foreach ($folders as $folder) {
            $node = &$folderMap[$folder->path];
            if ($folder->parent_path && isset($folderMap[$folder->parent_path])) {
                $folderMap[$folder->parent_path]['children'][] = &$node;
            } else {
                $root[] = &$node;
            }
        }

        // Sort by name
        usort($root, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($root as &$node) {
            $this->sortTree($node['children']);
        }

        return $root;
    }

    private function sortTree(array &$tree): void
    {
        usort($tree, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($tree as &$node) {
            $this->sortTree($node['children']);
        }
    }
}
