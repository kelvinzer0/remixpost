<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Media Manager API — for external platforms to upload/list/delete media
|--------------------------------------------------------------------------
| Authentication: Bearer token (API key prefixed with 'rk_')
| Generate keys at: /settings/api-keys
| OpenAPI spec: GET /api/openapi.json
*/

Route::prefix('v1')->middleware('auth.apikey')->group(function () {
    Route::get('/media', [\App\Http\Controllers\Api\MediaApiController::class, 'index']);
    Route::post('/media', [\App\Http\Controllers\Api\MediaApiController::class, 'store']);
    Route::get('/media/{id}', [\App\Http\Controllers\Api\MediaApiController::class, 'show'])
        ->whereNumber('id');
    Route::delete('/media/{id}', [\App\Http\Controllers\Api\MediaApiController::class, 'destroy'])
        ->whereNumber('id');
});

// OpenAPI spec endpoint (no auth — public documentation)
Route::get('/openapi.json', function () {
    return response()->json([
        'openapi' => '3.0.0',
        'info' => [
            'title' => 'remixpost Media Manager API',
            'version' => '1.0.0',
            'description' => 'REST API for uploading and managing media in remixpost. ' .
                'External platforms (n8n, Zapier, custom scripts) can use this API to ' .
                'send media files to remixpost for use in scheduled posts.',
        ],
        'servers' => [
            ['url' => config('app.url'), 'description' => 'Production'],
        ],
        'components' => [
            'securitySchemes' => [
                'BearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => "API key prefixed with 'rk_'. Generate at /settings/api-keys",
                ],
            ],
            'schemas' => [
                'Media' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'readOnly' => true],
                        'original_name' => ['type' => 'string'],
                        'filename' => ['type' => 'string', 'readOnly' => true],
                        'mime_type' => ['type' => 'string'],
                        'size' => ['type' => 'integer', 'readOnly' => true],
                        'url' => ['type' => 'string', 'readOnly' => true, 'description' => 'Public URL of the media file'],
                        'folder_path' => ['type' => 'string', 'nullable' => true],
                        'dimensions' => ['type' => 'object', 'properties' => ['w' => ['type' => 'integer'], 'h' => ['type' => 'integer']], 'readOnly' => true, 'nullable' => true],
                        'aspect_ratio' => ['type' => 'string', 'readOnly' => true, 'nullable' => true, 'example' => '16:9'],
                        'created_at' => ['type' => 'string', 'format' => 'date-time', 'readOnly' => true],
                    ],
                ],
                'ErrorResponse' => [
                    'type' => 'object',
                    'properties' => [
                        'error' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'paths' => [
            '/api/v1/media' => [
                'get' => [
                    'summary' => 'List media',
                    'description' => 'List all media files for the authenticated user.',
                    'security' => [['BearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'folder', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by folder path (empty = Root)'],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 24], 'description' => 'Items per page (max 100)'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'List of media items with pagination'],
                        '401' => ['description' => 'Invalid or missing API key'],
                    ],
                ],
                'post' => [
                    'summary' => 'Upload media',
                    'description' => 'Upload a media file (image, video, PDF). Max 100MB.',
                    'security' => [['BearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'Media file to upload'],
                                        'folder_path' => ['type' => 'string', 'description' => 'Optional folder path (e.g. "promotions/july")'],
                                    ],
                                    'required' => ['file'],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Media uploaded successfully'],
                        '401' => ['description' => 'Invalid or missing API key'],
                        '422' => ['description' => 'Validation error'],
                    ],
                ],
            ],
            '/api/v1/media/{id}' => [
                'get' => [
                    'summary' => 'Get single media',
                    'security' => [['BearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Media details'],
                        '404' => ['description' => 'Media not found'],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete media',
                    'security' => [['BearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Media deleted'],
                        '404' => ['description' => 'Media not found'],
                    ],
                ],
            ],
        ],
    ]);
});
