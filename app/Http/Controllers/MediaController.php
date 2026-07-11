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
     * Create a new folder (virtual — just a name, no physical directory).
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

        // Check if folder already exists (any media in it)
        $exists = Media::where('user_id', $request->user()->id)
            ->where('folder_path', $folderPath)
            ->exists();

        // Also check subfolders
        $hasSubfolder = Media::where('user_id', $request->user()->id)
            ->where('folder_path', 'like', $folderPath . '/%')
            ->exists();

        if ($exists || $hasSubfolder) {
            return back()->with('message', "Folder '{$folderPath}' already exists.");
        }

        // Folder is virtual — no physical creation needed.
        // It will appear in the tree once media is uploaded to it.
        // But we can create a placeholder by returning the path.
        return back()->with('message', "Folder '{$folderPath}' created. Upload media to it.");
    }

    /**
     * Delete a folder (moves all media in it to parent or root).
     */
    public function deleteFolder(Request $request)
    {
        $validated = $request->validate([
            'folder_path' => 'required|string|max:500',
        ]);

        $folderPath = trim($validated['folder_path'], '/');

        // Move all media in this folder to root (null folder_path)
        Media::where('user_id', $request->user()->id)
            ->where('folder_path', $folderPath)
            ->update(['folder_path' => null]);

        // Move all media in subfolders to root too
        Media::where('user_id', $request->user()->id)
            ->where('folder_path', 'like', $folderPath . '/%')
            ->update(['folder_path' => null]);

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
     * Build a hierarchical folder tree from all media folder_paths.
     * Returns array of { name, path, count, children: [...] }
     */
    private function buildFolderTree(int $userId): array
    {
        // Get all unique folder_paths + count of media in each
        $folders = DB::table('media')
            ->select('folder_path', DB::raw('COUNT(*) as count'))
            ->where('user_id', $userId)
            ->whereNotNull('folder_path')
            ->groupBy('folder_path')
            ->get();

        // Build tree structure
        $tree = [];
        $folderMap = []; // path => node reference

        foreach ($folders as $folder) {
            $path = $folder->folder_path;
            $parts = explode('/', $path);
            $currentPath = '';
            $parent = &$tree;

            foreach ($parts as $i => $part) {
                $currentPath = $currentPath ? "{$currentPath}/{$part}" : $part;
                $isLeaf = ($i === count($parts) - 1);

                if (!isset($folderMap[$currentPath])) {
                    $node = [
                        'name' => $part,
                        'path' => $currentPath,
                        'count' => 0,
                        'children' => [],
                    ];
                    $folderMap[$currentPath] = &$node;
                    $parent[] = &$node;
                }

                // If this is the actual folder_path (leaf), add count
                if ($isLeaf) {
                    $folderMap[$currentPath]['count'] += $folder->count;
                }

                $parent = &$folderMap[$currentPath]['children'];
            }
        }

        // Sort tree by name
        usort($tree, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($tree as &$node) {
            $this->sortTree($node['children']);
        }

        return $tree;
    }

    private function sortTree(array &$tree): void
    {
        usort($tree, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($tree as &$node) {
            $this->sortTree($node['children']);
        }
    }
}
