<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\MediaType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Media API Controller — RESTful API for external platforms to
 * upload, list, and delete media in remixpost's Media Manager.
 *
 * All endpoints require API key auth via 'auth.apikey' middleware.
 * API keys are prefixed with 'rk_' and managed via /settings/api-keys.
 *
 * OpenAPI spec available at: GET /api/openapi.json
 */
class MediaApiController extends Controller
{
    /**
     * List media for the authenticated user.
     * GET /api/v1/media?folder=xxx&per_page=24
     */
    public function index(Request $request)
    {
        $folder = $request->input('folder', '');
        $perPage = min((int) $request->input('per_page', 24), 100);

        $query = $request->user()->media()->orderBy('created_at', 'desc');

        if ($folder !== '') {
            $query->where('folder_path', $folder);
        } else {
            $query->whereNull('folder_path');
        }

        $media = $query->paginate($perPage);

        return response()->json([
            'data' => $media->items(),
            'meta' => [
                'current_page' => $media->currentPage(),
                'last_page' => $media->lastPage(),
                'per_page' => $media->perPage(),
                'total' => $media->total(),
            ],
        ]);
    }

    /**
     * Upload a media file.
     * POST /api/v1/media
     * Content-Type: multipart/form-data
     * Fields: file (required), folder_path (optional)
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // 100MB
            'folder_path' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $originalExt = $file->getClientOriginalExtension() ?: 'bin';
        $filename = time() . '_' . uniqid() . '.' . $originalExt;
        $path = $file->storeAs('uploads', $filename, 'public');

        $folderPath = $request->input('folder_path');
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
            'folder_path' => $folderPath ?: null,
        ]);

        // Append dimensions + aspect ratio
        $localPath = storage_path('app/public/' . $path);
        $dims = MediaType::getDimensions($localPath, $media->mime_type);
        $media->dimensions = $dims;
        $media->aspect_ratio = $dims ? MediaType::aspectRatioLabel($dims['w'], $dims['h']) : null;

        return response()->json([
            'success' => true,
            'media' => $media,
        ], 201);
    }

    /**
     * Get a single media item.
     * GET /api/v1/media/{id}
     */
    public function show(Request $request, int $id)
    {
        $media = $request->user()->media()->findOrFail($id);

        $localPath = storage_path('app/public/' . $media->path);
        $dims = MediaType::getDimensions($localPath, $media->mime_type);
        $media->dimensions = $dims;
        $media->aspect_ratio = $dims ? MediaType::aspectRatioLabel($dims['w'], $dims['h']) : null;

        return response()->json(['media' => $media]);
    }

    /**
     * Delete a media item.
     * DELETE /api/v1/media/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $media = $request->user()->media()->findOrFail($id);
        Storage::disk('public')->delete($media->path);
        $media->delete();

        return response()->json(['success' => true, 'message' => 'Media deleted.'], 200);
    }
}
